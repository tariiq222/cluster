<?php

namespace Modules\Organization\Features\Person\Handler;

use Closure;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Organization\Domain\Person;
use Modules\Organization\Features\Person\Authorization\PersonAuthorizationFacts;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use Modules\Organization\Infrastructure\Persistence\EncryptedCursor;
use Modules\Organization\Infrastructure\Persistence\OrganizationIdempotencyStore;
use stdClass;
use Throwable;
use UnexpectedValueException;

final class PersonHandler
{
    private const MAX_RAW_ROWS_PER_REQUEST = 100;

    public function __construct(
        private readonly OrganizationOutbox $outbox,
        private readonly OrganizationIdempotencyStore $idempotency,
        private readonly EncryptedCursor $cursor,
        private readonly PersonAuthorizationFacts $personAuthorization,
    ) {}

    /**
     * @param  array{employee_number: string, display_name_ar: string, display_name_en?: string|null, status: string}  $input
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  Closure(array<string, mixed>): list<array<string, mixed>>  $eventFactory
     * @return array{created: bool, request_hash_matches: bool, person: array<string, mixed>}
     */
    public function create(string $personId, array $input, array $idempotency, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($personId, $input, $idempotency, $eventFactory): array {
            $existingKey = $this->idempotency->query($idempotency)->lockForUpdate()->first();
            if ($existingKey instanceof stdClass) {
                return $this->replayResult($existingKey, $idempotency['request_hash']);
            }
            if (DB::table('people')->where('employee_number', $input['employee_number'])->exists()) {
                throw new DomainException('person_already_exists');
            }

            if (! $this->idempotency->claim($idempotency, 'person', $personId)) {
                $concurrent = $this->idempotency->query($idempotency)->lockForUpdate()->first();
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
            $this->idempotency->storeResponse($idempotency, $person);
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
        $items = [];
        $lastScannedId = $afterId;
        $query = $this->personQuery()->orderBy('id')->limit(self::MAX_RAW_ROWS_PER_REQUEST + 1);
        if ($lastScannedId !== null) {
            $query->where('id', '>', $lastScannedId);
        }
        $rawRows = $query->get();
        $hasMoreRawRows = $rawRows->count() > self::MAX_RAW_ROWS_PER_REQUEST;
        $rows = $hasMoreRawRows ? $rawRows->take(self::MAX_RAW_ROWS_PER_REQUEST) : $rawRows;

        if (! $rows->isEmpty()) {
            $allowed = $this->personAuthorization->allowsMany(
                $principal,
                'organization.person.read',
                $rows->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all(),
                'organization_person',
            );
            foreach ($rows as $row) {
                $lastScannedId = (string) $row->id;
                if (($allowed[(string) $row->id] ?? false) !== true) {
                    continue;
                }
                $items[] = $this->serialize($row);
                if (count($items) > $limit) {
                    break;
                }
            }
        }

        $hasMoreVisibleRows = count($items) > $limit;
        if ($hasMoreVisibleRows) {
            array_pop($items);
        }
        $nextAfterId = $hasMoreVisibleRows
            ? $items[array_key_last($items)]['id']
            : ($hasMoreRawRows ? $lastScannedId : null);

        return [
            'items' => $items,
            'next_cursor' => $nextAfterId !== null
                ? $this->encodeCursor($nextAfterId, $principal, $limit)
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

    /** @return array{created: bool, request_hash_matches: bool, person: array<string, mixed>} */
    private function replayResult(stdClass $key, string $requestHash): array
    {
        $person = $this->decodeReplayPerson($key);

        return [
            'created' => false,
            'request_hash_matches' => $this->idempotency->hashMatches($key, $requestHash),
            'person' => $person,
        ];
    }

    /**
     * Legacy rows (pre-2026-07) stored the replay payload double-encoded as
     * json_encode(Crypt::encryptString(json_encode($person))); current rows
     * store plain JSON like every sibling handler. Decode both formats so a
     * pre-cutover idempotency key still replays safely.
     *
     * @return array<string, mixed>
     */
    private function decodeReplayPerson(stdClass $key): array
    {
        try {
            return $this->idempotency->decodeResponse($key, 'Person');
        } catch (UnexpectedValueException $exception) {
            if (! is_string($key->response_payload)) {
                throw $exception;
            }
            try {
                $encrypted = json_decode($key->response_payload, true, 4, JSON_THROW_ON_ERROR);
                $person = json_decode(Crypt::decryptString((string) $encrypted), true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                throw $exception;
            }
            if (! is_array($person)) {
                throw $exception;
            }

            return $person;
        }
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function encodeCursor(string $personId, array $principal, int $limit): string
    {
        return $this->cursor->encrypt([
            'version' => 1,
            'resource' => 'person',
            'after_id' => $personId,
            'limit' => $limit,
            'principal_id' => $principal['user_id'],
            'facility_id' => $principal['facility_id'],
        ]);
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function decodeCursor(string $cursor, array $principal, int $limit): string
    {
        $payload = $this->cursor->tryDecrypt($cursor);
        if ($payload === null
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
