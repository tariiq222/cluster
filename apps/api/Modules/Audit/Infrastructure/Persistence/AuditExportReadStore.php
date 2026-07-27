<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Domain\AuditContextProjection;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;

/**
 * Read-side adapter for audit_export_jobs and the bounded query that
 * streams authorized audit_events rows up to a frozen snapshot upper
 * bound.
 *
 * The download handler uses this adapter to:
 *  - load the export descriptor by id;
 *  - stream audit_events rows up to and including the
 *    `snapshot_recorded_at` upper bound;
 *  - apply per-row authorization facts, exactly once per streamed row.
 *
 * Writes never happen here; the repository side owns {@see AuditExportRepository}.
 */
final class AuditExportReadStore
{
    /**
     * Fixed plan-safe upper bound on the number of audit_events rows
     * that any single Audit export may materialize. A descriptor whose
     * authorized snapshot would exceed this limit is rejected at
     * creation; a download that would exceed it is truncated with a
     * counted error so the descriptor and the stream can never disagree.
     */
    public const MAX_EXPORTED_ROWS = 10_000;

    /** Chunk size used for both the creation count and the streamed download. */
    private const CHUNK_SIZE = 200;

    /** @var list<string> */
    private const PROJECT_COLUMNS = [
        'id',
        'occurred_at',
        'recorded_at',
        'source_module',
        'action',
        'event_type',
        'actor_type',
        'actor_id',
        'subject_type',
        'subject_id',
        'correlation_id',
        'outcome',
        'classification',
        'context',
        'retention_until',
    ];

    public function __construct(
        private readonly DecideAccess $access,
        private readonly AuditContextProjection $contexts,
    ) {}

    /**
     * Apply the exact same filter/ABAC pipeline to the authorized
     * snapshot as the download stream. Returns the materialized count
     * up to the hard bound. Throws when the snapshot exceeds the hard
     * bound so the create handler can fail closed.
     *
     * @param  list<string>  $organizationUnitIds
     */
    public function countAuthorizedSnapshotRows(
        DateTimeImmutable $snapshotUpperBound,
        ?string $sourceModule,
        ?string $action,
        ?string $correlationId,
        ?DateTimeImmutable $occurredFrom,
        ?DateTimeImmutable $occurredTo,
        string $principalId,
        ?string $facilityId,
        array $organizationUnitIds,
    ): int {
        $count = 0;
        $this->iterateAuthorizedSnapshot(
            function (object $_row) use (&$count): void {
                $count++;
                if ($count > self::MAX_EXPORTED_ROWS) {
                    throw new InvalidArgumentException('audit_export_event_count_too_large');
                }
            },
            $snapshotUpperBound,
            $sourceModule,
            $action,
            $correlationId,
            $occurredFrom,
            $occurredTo,
            $principalId,
            $facilityId,
            $organizationUnitIds,
        );

        return $count;
    }

    /**
     * Stream authorized, snapshot-bounded audit_events rows through the
     * supplied callback. The stream is chunked via
     * {@see self::CHUNK_SIZE} and stops as soon as the per-row callback
     * returns `false` or the underlying scan is exhausted. The callback
     * must not retain `$row` past its own return; the adapter is the
     * sole owner of every materialization.
     *
     * @param  Closure(object): bool  $accept
     * @param  list<string>           $organizationUnitIds
     */
    public function streamAuthorizedSnapshot(
        Closure $accept,
        DateTimeImmutable $snapshotUpperBound,
        ?string $sourceModule,
        ?string $action,
        ?string $correlationId,
        ?DateTimeImmutable $occurredFrom,
        ?DateTimeImmutable $occurredTo,
        string $principalId,
        ?string $facilityId,
        array $organizationUnitIds,
    ): void {
        $emitted = 0;
        $this->iterateAuthorizedSnapshot(
            function (object $row) use ($accept, &$emitted): bool {
                $emitted++;
                if ($emitted > self::MAX_EXPORTED_ROWS) {
                    throw new InvalidArgumentException('audit_export_stream_too_large');
                }

                return $accept($row);
            },
            $snapshotUpperBound,
            $sourceModule,
            $action,
            $correlationId,
            $occurredFrom,
            $occurredTo,
            $principalId,
            $facilityId,
            $organizationUnitIds,
        );
    }

