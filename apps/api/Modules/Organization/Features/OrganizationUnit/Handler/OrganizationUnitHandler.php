<?php

namespace Modules\Organization\Features\OrganizationUnit\Handler;

use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Organization\Domain\OrganizationUnit;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use Modules\Organization\Infrastructure\Persistence\EncryptedCursor;
use Modules\Organization\Infrastructure\Persistence\OrganizationIdempotencyStore;
use stdClass;
use UnexpectedValueException;

final class OrganizationUnitHandler
{
    public function __construct(
        private readonly OrganizationOutbox $outbox,
        private readonly OrganizationIdempotencyStore $idempotency,
        private readonly EncryptedCursor $cursor,
    ) {}

    /**
     * @param  array{cluster_id: string, parent_id?: string, type_code: string, code: string, name: string, name_en?: string|null}  $input
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  Closure(array<string, mixed>): array<string, mixed>  $eventFactory
     * @return array{created: bool, request_hash_matches: bool, unit: array<string, mixed>}
     */
    public function create(string $unitId, array $input, array $idempotency, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($unitId, $input, $idempotency, $eventFactory): array {
            $existingKey = $this->idempotency->query($idempotency)->lockForUpdate()->first();
            if ($existingKey instanceof stdClass) {
                return $this->replayResult($existingKey, $idempotency['request_hash']);
            }

            $parent = $this->resolveParent($input['cluster_id'], $input['parent_id'] ?? null, true);
            $type = DB::table('unit_types')->where('code', $input['type_code'])->where('is_active', true)->first();
            if (! $type instanceof stdClass) {
                throw new InvalidArgumentException('Organization unit type is invalid.');
            }
            if (DB::table('organization_units')
                ->where('parent_type', $parent['type'])
                ->where('parent_id', $parent['id'])
                ->where('code', $input['code'])
                ->exists()) {
                throw new DomainException('organization_unit_already_exists');
            }

            if (! $this->idempotency->claim($idempotency, 'organization_unit', $unitId)) {
                $concurrent = $this->idempotency->query($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('The organization unit idempotency claim could not be resolved.');
                }

                return $this->replayResult($concurrent, $idempotency['request_hash']);
            }

            $unit = OrganizationUnit::create(
                $unitId,
                $input['cluster_id'],
                $parent['id'],
                $parent['type'],
                $input['type_code'],
                $input['code'],
                $input['name'],
                $input['name_en'] ?? null,
                $parent['path'].'/'.$unitId,
                $parent['depth'] + 1,
            );
            $data = $unit->toArray();
            DB::table('organization_units')->insert([
                'id' => $data['id'],
                'cluster_id' => $data['cluster_id'],
                'parent_id' => $data['parent_id'],
                'parent_type' => $data['parent_type'],
                'unit_type_id' => $type->id,
                'code' => $data['code'],
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'],
                'status' => $data['status'],
                'path_cache' => $data['path_cache'],
                'depth' => $data['depth'],
                'lock_version' => $data['lock_version'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->idempotency->storeResponse($idempotency, $data);
            $this->outbox->insert($eventFactory($data), $unitId);

            return ['created' => true, 'request_hash_matches' => true, 'unit' => $data];
        });
    }

    /** @return array<string, mixed>|null */
    public function find(string $unitId): ?array
    {
        $row = $this->unitQuery()->where('organization_units.id', $unitId)->first();

        return $row instanceof stdClass ? $this->serialize($row) : null;
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function list(array $principal, ?string $cursor, int $limit, ?string $parentId): array
    {
        $after = $cursor === null ? null : $this->decodeCursor($cursor, $principal, $limit, $parentId);
        $query = $this->unitQuery()
            ->orderBy('organization_units.parent_type')
            ->orderBy('organization_units.parent_id')
            ->orderBy('organization_units.sort_order')
            ->orderBy('organization_units.code')
            ->orderBy('organization_units.id');
        if ($parentId !== null) {
            $query->where('organization_units.parent_id', $parentId);
        }
        if ($after !== null) {
            $query->whereRaw(
                '(organization_units.parent_type, organization_units.parent_id, organization_units.sort_order, organization_units.code, organization_units.id) > (?, ?, ?, ?, ?)',
                [
                    $after['after_parent_type'],
                    $after['after_parent_id'],
                    $after['after_sort_order'],
                    $after['after_code'],
                    $after['after_id'],
                ],
            );
        }
        $items = $query->limit($limit + 1)->get()->map(fn (stdClass $row): array => $this->serialize($row))->all();
        $hasNextPage = count($items) > $limit;
        if ($hasNextPage) {
            array_pop($items);
        }

        $nextCursor = null;
        if ($hasNextPage) {
            $last = $items[array_key_last($items)];
            $nextCursor = $this->encodeCursor([
                'after_parent_type' => (string) $last['parent_type'],
                'after_parent_id' => (string) $last['parent_id'],
                'after_sort_order' => (int) $last['sort_order'],
                'after_code' => (string) $last['code'],
                'after_id' => (string) $last['id'],
            ], $principal, $limit, $parentId);
        }

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }

    /**
     * @param  array{parent_id?: string, name?: string, status?: string}  $changes
     * @param  Closure(array<string, mixed>, string, string): array<string, mixed>  $eventFactory
     * @return array<string, mixed>
     */
    public function update(string $unitId, int $expectedVersion, array $changes, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($unitId, $expectedVersion, $changes, $eventFactory): array {
            $row = DB::table('organization_units')->where('id', $unitId)->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                throw new DomainException('organization_unit_not_found');
            }
            if ((int) $row->lock_version !== $expectedVersion) {
                throw new DomainException('precondition_failed');
            }

            $previousParentId = (string) $row->parent_id;
            $previousStatus = (string) $row->status;
            $parent = array_key_exists('parent_id', $changes)
                ? $this->resolveParent((string) $row->cluster_id, $changes['parent_id'], true)
                : [
                    'id' => (string) $row->parent_id,
                    'type' => (string) $row->parent_type,
                    'path' => substr((string) $row->path_cache, 0, (int) strrpos((string) $row->path_cache, '/')),
                    'depth' => (int) $row->depth - 1,
                ];
            if ($parent['type'] === 'unit'
                && ($parent['id'] === $unitId || str_starts_with($parent['path'].'/', (string) $row->path_cache.'/'))) {
                throw new DomainException('organization_unit_cycle');
            }

            $name = $changes['name'] ?? $row->name_ar;
            $status = $changes['status'] ?? $row->status;
            if (! is_string($name) || ! is_string($status)) {
                throw new InvalidArgumentException('Organization unit change is invalid.');
            }
            if ($row->status === 'archived' || ! $this->allowsTransition((string) $row->status, $status)) {
                throw new DomainException('invalid_organization_unit_transition');
            }

            $path = $parent['path'].'/'.$unitId;
            $depth = $parent['depth'] + 1;
            if (strlen($path) > 512) {
                throw new InvalidArgumentException('Organization unit path exceeds the governed limit.');
            }
            if ($name === $row->name_ar && $status === $row->status && $path === $row->path_cache) {
                throw new InvalidArgumentException('Organization unit patch does not change the resource.');
            }
            if (DB::table('organization_units')
                ->where('id', '!=', $unitId)
                ->where('parent_type', $parent['type'])
                ->where('parent_id', $parent['id'])
                ->where('code', $row->code)
                ->exists()) {
                throw new DomainException('organization_unit_already_exists');
            }

            $oldPath = (string) $row->path_cache;
            $descendants = $path === $oldPath
                ? []
                : DB::table('organization_units')
                    ->where('path_cache', 'like', $oldPath.'/%')
                    ->orderBy('depth')
                    ->lockForUpdate()
                    ->get()
                    ->all();
            $version = (int) $row->lock_version + 1;
            $updated = DB::table('organization_units')
                ->where('id', $unitId)
                ->where('lock_version', $expectedVersion)
                ->update([
                    'parent_id' => $parent['id'],
                    'parent_type' => $parent['type'],
                    'name_ar' => $name,
                    'status' => $status,
                    'path_cache' => $path,
                    'depth' => $depth,
                    'lock_version' => $version,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new DomainException('precondition_failed');
            }

            $depthDelta = $depth - (int) $row->depth;
            foreach ($descendants as $descendant) {
                $descendantPath = $path.substr((string) $descendant->path_cache, strlen($oldPath));
                if (strlen($descendantPath) > 512) {
                    throw new InvalidArgumentException('Organization unit descendant path exceeds the governed limit.');
                }
                DB::table('organization_units')->where('id', $descendant->id)->update([
                    'path_cache' => $descendantPath,
                    'depth' => (int) $descendant->depth + $depthDelta,
                    'lock_version' => (int) $descendant->lock_version + 1,
                    'updated_at' => now(),
                ]);
            }

            $unit = $this->serializeValues($row, $parent, $name, $status, $path, $depth, $version);
            $this->outbox->insert($eventFactory($unit, $previousParentId, $previousStatus), $unitId);

            return $unit;
        });
    }

    /**
     * Canonical sibling rebalance: assign each unit a fresh sort_order inside
     * its parent group, ordered by (type priority, code). Idempotent — two
     * consecutive runs produce the same ordering for the same input.
     *
     * @return array{updated: int, by_parent: list<string>, lock_version: int, request_hash_matches: bool}
     */
    public function reorderAll(
        Closure $eventFactory,
        int $expectedVersion,
        string $principalId,
        string $idempotencyKey,
        string $requestHash,
    ): array {
        $typePriority = [
            'sector' => 1,
            'department' => 2,
            'section' => 3,
            'unit' => 4,
            'committee' => 5,
        ];

        return DB::transaction(function () use ($eventFactory, $typePriority, $expectedVersion, $principalId, $idempotencyKey, $requestHash): array {
            $idempotency = [
                'principal_id' => (string) $principalId,
                'operation' => 'organization.units.reorder',
                'key_hash' => hash('sha256', (string) $idempotencyKey),
                'request_hash' => $requestHash,
            ];
            $existing = $this->idempotency->query($idempotency)->lockForUpdate()->first();
            if ($existing instanceof stdClass) {
                $payload = $this->idempotency->decodeResponse($existing, 'organization_unit');

                return [
                    'request_hash_matches' => $this->idempotency->hashMatches($existing, $idempotency['request_hash']),
                    ...$payload,
                ];
            }
            if (! $this->idempotency->claim($idempotency, 'organization_unit_collection', 'organization')) {
                $concurrent = $this->idempotency->query($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('Stored reorder idempotency state is incomplete.');
                }
                $payload = $this->idempotency->decodeResponse($concurrent, 'organization_unit');

                return [
                    'request_hash_matches' => $this->idempotency->hashMatches($concurrent, $idempotency['request_hash']),
                    ...$payload,
                ];
            }
            $cluster = DB::table('clusters')->lockForUpdate()->first();
            if ($cluster === null || (int) $cluster->lock_version !== $expectedVersion) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(412, 'If-Match does not match the current organization version.');
            }
            $rows = DB::table('organization_units as ou')
                ->join('unit_types as ut', 'ut.id', '=', 'ou.unit_type_id')
                ->where('ou.status', '!=', 'archived')
                ->select('ou.id', 'ou.cluster_id', 'ou.parent_type', 'ou.parent_id', 'ut.code as type_code', 'ou.code')
                ->orderBy('ou.parent_type')
                ->orderBy('ou.parent_id')
                ->orderBy('ou.code')
                ->orderBy('ou.id')
                ->get();

            $nextByParent = [];
            $updates = [];
            foreach ($rows as $row) {
                $parentKey = $row->parent_type.'/'.$row->parent_id;
                $priority = $typePriority[$row->type_code] ?? 99;
                if (! isset($nextByParent[$parentKey])) {
                    $nextByParent[$parentKey] = [];
                }
                $nextByParent[$parentKey][] = ['id' => $row->id, 'priority' => $priority, 'code' => $row->code];
            }

            $now = now();
            $updated = 0;
            $affectedParentKeys = [];
            foreach ($nextByParent as $parentKey => $siblings) {
                usort($siblings, static function (array $a, array $b): int {
                    return $a['priority'] <=> $b['priority']
                        ?: strcmp($a['code'], $b['code'])
                        ?: strcmp($a['id'], $b['id']);
                });
                $order = 0;
                foreach ($siblings as $sibling) {
                    $order++;
                    DB::table('organization_units')
                        ->where('id', $sibling['id'])
                        ->update([
                            'sort_order' => $order,
                            'updated_at' => $now,
                        ]);
                    $updated++;
                }
                $affectedParentKeys[] = $parentKey;
            }
            $payload = [
                'updated' => $updated,
                'by_parent' => $affectedParentKeys,
                'policy' => 'type-priority-then-code',
                'lock_version' => (int) $cluster->lock_version + 1,
            ];
            $advanced = DB::table('clusters')
                ->where('id', $cluster->id)
                ->where('lock_version', $expectedVersion)
                ->update(['lock_version' => $expectedVersion + 1, 'updated_at' => now()]);
            if ($advanced !== 1) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(412, 'If-Match does not match the current organization version.');
            }
            $this->idempotency->storeResponse($idempotency, $payload);
            $this->outbox->insert($eventFactory($payload), 'organization.units.reordered');

            return ['request_hash_matches' => true, ...$payload];
        });
    }

