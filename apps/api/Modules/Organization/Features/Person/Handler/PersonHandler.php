<?php

namespace Modules\Organization\Features\Person\Handler;

use Closure;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Modules\Organization\Domain\Person;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use stdClass;
use UnexpectedValueException;

final class PersonHandler
{
    public function __construct(private readonly OrganizationOutbox $outbox) {}

    /**
     * @param  array{employee_number: string, display_name_ar: string, display_name_en?: string|null, status: string}  $input
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  Closure(array<string, mixed>): list<array<string, mixed>>  $eventFactory
     * @return array{created: bool, request_hash_matches: bool, person: array<string, mixed>}
     */
    public function create(string $personId, array $input, array $idempotency, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($personId, $input, $idempotency, $eventFactory): array {
            $existingKey = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existingKey instanceof stdClass) {
                return $this->replayResult($existingKey, $idempotency['request_hash']);
            }
            if (DB::table('people')->where('employee_number', $input['employee_number'])->exists()) {
                throw new DomainException('person_already_exists');
            }

            $claimed = DB::table('organization_idempotency_keys')->insertOrIgnore([
                'principal_id' => $idempotency['principal_id'],
                'operation' => $idempotency['operation'],
                'idempotency_key_hash' => $idempotency['key_hash'],
                'request_hash' => $idempotency['request_hash'],
                'resource_type' => 'person',
                'resource_id' => $personId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (! $claimed) {
                $concurrent = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('The Person idempotency claim could not be resolved.');
                }

                return $this->replayResult($concurrent, $idempotency['request_hash']);
            }

            $person = Person::register(
                $personId,
                $input['employee_number'],
                $input['display_name_ar'],
                $input['display_name_en'] ?? null,
                $input['status'],
            )->toArray();
            DB::table('people')->insert([
                'id' => $person['id'],
                'national_id_ciphertext' => null,
                'national_id_lookup_hash' => null,
                'employee_number' => $person['employee_number'],
                'display_name_ar' => $person['display_name_ar'],
                'display_name_en' => $person['display_name_en'],
                'primary_email_ciphertext' => null,
                'primary_phone_ciphertext' => null,
                'status' => $person['status'],
                'person_version' => $person['person_version'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->idempotencyQuery($idempotency)->update([
                'response_payload' => json_encode(Crypt::encryptString(json_encode($person, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            foreach ($eventFactory($person) as $event) {
                $this->outbox->insert($event, $personId);
            }

            return ['created' => true, 'request_hash_matches' => true, 'person' => $person];
        });
    }

    /** @return array<string, mixed>|null */
    public function find(string $personId): ?array
    {
        $row = $this->personQuery()->where('id', $personId)->first();

        return $row instanceof stdClass ? $this->serialize($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function reference(string $personId): ?array
    {
        $person = $this->find($personId);
        if ($person === null) {
            return null;
        }

        return [
            'person_id' => $person['id'],
            'person_version' => $person['person_version'],
            'status' => $person['status'],
            'display_name_ar' => $person['display_name_ar'],
            'display_name_en' => $person['display_name_en'],
        ];
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function list(array $principal, ?string $cursor, int $limit): array
    {
        $afterId = $cursor === null ? null : $this->decodeCursor($cursor, $principal, $limit);
        $query = $this->personQuery()->orderBy('id');
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

    /**
     * @param  array{display_name_ar?: string, display_name_en?: string|null, status?: string}  $changes
     * @param  Closure(array<string, mixed>, string): list<array<string, mixed>>  $eventFactory
     * @return array<string, mixed>
     */
    public function update(string $personId, int $expectedVersion, array $changes, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($personId, $expectedVersion, $changes, $eventFactory): array {
            $row = DB::table('people')->where('id', $personId)->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                throw new DomainException('person_not_found');
            }
            if ((int) $row->person_version !== $expectedVersion) {
                throw new DomainException('precondition_failed');
            }

            $displayNameAr = $changes['display_name_ar'] ?? $row->display_name_ar;
            $displayNameEn = array_key_exists('display_name_en', $changes) ? $changes['display_name_en'] : $row->display_name_en;
            $status = $changes['status'] ?? $row->status;
            if (! is_string($displayNameAr)
                || ($displayNameEn !== null && ! is_string($displayNameEn))
                || ! is_string($status)
                || ! in_array($status, ['active', 'suspended', 'left'], true)) {
                throw new InvalidArgumentException('Person change is invalid.');
            }
            if ($displayNameAr === $row->display_name_ar && $displayNameEn === $row->display_name_en && $status === $row->status) {
                throw new InvalidArgumentException('Person patch does not change the resource.');
            }

            $version = (int) $row->person_version + 1;
            $updated = DB::table('people')->where('id', $personId)->where('person_version', $expectedVersion)->update([
                'display_name_ar' => $displayNameAr,
                'display_name_en' => $displayNameEn,
                'status' => $status,
                'person_version' => $version,
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new DomainException('precondition_failed');
            }
            $person = [
                'id' => $row->id,
                'employee_number' => $row->employee_number,
                'display_name_ar' => $displayNameAr,
                'display_name_en' => $displayNameEn,
                'status' => $status,
                'person_version' => $version,
            ];
            foreach ($eventFactory($person, (string) $row->status) as $event) {
                $this->outbox->insert($event, $personId);
            }

            return $person;
        });
    }

    private function personQuery(): mixed
    {
        return DB::table('people')->select([
            'id',
            'employee_number',
            'display_name_ar',
            'display_name_en',
            'status',
            'person_version',
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'employee_number' => $row->employee_number,
            'display_name_ar' => $row->display_name_ar,
            'display_name_en' => $row->display_name_en,
            'status' => $row->status,
            'person_version' => (int) $row->person_version,
        ];
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    private function idempotencyQuery(array $idempotency): mixed
    {
        return DB::table('organization_idempotency_keys')
            ->where('principal_id', $idempotency['principal_id'])
            ->where('operation', $idempotency['operation'])
            ->where('idempotency_key_hash', $idempotency['key_hash']);
    }

    /** @return array{created: bool, request_hash_matches: bool, person: array<string, mixed>} */
    private function replayResult(stdClass $key, string $requestHash): array
    {
        if (! is_string($key->response_payload)) {
            throw new UnexpectedValueException('Stored Person idempotency state is incomplete.');
        }
        try {
            $encrypted = json_decode($key->response_payload, true, 4, JSON_THROW_ON_ERROR);
            if (! is_string($encrypted)) {
                throw new UnexpectedValueException('Stored Person idempotency response is invalid.');
            }
            $person = json_decode(Crypt::decryptString($encrypted), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new UnexpectedValueException('Stored Person idempotency response is invalid.');
        }
        if (! is_array($person)) {
            throw new UnexpectedValueException('Stored Person idempotency response is invalid.');
        }

        return [
            'created' => false,
            'request_hash_matches' => is_string($key->request_hash) && hash_equals($key->request_hash, $requestHash),
            'person' => $person,
        ];
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function encodeCursor(string $personId, array $principal, int $limit): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'resource' => 'person',
            'after_id' => $personId,
            'limit' => $limit,
            'principal_id' => $principal['user_id'],
            'facility_id' => $principal['facility_id'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function decodeCursor(string $cursor, array $principal, int $limit): string
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('The Person cursor is invalid.');
        }
        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ($payload['resource'] ?? null) !== 'person'
            || ($payload['limit'] ?? null) !== $limit
            || ! is_string($payload['principal_id'] ?? null)
            || ! hash_equals($principal['user_id'], $payload['principal_id'])
            || ! is_string($payload['facility_id'] ?? null)
            || ! hash_equals($principal['facility_id'], $payload['facility_id'])
            || ! is_string($payload['after_id'] ?? null)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $payload['after_id']) !== 1) {
            throw new InvalidArgumentException('The Person cursor is invalid.');
        }

        return $payload['after_id'];
    }
}
