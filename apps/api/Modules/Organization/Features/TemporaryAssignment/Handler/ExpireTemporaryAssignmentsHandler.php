<?php

namespace Modules\Organization\Features\TemporaryAssignment\Handler;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Organization\Features\TemporaryAssignment\Events\BuildTemporaryAssignmentEvent;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use stdClass;

final class ExpireTemporaryAssignmentsHandler
{
    private const MAX_BATCH_SIZE = 500;

    public function __construct(
        private readonly OrganizationOutbox $outbox,
        private readonly BuildTemporaryAssignmentEvent $events,
        private readonly TemporaryAssignmentExpirationLock $lock = new TemporaryAssignmentExpirationLock,
    ) {}

    /** @return array{expired_count: int, expired_ids: list<string>, has_more: bool} */
    public function handle(int $limit, string $subjectId, string $correlationId): array
    {
        if ($limit < 1 || $limit > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('temporary_assignment_expiration_limit_invalid');
        }

        return DB::transaction(function () use ($limit, $subjectId, $correlationId): array {
            $now = CarbonImmutable::now('UTC')->floorMillisecond();
            $rows = $this->due($now)
                ->orderBy('id')
                ->limit($limit)
                ->lock($this->lock->valueFor(DB::connection()->getDriverName()))
                ->get()
                ->all();

            $expiredIds = [];
            foreach ($rows as $row) {
                $version = (int) $row->lock_version + 1;
                $updated = DB::table('temporary_assignments')
                    ->where('id', $row->id)
                    ->where('lock_version', $row->lock_version)
                    ->whereNotIn('state', ['expired', 'revoked'])
                    ->update([
                        'state' => 'expired',
                        'lock_version' => $version,
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1) {
                    throw new DomainException('temporary_assignment_expiration_conflict');
                }

                $temporaryAssignment = $this->serialize($row, $version);
                $this->outbox->insert($this->events->make(
                    'com.cluster.organization.temporaryassignmentexpired.v1',
                    $temporaryAssignment,
                    $subjectId,
                    $this->tenantId((string) $row->organization_unit_id),
                    $correlationId,
                ), (string) $row->id);
                $expiredIds[] = (string) $row->id;
            }

            return [
                'expired_count' => count($expiredIds),
                'expired_ids' => $expiredIds,
                'has_more' => $this->due($now)->exists(),
            ];
        });
    }

    private function due(CarbonImmutable $at): Builder
    {
        return DB::table('temporary_assignments')
            ->whereNotIn('state', ['expired', 'revoked'])
            ->where('end_at', '<=', $this->databaseTimestamp($at));
    }

    private function tenantId(string $organizationUnitId): string
    {
        $tenantId = DB::table('organization_units')
            ->where('id', $organizationUnitId)
            ->value('cluster_id');
        if (! is_string($tenantId) || $tenantId === '') {
            throw new DomainException('temporary_assignment_organization_unit_unavailable');
        }

        return $tenantId;
    }

    /** @return array<string, mixed> */
    private function serialize(stdClass $row, int $version): array
    {
        /** @var list<string> $capabilityCodes */
        $capabilityCodes = DB::table('temporary_assignment_capabilities')
            ->where('temporary_assignment_id', $row->id)
            ->orderBy('capability_code')
            ->pluck('capability_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->values()
            ->all();

        return [
            'id' => (string) $row->id,
            'person_id' => (string) $row->person_id,
            'organization_unit_id' => (string) $row->organization_unit_id,
            'capability_codes' => $capabilityCodes,
            'start_at' => CarbonImmutable::parse((string) $row->start_at)->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'end_at' => CarbonImmutable::parse((string) $row->end_at)->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'state' => 'expired',
            'lock_version' => $version,
        ];
    }

    private function databaseTimestamp(CarbonImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.v');
    }
}