    /** @return array<string, mixed>|null */
    public function findReorderReplay(string $principalId, string $idempotencyKey, string $requestHash): ?array
    {
        $existing = DB::table('organization_idempotency_keys')
            ->where('principal_id', $principalId)
            ->where('operation', 'organization.units.reorder')
            ->where('idempotency_key_hash', hash('sha256', $idempotencyKey))
            ->first();
        if ($existing === null || ! is_string($existing->response_payload)) {
            return null;
        }

        return [
            'request_hash_matches' => hash_equals((string) $existing->request_hash, $requestHash),
            ...json_decode($existing->response_payload, true, 32, JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array{id: string, type: string, path: string, depth: int} */
    private function resolveParent(string $clusterId, ?string $parentId, bool $lock): array
    {
        $clusterQuery = DB::table('clusters')->where('id', $clusterId);
        $cluster = $lock ? $clusterQuery->lockForUpdate()->first() : $clusterQuery->first();
        if (! $cluster instanceof stdClass) {
            throw new InvalidArgumentException('Organization unit cluster is invalid.');
        }
        if ($parentId === null || $parentId === $clusterId) {
            return ['id' => $clusterId, 'type' => 'cluster', 'path' => '/'.$clusterId, 'depth' => 0];
        }

        $facilityQuery = DB::table('facilities')->where('id', $parentId)->where('cluster_id', $clusterId)->where('status', '!=', 'archived');
        $facility = $lock ? $facilityQuery->lockForUpdate()->first() : $facilityQuery->first();
        if ($facility instanceof stdClass) {
            return ['id' => $parentId, 'type' => 'facility', 'path' => '/'.$clusterId.'/'.$parentId, 'depth' => 1];
        }

        $unitQuery = DB::table('organization_units')->where('id', $parentId)->where('cluster_id', $clusterId)->where('status', '!=', 'archived');
        $unit = $lock ? $unitQuery->lockForUpdate()->first() : $unitQuery->first();
        if ($unit instanceof stdClass) {
            return ['id' => $parentId, 'type' => 'unit', 'path' => (string) $unit->path_cache, 'depth' => (int) $unit->depth];
        }

        throw new InvalidArgumentException('Organization unit parent is invalid.');
    }

    private function allowsTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return match ($from) {
            'active' => $to === 'inactive',
            'inactive' => in_array($to, ['active', 'archived'], true),
            default => false,
        };
    }

    private function unitQuery(): mixed
    {
        return DB::table('organization_units')
            ->join('unit_types', 'unit_types.id', '=', 'organization_units.unit_type_id')
            ->select('organization_units.*', 'unit_types.code as type_code');
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'cluster_id' => $row->cluster_id,
            'parent_id' => $row->parent_id,
            'parent_type' => $row->parent_type,
            'type_code' => $row->type_code,
            'code' => $row->code,
            'sort_order' => (int) $row->sort_order,
            'name_ar' => $row->name_ar,
            'name_en' => $row->name_en,
            'status' => $row->status,
            'path_cache' => $row->path_cache,
            'depth' => (int) $row->depth,
            'lock_version' => (int) $row->lock_version,
        ];
    }

    /**
     * @param  array{id: string, type: string, path: string, depth: int}  $parent
     * @return array<string, mixed>
     */
    private function serializeValues(stdClass $row, array $parent, string $name, string $status, string $path, int $depth, int $version): array
    {
        $typeCode = DB::table('unit_types')->where('id', $row->unit_type_id)->value('code');
        if (! is_string($typeCode)) {
            throw new UnexpectedValueException('Organization unit type state is incomplete.');
        }

        return [
            'id' => $row->id,
            'cluster_id' => $row->cluster_id,
            'parent_id' => $parent['id'],
            'parent_type' => $parent['type'],
            'type_code' => $typeCode,
            'code' => $row->code,
            'name_ar' => $name,
            'name_en' => $row->name_en,
            'status' => $status,
            'path_cache' => $path,
            'depth' => $depth,
            'lock_version' => $version,
        ];
    }

    /** @return array{created: bool, request_hash_matches: bool, unit: array<string, mixed>} */
    private function replayResult(stdClass $key, string $requestHash): array
    {
        $unit = $this->idempotency->decodeResponse($key, 'organization unit');

        return [
            'created' => false,
            'request_hash_matches' => $this->idempotency->hashMatches($key, $requestHash),
            'unit' => $unit,
        ];
    }

    /**
     * @param  array{
     *     after_parent_type: string,
     *     after_parent_id: string,
     *     after_sort_order: int,
     *     after_code: string,
     *     after_id: string,
     * }  $after
     * @param  array{user_id: string, facility_id: string}  $principal
     */
    private function encodeCursor(array $after, array $principal, int $limit, ?string $parentId): string
    {
        return $this->cursor->encrypt([
            'version' => 1,
            'resource' => 'organization_unit',
            'after_parent_type' => $after['after_parent_type'],
            'after_parent_id' => $after['after_parent_id'],
            'after_sort_order' => $after['after_sort_order'],
            'after_code' => $after['after_code'],
            'after_id' => $after['after_id'],
            'limit' => $limit,
            'parent_id' => $parentId,
            'principal_id' => $principal['user_id'],
        ]);
    }

    /**
     * @param  array{user_id: string, facility_id: string}  $principal
     * @return array{
     *     after_parent_type: string,
     *     after_parent_id: string,
     *     after_sort_order: int,
     *     after_code: string,
     *     after_id: string,
     * }
     */
    private function decodeCursor(string $cursor, array $principal, int $limit, ?string $parentId): array
    {
        $payload = $this->cursor->tryDecrypt($cursor);
        if ($payload === null
            || ($payload['version'] ?? null) !== 1
            || ($payload['resource'] ?? null) !== 'organization_unit'
            || ($payload['limit'] ?? null) !== $limit
            || ($payload['parent_id'] ?? null) !== $parentId
            || ! is_string($payload['principal_id'] ?? null)
            || ! hash_equals($principal['user_id'], $payload['principal_id'])
            || ! is_string($payload['after_id'] ?? null)
            || ! is_string($payload['after_parent_type'] ?? null)
            || ! is_string($payload['after_parent_id'] ?? null)
            || ! is_int($payload['after_sort_order'] ?? null)
            || ! is_string($payload['after_code'] ?? null)) {
            throw new InvalidArgumentException('The organization unit cursor is invalid.');
        }

        return [
            'after_parent_type' => $payload['after_parent_type'],
            'after_parent_id' => $payload['after_parent_id'],
            'after_sort_order' => $payload['after_sort_order'],
            'after_code' => $payload['after_code'],
            'after_id' => $payload['after_id'],
        ];
    }
}