    /**
     * @param  Closure(object): mixed  $visitor
     * @param  list<string>           $organizationUnitIds
     */
    private function iterateAuthorizedSnapshot(
        Closure $visitor,
        DateTimeImmutable $snapshotUpperBound,
        ?string $sourceModule,
        ?string $action,
        ?string $correlationId,
        ?DateTimeImmutable $occurredFrom,
        ?DateTimeImmutable $occurredTo,
        string $principalId,
        ?string $facilityId,
        array $organizationUnitIds,
    ): void {
        $actor = [
            'user_id' => $principalId,
            'facility_id' => $facilityId,
            'organization_unit_ids' => $organizationUnitIds,
        ];
        $lastRecordedAt = null;
        $lastId = null;

        while (true) {
            $builder = DB::table('audit_events')
                ->select(self::PROJECT_COLUMNS)
                ->where('recorded_at', '<=', $this->databaseTimestamp($snapshotUpperBound))
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->limit(self::CHUNK_SIZE);

            if ($sourceModule !== null) {
                $builder->where('source_module', $sourceModule);
            }
            if ($action !== null) {
                $builder->where('action', $action);
            }
            if ($correlationId !== null) {
                $builder->where('correlation_id', $correlationId);
            }
            if ($occurredFrom !== null) {
                $builder->where('occurred_at', '>=', $this->databaseTimestamp($occurredFrom));
            }
            if ($occurredTo !== null) {
                $builder->where('occurred_at', '<=', $this->databaseTimestamp($occurredTo));
            }
            if ($lastRecordedAt !== null && $lastId !== null) {
                $builder->where(function ($query) use ($lastRecordedAt, $lastId): void {
                    $query->where('recorded_at', '<', $lastRecordedAt)
                        ->orWhere(function ($nested) use ($lastRecordedAt, $lastId): void {
                            $nested->where('recorded_at', '=', $lastRecordedAt)
                                ->where('id', '<', $lastId);
                        });
                });
            }

            /** @var list<object> $chunk */
            $chunk = $builder->get()->all();
            if ($chunk === []) {
                return;
            }

            foreach ($chunk as $row) {
                $lastRecordedAt = (string) $row->recorded_at;
                $lastId = (string) $row->id;
                $projected = $this->authorizeAndProject($row, $actor);
                if ($projected === null) {
                    continue;
                }
                $stop = $visitor($projected);
                if ($stop === false) {
                    return;
                }
            }

            if (count($chunk) < self::CHUNK_SIZE) {
                return;
            }
        }
    }



    /** @param array<string, mixed> $actor */
    private function authorizeAndProject(object $row, array $actor): ?object
    {
        $context = $this->contexts->decode($row->context ?? null);
        if ($context === null) {
            return null;
        }
        $scope = $this->scopeFacts($context);
        $decision = $this->access->decide(
            $actor,
            'audit.event.export',
            new RecordFacts(
                ownerFacilityId: $scope['facility_id'],
                resourceType: 'audit_event',
                classification: (string) $row->classification,
                organizationUnitId: $scope['organization_unit_id'],
                recordId: (string) $row->id,
                sourceModule: (string) $row->source_module,
                sharedUnitIds: $scope['organization_unit_ids'],
            ),
        );
        if (! $decision->isAllowed()) {
            return null;
        }
        $row->context = $this->contexts->apply($context, $decision);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{facility_id: string|null, organization_unit_id: string|null, organization_unit_ids: list<string>}
     */
    private function scopeFacts(array $context): array
    {
        $facilityId = $this->validUuid($context['facility_id'] ?? null);
        $organizationUnitId = $this->validUuid($context['organization_unit_id'] ?? null);
        $organizationUnitIds = $this->validUuidList($context['organization_unit_ids'] ?? null);
        if ($organizationUnitId !== null && ! in_array($organizationUnitId, $organizationUnitIds, true)) {
            array_unshift($organizationUnitIds, $organizationUnitId);
        }

        return [
            'facility_id' => $facilityId,
            'organization_unit_id' => $organizationUnitId ?? ($organizationUnitIds[0] ?? null),
            'organization_unit_ids' => $organizationUnitIds,
        ];
    }

    private function validUuid(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            AuditEventInput::assertUuidV7($value, 'scope');
        } catch (InvalidArgumentException) {
            return null;
        }

        return $value;
    }

    /** @return list<string> */
    private function validUuidList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }
        $validated = [];
        foreach ($value as $candidate) {
            $id = $this->validUuid($candidate);
            if ($id === null || in_array($id, $validated, true)) {
                return [];
            }
            $validated[] = $id;
        }

        return $validated;
    }

    private function databaseTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
