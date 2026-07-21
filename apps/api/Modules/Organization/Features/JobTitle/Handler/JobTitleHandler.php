<?php

namespace Modules\Organization\Features\JobTitle\Handler;

use Closure;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Modules\Organization\Domain\JobTitle;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use stdClass;
use UnexpectedValueException;

final class JobTitleHandler
{
    public function __construct(private readonly OrganizationOutbox $outbox) {}

    /**
     * @param  array{code: string, title_ar: string}  $input
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  Closure(array<string, mixed>, string): array<string, mixed>  $eventFactory
     * @return array{created: bool, request_hash_matches: bool, job_title: array<string, mixed>}
     */
    public function create(string $jobTitleId, array $input, array $idempotency, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($jobTitleId, $input, $idempotency, $eventFactory): array {
            $existingKey = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existingKey instanceof stdClass) {
                return $this->replayResult($existingKey, $idempotency['request_hash']);
            }
            if (DB::table('job_titles')->where('code', $input['code'])->exists()) {
                throw new DomainException('job_title_already_exists');
            }

            $claimed = DB::table('organization_idempotency_keys')->insertOrIgnore([
                'principal_id' => $idempotency['principal_id'],
                'operation' => $idempotency['operation'],
                'idempotency_key_hash' => $idempotency['key_hash'],
                'request_hash' => $idempotency['request_hash'],
                'resource_type' => 'job_title',
                'resource_id' => $jobTitleId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (! $claimed) {
                $concurrent = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('The job_title idempotency claim could not be resolved.');
                }

                return $this->replayResult($concurrent, $idempotency['request_hash']);
            }

            $jobTitle = JobTitle::create($jobTitleId, $input['code'], $input['title_ar']);
            $data = $jobTitle->toArray();
            DB::table('job_titles')->insert([
                'id' => $data['id'],
                'code' => $data['code'],
                'title_ar' => $data['title_ar'],
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->idempotencyQuery($idempotency)->update([
                'response_payload' => json_encode($data, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $this->outbox->insert($eventFactory($data, $jobTitleId), $jobTitleId);

            return ['created' => true, 'request_hash_matches' => true, 'job_title' => $data];
        });
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function list(array $principal, ?string $cursor, int $limit): array
    {
        $afterId = $cursor === null ? null : $this->decodeCursor($cursor, $principal, $limit);
        $query = DB::table('job_titles')->where('status', 'active')->orderBy('id');
        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }
        $items = $query->limit($limit + 1)->get()->map(fn (stdClass $row): array => $this->serialize($row))->all();
        $hasNextPage = count($items) > $limit;
        if ($hasNextPage) {
            array_pop($items);
        }

        return [
            'items' => $items,
            'next_cursor' => $hasNextPage
                ? $this->encodeCursor($items[array_key_last($items)]['id'], $principal, $limit)
                : null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(string $jobTitleId): ?array
    {
        $row = DB::table('job_titles')->where('id', $jobTitleId)->first();

        return $row instanceof stdClass ? $this->serialize($row) : null;
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'title_ar' => $row->title_ar,
            'status' => $row->status,
        ];
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function encodeCursor(string $afterId, array $principal, int $limit): string
    {
        $payload = [
            'after_id' => $afterId,
            'p' => $principal,
            'l' => $limit,
        ];

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function decodeCursor(string $cursor, array $principal, int $limit): string
    {
        try {
            $decoded = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('Cursor is invalid.');
        }
        if (! is_array($decoded) || ($decoded['p']['user_id'] ?? null) !== $principal['user_id']) {
            throw new InvalidArgumentException('Cursor is invalid.');
        }
        if (($decoded['l'] ?? null) !== $limit || ! is_string($decoded['after_id'] ?? null)) {
            throw new InvalidArgumentException('Cursor is invalid.');
        }

        return $decoded['after_id'];
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    private function idempotencyQuery(array $idempotency): mixed
    {
        return DB::table('organization_idempotency_keys')
            ->where('principal_id', $idempotency['principal_id'])
            ->where('operation', $idempotency['operation'])
            ->where('idempotency_key_hash', $idempotency['key_hash']);
    }

    /** @return array{request_hash_matches: bool, job_title: array<string, mixed>} */
    private function replayResult(stdClass $key, string $requestHash): array
    {
        if (! is_string($key->response_payload)) {
            throw new UnexpectedValueException('Stored job_title idempotency state is incomplete.');
        }
        try {
            $payload = json_decode($key->response_payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('Stored job_title idempotency response is invalid.');
        }
        if (! is_array($payload)) {
            throw new UnexpectedValueException('Stored job_title idempotency response is invalid.');
        }

        return [
            'request_hash_matches' => is_string($key->request_hash) && hash_equals($key->request_hash, $requestHash),
            'job_title' => $payload,
        ];
    }
}
