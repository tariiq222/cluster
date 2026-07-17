<?php

namespace Modules\Organization\Features\Position\Handler;

use Closure;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Modules\Organization\Domain\Position;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use stdClass;
use UnexpectedValueException;

final class PositionHandler
{
    public function __construct(private readonly OrganizationOutbox $outbox) {}

    /**
     * @param  array{organization_unit_id: string, code: string, title: string, manager_position_id?: string|null}  $input
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  Closure(array<string, mixed>, string): array<string, mixed>  $eventFactory
     * @return array{created: bool, request_hash_matches: bool, position: array<string, mixed>}
     */
    public function create(string $positionId, array $input, array $idempotency, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($positionId, $input, $idempotency, $eventFactory): array {
            $existingKey = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
            if ($existingKey instanceof stdClass) {
                return $this->replayResult($existingKey, $idempotency['request_hash']);
            }
            $unit = $this->assertUnit($input['organization_unit_id']);
            $this->assertManager($input['manager_position_id'] ?? null, $positionId);
            if (DB::table('positions')->where('organization_unit_id', $input['organization_unit_id'])->where('code', $input['code'])->exists()) {
                throw new DomainException('position_already_exists');
            }

            $claimed = DB::table('organization_idempotency_keys')->insertOrIgnore([
                'principal_id' => $idempotency['principal_id'],
                'operation' => $idempotency['operation'],
                'idempotency_key_hash' => $idempotency['key_hash'],
                'request_hash' => $idempotency['request_hash'],
                'resource_type' => 'position',
                'resource_id' => $positionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (! $claimed) {
                $concurrent = $this->idempotencyQuery($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('The position idempotency claim could not be resolved.');
                }

                return $this->replayResult($concurrent, $idempotency['request_hash']);
            }

            $position = Position::create(
                $positionId,
                $input['organization_unit_id'],
                $input['code'],
                $input['title'],
                $input['manager_position_id'] ?? null,
            );
            $data = $position->toArray();
            DB::table('positions')->insert([
                'id' => $data['id'],
                'organization_unit_id' => $data['organization_unit_id'],
                'code' => $data['code'],
                'title_ar' => $data['title_ar'],
                'manager_position_id' => $data['manager_position_id'],
                'is_active' => $data['is_active'],
                'lock_version' => $data['lock_version'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->idempotencyQuery($idempotency)->update([
                'response_payload' => json_encode($data, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $this->outbox->insert($eventFactory($data, (string) $unit->cluster_id), $positionId);

            return ['created' => true, 'request_hash_matches' => true, 'position' => $data];
        });
    }

    /** @return array<string, mixed>|null */
    public function find(string $positionId): ?array
    {
        $row = DB::table('positions')->where('id', $positionId)->first();

        return $row instanceof stdClass ? $this->serialize($row) : null;
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function list(array $principal, ?string $cursor, int $limit, ?string $unitId): array
    {
        $afterId = $cursor === null ? null : $this->decodeCursor($cursor, $principal, $limit, $unitId);
        $query = DB::table('positions')->orderBy('id');
        if ($unitId !== null) {
            $query->where('organization_unit_id', $unitId);
        }
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
                ? $this->encodeCursor($items[array_key_last($items)]['id'], $principal, $limit, $unitId)
                : null,
        ];
    }

    /**
     * @param  array{organization_unit_id?: string, title?: string, manager_position_id?: string|null}  $changes
     * @param  Closure(array<string, mixed>, string): array<string, mixed>  $eventFactory
     * @return array<string, mixed>
     */
    public function update(string $positionId, int $expectedVersion, array $changes, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($positionId, $expectedVersion, $changes, $eventFactory): array {
            $row = DB::table('positions')->where('id', $positionId)->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                throw new DomainException('position_not_found');
            }
            if ((int) $row->lock_version !== $expectedVersion) {
                throw new DomainException('precondition_failed');
            }

            $unitId = $changes['organization_unit_id'] ?? $row->organization_unit_id;
            $title = $changes['title'] ?? $row->title_ar;
            $managerId = array_key_exists('manager_position_id', $changes) ? $changes['manager_position_id'] : $row->manager_position_id;
            if (! is_string($unitId) || ! is_string($title) || ($managerId !== null && ! is_string($managerId))) {
                throw new InvalidArgumentException('Position change is invalid.');
            }
            $unit = $this->assertUnit($unitId);
            $this->assertManager($managerId, $positionId);
            if ($unitId === $row->organization_unit_id && $title === $row->title_ar && $managerId === $row->manager_position_id) {
                throw new InvalidArgumentException('Position patch does not change the resource.');
            }
            if (DB::table('positions')
                ->where('id', '!=', $positionId)
                ->where('organization_unit_id', $unitId)
                ->where('code', $row->code)
                ->exists()) {
                throw new DomainException('position_already_exists');
            }

            $version = (int) $row->lock_version + 1;
            $updated = DB::table('positions')
                ->where('id', $positionId)
                ->where('lock_version', $expectedVersion)
                ->update([
                    'organization_unit_id' => $unitId,
                    'title_ar' => $title,
                    'manager_position_id' => $managerId,
                    'lock_version' => $version,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new DomainException('precondition_failed');
            }

            $position = [
                'id' => $row->id,
                'organization_unit_id' => $unitId,
                'code' => $row->code,
                'title_ar' => $title,
                'manager_position_id' => $managerId,
                'is_active' => (bool) $row->is_active,
                'lock_version' => $version,
            ];
            $this->outbox->insert($eventFactory($position, (string) $unit->cluster_id), $positionId);

            return $position;
        });
    }

    private function assertUnit(string $unitId): stdClass
    {
        $unit = DB::table('organization_units')->where('id', $unitId)->where('status', 'active')->lockForUpdate()->first();
        if (! $unit instanceof stdClass) {
            throw new InvalidArgumentException('Position organization unit is invalid.');
        }

        return $unit;
    }

    private function assertManager(?string $managerId, string $positionId): void
    {
        if ($managerId === null) {
            return;
        }
        if ($managerId === $positionId) {
            throw new DomainException('position_manager_cycle');
        }

        $candidate = DB::table('positions')->where('id', $managerId)->where('is_active', true)->lockForUpdate()->first();
        if (! $candidate instanceof stdClass) {
            throw new InvalidArgumentException('Manager position is invalid.');
        }
        $visited = [];
        while ($candidate->manager_position_id !== null) {
            $nextId = (string) $candidate->manager_position_id;
            if ($nextId === $positionId || isset($visited[$nextId])) {
                throw new DomainException('position_manager_cycle');
            }
            $visited[$nextId] = true;
            $candidate = DB::table('positions')->where('id', $nextId)->where('is_active', true)->lockForUpdate()->first();
            if (! $candidate instanceof stdClass) {
                throw new InvalidArgumentException('Manager position chain is invalid.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'organization_unit_id' => $row->organization_unit_id,
            'code' => $row->code,
            'title_ar' => $row->title_ar,
            'manager_position_id' => $row->manager_position_id,
            'is_active' => (bool) $row->is_active,
            'lock_version' => (int) $row->lock_version,
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

    /** @return array{created: bool, request_hash_matches: bool, position: array<string, mixed>} */
    private function replayResult(stdClass $key, string $requestHash): array
    {
        if (! is_string($key->response_payload)) {
            throw new UnexpectedValueException('Stored position idempotency state is incomplete.');
        }
        try {
            $position = json_decode($key->response_payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('Stored position idempotency response is invalid.');
        }
        if (! is_array($position)) {
            throw new UnexpectedValueException('Stored position idempotency response is invalid.');
        }

        return [
            'created' => false,
            'request_hash_matches' => is_string($key->request_hash) && hash_equals($key->request_hash, $requestHash),
            'position' => $position,
        ];
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function encodeCursor(string $positionId, array $principal, int $limit, ?string $unitId): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'resource' => 'position',
            'after_id' => $positionId,
            'limit' => $limit,
            'unit_id' => $unitId,
            'principal_id' => $principal['user_id'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function decodeCursor(string $cursor, array $principal, int $limit, ?string $unitId): string
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('The position cursor is invalid.');
        }
        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ($payload['resource'] ?? null) !== 'position'
            || ($payload['limit'] ?? null) !== $limit
            || ($payload['unit_id'] ?? null) !== $unitId
            || ! is_string($payload['principal_id'] ?? null)
            || ! hash_equals($principal['user_id'], $payload['principal_id'])
            || ! is_string($payload['after_id'] ?? null)) {
            throw new InvalidArgumentException('The position cursor is invalid.');
        }

        return $payload['after_id'];
    }
}
