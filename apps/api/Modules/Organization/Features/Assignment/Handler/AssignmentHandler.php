<?php

namespace Modules\Organization\Features\Assignment\Handler;

use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use stdClass;
use UnexpectedValueException;

final class AssignmentHandler
{
    private const FAR_FUTURE = '9999-12-31 23:59:59.999';

    public function __construct(private readonly OrganizationOutbox $outbox) {}

    /**
     * @param  array{person_id: string, position_id: string, start_at: string, end_at?: string|null, is_primary?: bool}  $input
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  Closure(array<string, mixed>, string): array<string, mixed>  $eventFactory
     * @return array{request_hash_matches: bool, assignment: array<string, mixed>}
     */
    public function create(string $assignmentId, array $input, array $idempotency, Closure $eventFactory): array
    {
        $start = $this->timestamp($input['start_at']);
        $end = isset($input['end_at']) ? $this->timestamp($input['end_at']) : null;
        if ($end !== null && ($end->lessThanOrEqualTo($start) || $end->lessThanOrEqualTo(CarbonImmutable::now('UTC')))) {
            throw new InvalidArgumentException('assignment_window_invalid');
        }

        return DB::transaction(function () use ($assignmentId, $input, $idempotency, $eventFactory, $start, $end): array {
            $existing = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existing instanceof stdClass) {
                return $this->replay($existing, $idempotency['request_hash']);
            }
            $concurrent = $this->claimIdempotency($assignmentId, $idempotency);
            if ($concurrent !== null) {
                return $concurrent;
            }

            $clusterId = $this->assertReferences($input['person_id'], $input['position_id']);
            $isPrimary = $input['is_primary'] ?? true;
            if ($this->periodQuery(DB::table('assignments')->where('position_id', $input['position_id']), $start, $end)->exists()) {
                throw new DomainException('position_assignment_overlap');
            }
            if ($isPrimary && $this->periodQuery(DB::table('assignments')
                ->where('person_id', $input['person_id'])
                ->where('is_primary', true), $start, $end)->exists()) {
                throw new DomainException('primary_assignment_overlap');
            }

            DB::table('assignments')->insert([
                'id' => $assignmentId,
                'person_id' => $input['person_id'],
                'position_id' => $input['position_id'],
                'start_at' => $this->databaseTimestamp($start),
                'end_at' => $end === null ? null : $this->databaseTimestamp($end),
                'is_primary' => $isPrimary,
                'end_reason' => null,
                'ended_by_user_id' => null,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $assignment = $this->findRow($assignmentId);
            $this->storeReplay($idempotency, $assignment);
            $this->outbox->insert($eventFactory($assignment, $clusterId), $assignmentId);

            return ['request_hash_matches' => true, 'assignment' => $assignment];
        });
    }

    /**
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  Closure(array<string, mixed>, string): array<string, mixed>  $eventFactory
     * @return array{request_hash_matches: bool, assignment: array<string, mixed>}
     */
    public function end(
        string $assignmentId,
        int $expectedVersion,
        string $endAt,
        string $reason,
        string $principalId,
        array $idempotency,
        Closure $eventFactory,
    ): array {
        $effectiveEnd = $this->timestamp($endAt);

        return DB::transaction(function () use ($assignmentId, $expectedVersion, $effectiveEnd, $reason, $principalId, $idempotency, $eventFactory): array {
            $existing = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existing instanceof stdClass) {
                return $this->replay($existing, $idempotency['request_hash']);
            }
            $concurrent = $this->claimIdempotency($assignmentId, $idempotency);
            if ($concurrent !== null) {
                return $concurrent;
            }
            $seed = DB::table('assignments')->where('id', $assignmentId)->first();
            if (! $seed instanceof stdClass) {
                throw new DomainException('assignment_not_found');
            }
            $this->lockRoots((string) $seed->person_id, (string) $seed->position_id);
            $row = DB::table('assignments')->where('id', $assignmentId)->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                throw new DomainException('assignment_not_found');
            }
            if ((int) $row->lock_version !== $expectedVersion) {
                throw new DomainException('precondition_failed');
            }
            $now = CarbonImmutable::now('UTC');
            $status = $this->status($row, $now);
            if ($status === 'ended') {
                throw new DomainException('assignment_already_ended');
            }
            if ($status !== 'active') {
                throw new DomainException('assignment_not_active');
            }
            if ($effectiveEnd->lessThan($this->timestamp((string) $row->start_at)) || $effectiveEnd->greaterThan($now)) {
                throw new InvalidArgumentException('assignment_end_invalid');
            }

            $version = (int) $row->lock_version + 1;
            $updated = DB::table('assignments')
                ->where('id', $assignmentId)
                ->where('lock_version', $expectedVersion)
                ->update([
                    'end_at' => $this->databaseTimestamp($effectiveEnd),
                    'end_reason' => $reason,
                    'ended_by_user_id' => $principalId,
                    'lock_version' => $version,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new DomainException('precondition_failed');
            }
            $assignment = $this->findRow($assignmentId);
            $this->storeReplay($idempotency, $assignment);
            $clusterId = $this->clusterIdForPosition((string) $row->position_id);
            $this->outbox->insert($eventFactory($assignment, $clusterId), $assignmentId);

            return ['request_hash_matches' => true, 'assignment' => $assignment];
        });
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function list(array $principal, ?string $cursor, int $limit, ?string $personId): array
    {
        $afterId = $cursor === null ? null : $this->decodeCursor($cursor, $principal, $limit, $personId);
        $query = DB::table('assignments')->orderBy('id');
        if ($personId !== null) {
            $query->where('person_id', $personId);
        }
        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }
        $rows = $query->limit($limit + 1)->get()->all();
        $hasNextPage = count($rows) > $limit;
        if ($hasNextPage) {
            array_pop($rows);
        }
        $items = array_map(fn (stdClass $row): array => $this->serialize($row), $rows);

        return [
            'items' => $items,
            'next_cursor' => $hasNextPage
                ? $this->encodeCursor($items[array_key_last($items)]['id'], $principal, $limit, $personId)
                : null,
        ];
    }

