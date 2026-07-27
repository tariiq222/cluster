<?php

namespace Modules\Organization\Features\JobTitle\Handler;

use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Organization\Domain\JobTitle;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use Modules\Organization\Infrastructure\Persistence\EncryptedCursor;
use Modules\Organization\Infrastructure\Persistence\OrganizationIdempotencyStore;
use stdClass;
use UnexpectedValueException;

final class JobTitleHandler
{
    public function __construct(
        private readonly OrganizationOutbox $outbox,
        private readonly OrganizationIdempotencyStore $idempotency,
        private readonly EncryptedCursor $cursor,
    ) {}

    /**
     * @param  array{code: string, title_ar: string}  $input
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  Closure(array<string, mixed>, string): array<string, mixed>  $eventFactory
     * @return array{created: bool, request_hash_matches: bool, job_title: array<string, mixed>}
     */
    public function create(string $jobTitleId, array $input, array $idempotency, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($jobTitleId, $input, $idempotency, $eventFactory): array {
            $existingKey = $this->idempotency->query($idempotency)->lockForUpdate()->first();
            if ($existingKey instanceof stdClass) {
                return $this->replayResult($existingKey, $idempotency['request_hash']);
            }
            if (DB::table('job_titles')->where('code', $input['code'])->exists()) {
                throw new DomainException('job_title_already_exists');
            }

            if (! $this->idempotency->claim($idempotency, 'job_title', $jobTitleId)) {
                $concurrent = $this->idempotency->query($idempotency)->lockForUpdate()->first();
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
            $this->idempotency->storeResponse($idempotency, $data);
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
        return $this->cursor->encrypt([
            'after_id' => $afterId,
            'p' => $principal,
            'l' => $limit,
        ]);
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function decodeCursor(string $cursor, array $principal, int $limit): string
    {
        $decoded = $this->cursor->tryDecrypt($cursor);
        if ($decoded === null
            || ($decoded['p']['user_id'] ?? null) !== $principal['user_id']
            || ($decoded['l'] ?? null) !== $limit
            || ! is_string($decoded['after_id'] ?? null)) {
            throw new InvalidArgumentException('Cursor is invalid.');
        }

        return $decoded['after_id'];
    }

    /** @return array{request_hash_matches: bool, job_title: array<string, mixed>} */
    private function replayResult(stdClass $key, string $requestHash): array
    {
        $payload = $this->idempotency->decodeResponse($key, 'job_title');

        return [
            'request_hash_matches' => $this->idempotency->hashMatches($key, $requestHash),
            'job_title' => $payload,
        ];
    }
}
