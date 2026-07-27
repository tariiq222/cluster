<?php

namespace Modules\Organization\Features\Position\Handler;

use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Organization\Domain\Position;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use Modules\Organization\Infrastructure\Persistence\EncryptedCursor;
use Modules\Organization\Infrastructure\Persistence\OrganizationIdempotencyStore;
use stdClass;
use UnexpectedValueException;

final class PositionHandler
{
    private const MAX_MANAGER_HOPS = 32;

    public function __construct(
        private readonly OrganizationOutbox $outbox,
        private readonly OrganizationIdempotencyStore $idempotency,
        private readonly EncryptedCursor $cursor,
    ) {}

    /**
     * @param  array{organization_unit_id: string, code: string, title?: string, job_title_id?: string|null, manager_position_id?: string|null}  $input
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  Closure(array<string, mixed>, string): array<string, mixed>  $eventFactory
     * @return array{created: bool, request_hash_matches: bool, position: array<string, mixed>}
     */
    public function create(string $positionId, array $input, array $idempotency, Closure $eventFactory): array
    {
        return DB::transaction(function () use ($positionId, $input, $idempotency, $eventFactory): array {
            $existingKey = $this->idempotency->query($idempotency)->lockForUpdate()->first();
            if ($existingKey instanceof stdClass) {
                return $this->replayResult($existingKey, $idempotency['request_hash']);
            }
            $unit = $this->assertUnit($input['organization_unit_id']);
            $this->assertManager($input['manager_position_id'] ?? null, $positionId);
            if (DB::table('positions')->where('organization_unit_id', $input['organization_unit_id'])->where('code', $input['code'])->exists()) {
                throw new DomainException('position_already_exists');
            }

            $jobTitleIdInput = $input['job_title_id'] ?? null;
            $jobTitleId = is_string($jobTitleIdInput) && $jobTitleIdInput !== '' ? $jobTitleIdInput : null;
            $titleInput = $input['title'] ?? null;
            $resolvedTitle = $this->resolveTitle($jobTitleId, is_string($titleInput) ? $titleInput : null);

            if (! $this->idempotency->claim($idempotency, 'position', $positionId)) {
                $concurrent = $this->idempotency->query($idempotency)->lockForUpdate()->first();
                if (! $concurrent instanceof stdClass) {
                    throw new UnexpectedValueException('The position idempotency claim could not be resolved.');
                }

                return $this->replayResult($concurrent, $idempotency['request_hash']);
            }

            $position = Position::create(
                $positionId,
                $input['organization_unit_id'],
                $input['code'],
                $resolvedTitle,
                $input['manager_position_id'] ?? null,
            );
            $data = $position->toArray();
            $data['job_title_id'] = $jobTitleId;
            DB::table('positions')->insert([
                'id' => $data['id'],
                'organization_unit_id' => $data['organization_unit_id'],
                'code' => $data['code'],
                'title_ar' => $data['title_ar'],
                'job_title_id' => $jobTitleId,
                'manager_position_id' => $data['manager_position_id'],
                'is_active' => $data['is_active'],
                'lock_version' => $data['lock_version'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->idempotency->storeResponse($idempotency, $data);
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
     * @param  array{organization_unit_id?: string, title?: string, job_title_id?: string|null, manager_position_id?: string|null}  $changes
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
            $managerId = array_key_exists('manager_position_id', $changes) ? $changes['manager_position_id'] : $row->manager_position_id;
            if (! is_string($unitId) || ($managerId !== null && ! is_string($managerId))) {
                throw new InvalidArgumentException('Position change is invalid.');
            }
            $unit = $this->assertUnit($unitId);
            $this->assertManager($managerId, $positionId);

            $currentJobTitleId = $row->job_title_id ?? null;
            if (array_key_exists('job_title_id', $changes)) {
                $raw = $changes['job_title_id'];
                $nextJobTitleId = is_string($raw) && $raw !== '' ? $raw : null;
            } else {
                $nextJobTitleId = $currentJobTitleId;
            }
            $changeTitle = $changes['title'] ?? null;
            $resolvedTitle = $this->resolveTitle(
                $nextJobTitleId,
                is_string($changeTitle) ? $changeTitle : null,
            );
            if ($nextJobTitleId === null && $currentJobTitleId === null) {
                $resolvedTitle = $resolvedTitle !== '' ? $resolvedTitle : (string) $row->title_ar;
            }

            if ($unitId === $row->organization_unit_id
                && $resolvedTitle === $row->title_ar
                && $managerId === $row->manager_position_id
                && $nextJobTitleId === $currentJobTitleId) {
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
                    'title_ar' => $resolvedTitle,
                    'job_title_id' => $nextJobTitleId,
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
                'title_ar' => $resolvedTitle,
                'job_title_id' => $nextJobTitleId,
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
        $hops = 0;
        while ($candidate->manager_position_id !== null) {
            if ($hops >= self::MAX_MANAGER_HOPS) {
                throw new DomainException('position_manager_cycle');
            }
            $hops++;
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
            'job_title_id' => $row->job_title_id ?? null,
            'manager_position_id' => $row->manager_position_id,
            'is_active' => (bool) $row->is_active,
            'lock_version' => (int) $row->lock_version,
        ];
    }

    /**
     * Resolves the position title for storage. When job_title_id is provided the
     * lookup table is the source of truth and any free-text `title` must agree.
     * When job_title_id is null the legacy free-text title is accepted to keep
     * the migration path open for rows created before the reference existed.
     */
    private function resolveTitle(?string $jobTitleId, ?string $freeText): string
    {
        if ($jobTitleId === null) {
            if (! is_string($freeText) || trim($freeText) === '') {
                throw new InvalidArgumentException('Position title is invalid.');
            }

            return $freeText;
        }
        $row = DB::table('job_titles')->where('id', $jobTitleId)->where('status', 'active')->first();
        if (! $row instanceof stdClass) {
            throw new InvalidArgumentException('Job title reference is invalid.');
        }
        if ($freeText !== null && trim($freeText) !== '' && $freeText !== $row->title_ar) {
            throw new InvalidArgumentException('Position title does not match the job title reference.');
        }

        return (string) $row->title_ar;
    }

    /** @return array{created: bool, request_hash_matches: bool, position: array<string, mixed>} */
    private function replayResult(stdClass $key, string $requestHash): array
    {
        $position = $this->idempotency->decodeResponse($key, 'position');

        return [
            'created' => false,
            'request_hash_matches' => $this->idempotency->hashMatches($key, $requestHash),
            'position' => $position,
        ];
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function encodeCursor(string $positionId, array $principal, int $limit, ?string $unitId): string
    {
        return $this->cursor->encrypt([
            'version' => 1,
            'resource' => 'position',
            'after_id' => $positionId,
            'limit' => $limit,
            'unit_id' => $unitId,
            'principal_id' => $principal['user_id'],
        ]);
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function decodeCursor(string $cursor, array $principal, int $limit, ?string $unitId): string
    {
        $payload = $this->cursor->tryDecrypt($cursor);
        if ($payload === null
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