    private function assertReferences(string $personId, string $positionId): string
    {
        $person = DB::table('people')->where('id', $personId)->lockForUpdate()->first();
        if (! $person instanceof stdClass) {
            throw new DomainException('person_not_found');
        }
        if ($person->status !== 'active') {
            throw new DomainException('person_inactive');
        }
        $position = DB::table('positions')->where('id', $positionId)->lockForUpdate()->first();
        if (! $position instanceof stdClass) {
            throw new DomainException('position_not_found');
        }
        if (! (bool) $position->is_active) {
            throw new DomainException('position_inactive');
        }

        return $this->clusterIdForPosition($positionId);
    }

    private function clusterIdForPosition(string $positionId): string
    {
        $clusterId = DB::table('positions')
            ->join('organization_units', 'organization_units.id', '=', 'positions.organization_unit_id')
            ->where('positions.id', $positionId)
            ->where('organization_units.status', 'active')
            ->value('organization_units.cluster_id');
        if (! is_string($clusterId) || $clusterId === '') {
            throw new DomainException('position_not_found');
        }

        return $clusterId;
    }

    private function lockRoots(string $personId, string $positionId): void
    {
        if (! DB::table('people')->where('id', $personId)->lockForUpdate()->exists()
            || ! DB::table('positions')->where('id', $positionId)->lockForUpdate()->exists()) {
            throw new DomainException('assignment_reference_unavailable');
        }
    }

    private function periodQuery(mixed $query, CarbonImmutable $start, ?CarbonImmutable $end): mixed
    {
        return $query
            ->where('start_at', '<', $end === null ? self::FAR_FUTURE : $this->databaseTimestamp($end))
            ->where(function (mixed $period) use ($start): void {
                $period->whereNull('end_at')->orWhere('end_at', '>', $this->databaseTimestamp($start));
            });
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    /** @return array{request_hash_matches: bool, assignment: array<string, mixed>}|null */
    private function claimIdempotency(string $assignmentId, array $idempotency): ?array
    {
        $claimed = DB::table('organization_idempotency_keys')->insertOrIgnore([
            'principal_id' => $idempotency['principal_id'],
            'operation' => $idempotency['operation'],
            'idempotency_key_hash' => $idempotency['key_hash'],
            'request_hash' => $idempotency['request_hash'],
            'resource_type' => 'assignment',
            'resource_id' => $assignmentId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($claimed) {
            return null;
        }
        $concurrent = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
        if (! $concurrent instanceof stdClass) {
            throw new UnexpectedValueException('The assignment idempotency claim could not be resolved.');
        }

        return $this->replay($concurrent, $idempotency['request_hash']);
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency */
    private function idempotencyQuery(array $idempotency): mixed
    {
        return DB::table('organization_idempotency_keys')
            ->where('principal_id', $idempotency['principal_id'])
            ->where('operation', $idempotency['operation'])
            ->where('idempotency_key_hash', $idempotency['key_hash']);
    }

    /** @param array{principal_id: string, operation: string, key_hash: string, request_hash: string} $idempotency @param array<string, mixed> $assignment */
    private function storeReplay(array $idempotency, array $assignment): void
    {
        $this->idempotencyQuery($idempotency)->update([
            'response_payload' => json_encode($assignment, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    /** @return array{request_hash_matches: bool, assignment: array<string, mixed>} */
    private function replay(stdClass $key, string $requestHash): array
    {
        if (! is_string($key->response_payload)) {
            throw new UnexpectedValueException('Stored assignment idempotency state is incomplete.');
        }
        try {
            $assignment = json_decode($key->response_payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('Stored assignment idempotency response is invalid.');
        }
        if (! is_array($assignment)) {
            throw new UnexpectedValueException('Stored assignment idempotency response is invalid.');
        }

        return [
            'request_hash_matches' => is_string($key->request_hash) && hash_equals($key->request_hash, $requestHash),
            'assignment' => $assignment,
        ];
    }

    /** @return array<string, mixed> */
    private function findRow(string $assignmentId): array
    {
        $row = DB::table('assignments')->where('id', $assignmentId)->first();
        if (! $row instanceof stdClass) {
            throw new UnexpectedValueException('The assignment write could not be read back.');
        }

        return $this->serialize($row);
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'person_id' => $row->person_id,
            'position_id' => $row->position_id,
            'start_at' => $this->timestamp((string) $row->start_at)->format('Y-m-d\TH:i:s.v\Z'),
            'end_at' => $row->end_at === null ? null : $this->timestamp((string) $row->end_at)->format('Y-m-d\TH:i:s.v\Z'),
            'is_primary' => (bool) $row->is_primary,
            'status' => $this->status($row, CarbonImmutable::now('UTC')),
            'end_reason' => $row->end_reason,
            'lock_version' => (int) $row->lock_version,
        ];
    }

    private function status(stdClass $row, CarbonImmutable $at): string
    {
        if ($this->timestamp((string) $row->start_at)->greaterThan($at)) {
            return 'pending';
        }
        if ($row->end_at !== null && $this->timestamp((string) $row->end_at)->lessThanOrEqualTo($at)) {
            return 'ended';
        }

        return 'active';
    }

    private function timestamp(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            throw new InvalidArgumentException('assignment_timestamp_invalid');
        }
    }

    private function databaseTimestamp(CarbonImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.v');
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function encodeCursor(string $assignmentId, array $principal, int $limit, ?string $personId): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'resource' => 'assignment',
            'after_id' => $assignmentId,
            'limit' => $limit,
            'person_id' => $personId,
            'principal_id' => $principal['user_id'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function decodeCursor(string $cursor, array $principal, int $limit, ?string $personId): string
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('The assignment cursor is invalid.');
        }
        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ($payload['resource'] ?? null) !== 'assignment'
            || ($payload['limit'] ?? null) !== $limit
            || ($payload['person_id'] ?? null) !== $personId
            || ($payload['principal_id'] ?? null) !== $principal['user_id']
            || ! is_string($payload['after_id'] ?? null)) {
            throw new InvalidArgumentException('The assignment cursor is invalid.');
        }

        return $payload['after_id'];
    }
}
