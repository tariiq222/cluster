<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Audit\Contracts\AuditActivityItem;
use Modules\Audit\Contracts\AuditActivityPage;
use Modules\Audit\Contracts\AuditActivityQuery;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\QueryAuditActivity;
use Modules\Audit\Domain\AuditContextProjection;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Shared\Http\AuthenticatedCursorCodec;
use stdClass;

/**
 * Audit-owned persistence adapter for stable, authorized activity reads.
 *
 * SQL predicates use only explicit audit_events columns. Persisted context is
 * never used as a SQL filter: its optional, validated scope identifiers become
 * row authorization facts, and DecideAccess remains authoritative.
 */
final class DatabaseQueryAuditActivity implements QueryAuditActivity
{
    private const CURSOR_RESOURCE = 'audit.events';

    private const FETCH_BATCH_SIZE = 100;

    /** @var list<string> */
    private const PUBLIC_COLUMNS = [
        'id',
        'source_module',
        'action',
        'event_type',
        'actor_type',
        'actor_id',
        'original_actor_id',
        'subject_type',
        'subject_id',
        'correlation_id',
        'outcome',
        'classification',
        'context',
        'occurred_at',
        'recorded_at',
        'retention_until',
    ];

    public function __construct(
        private readonly AuthenticatedCursorCodec $cursors,
        private readonly DecideAccess $access,
        private readonly AuditContextProjection $contexts,
    ) {}

    public function query(AuditActivityQuery $query): AuditActivityPage
    {
        $binding = $this->cursorBinding($query);
        $position = $query->cursor === null
            ? null
            : $this->decodePosition($query->cursor, $binding);
        $authorized = [];
        $scanPosition = $position;
        $exhausted = false;

        while (count($authorized) <= $query->limit && ! $exhausted) {
            $builder = $this->filteredQuery($query);
            $this->applyPosition($builder, $scanPosition);

            /** @var list<stdClass> $rows */
            $rows = $builder->limit(self::FETCH_BATCH_SIZE)->get()->all();
            $exhausted = count($rows) < self::FETCH_BATCH_SIZE;

            foreach ($rows as $row) {
                $scanPosition = $this->positionForRow($row);
                $item = $this->authorizeAndProject($query, $row);
                if ($item !== null) {
                    $authorized[] = [$item, $scanPosition];
                    if (count($authorized) > $query->limit) {
                        break 2;
                    }
                }
            }
        }

        $hasMore = count($authorized) > $query->limit;
        if ($hasMore) {
            array_pop($authorized);
        }

        $nextCursor = null;
        if ($hasMore && $authorized !== []) {
            $last = $authorized[count($authorized) - 1];
            $nextCursor = $this->cursors->encode(self::CURSOR_RESOURCE, $last[1], $binding);
        }

        return new AuditActivityPage(
            items: array_map(static fn (array $entry): AuditActivityItem => $entry[0], $authorized),
            nextCursor: $nextCursor,
        );
    }

    public function findAuthorized(AuditActivityQuery $scope, string $eventId): ?AuditActivityItem
    {
        AuditEventInput::assertUuidV7($eventId, 'eventId');

        $row = DB::table('audit_events')
            ->select(self::PUBLIC_COLUMNS)
            ->where('id', $eventId)
            ->first();

        return $row instanceof stdClass ? $this->authorizeAndProject($scope, $row) : null;
    }

    private function filteredQuery(AuditActivityQuery $query): Builder
    {
        $builder = DB::table('audit_events')
            ->select(self::PUBLIC_COLUMNS)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id');

        $filters = [
            'source_module' => $query->sourceModule,
            'action' => $query->action,
            'actor_id' => $query->actorId,
            'subject_type' => $query->subjectType,
            'subject_id' => $query->subjectId,
            'correlation_id' => $query->correlationId,
            'classification' => $query->classification,
        ];
        foreach ($filters as $column => $value) {
            if ($value !== null) {
                $builder->where($column, $value);
            }
        }
        if ($query->occurredFrom !== null) {
            $builder->where('occurred_at', '>=', $this->databaseTimestamp($query->occurredFrom));
        }
        if ($query->occurredTo !== null) {
            $builder->where('occurred_at', '<=', $this->databaseTimestamp($query->occurredTo));
        }

        return $builder;
    }

    /** @param array{0: string, 1: string}|null $position */
    private function applyPosition(Builder $builder, ?array $position): void
    {
        if ($position === null) {
            return;
        }

        [$recordedAt, $id] = $position;
        $builder->where(static function (Builder $positionQuery) use ($recordedAt, $id): void {
            $positionQuery->where('recorded_at', '<', $recordedAt)
                ->orWhere(static function (Builder $tie) use ($recordedAt, $id): void {
                    $tie->where('recorded_at', $recordedAt)->where('id', '<', $id);
                });
        });
    }

    /**
     * @param  array<string, mixed>  $binding
     * @return array{0: string, 1: string}
     */
    private function decodePosition(string $cursor, array $binding): array
    {
        $sort = $this->cursors->decode($cursor, self::CURSOR_RESOURCE, $binding);
        if (count($sort) !== 2 || ! is_string($sort[0] ?? null) || ! is_string($sort[1] ?? null)) {
            throw new InvalidArgumentException(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);
        }

        $recordedAt = $sort[0];
        $id = $sort[1];
        if (! $this->isDatabaseTimestamp($recordedAt)) {
            throw new InvalidArgumentException(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);
        }
        try {
            AuditEventInput::assertUuidV7($id, 'cursorId');
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(AuthenticatedCursorCodec::INVALID_CURSOR_MESSAGE);
        }

        return [$recordedAt, $id];
    }

    /** @return array{0: string, 1: string} */
    private function positionForRow(stdClass $row): array
    {
        $recordedAt = $this->normalizeDatabaseTimestamp((string) $row->recorded_at);
        $id = (string) $row->id;
        AuditEventInput::assertUuidV7($id, 'eventId');

        return [$recordedAt, $id];
    }

    /** @return array<string, mixed> */
    private function cursorBinding(AuditActivityQuery $query): array
    {
        $filters = [
            'source_module' => $query->sourceModule,
            'action' => $query->action,
            'actor_id' => $query->actorId,
            'subject_type' => $query->subjectType,
            'subject_id' => $query->subjectId,
            'correlation_id' => $query->correlationId,
            'classification' => $query->classification,
            'occurred_from' => $query->occurredFrom?->format('Y-m-d\TH:i:s.v\Z'),
            'occurred_to' => $query->occurredTo?->format('Y-m-d\TH:i:s.v\Z'),
        ];

        return [
            'principal_id' => $query->principalId,
            'facility_id' => $query->facilityId,
            'organization_unit_ids' => $query->organizationUnitIds,
            'filters' => $filters,
            'filter_fingerprint' => hash('sha256', (string) json_encode($filters, JSON_THROW_ON_ERROR)),
            'limit' => $query->limit,
        ];
    }

    private function authorizeAndProject(AuditActivityQuery $query, stdClass $row): ?AuditActivityItem
    {
        $context = $this->contexts->decode($row->context ?? null);
        if ($context === null) {
            return null;
        }
        $scope = $this->scopeFacts($context);
        $decision = $this->access->decide(
            $this->actor($query),
            'audit.event.read',
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

        return $this->projectRow($row, $context, $decision);
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

    /** @return array<string, mixed> */
    private function actor(AuditActivityQuery $query): array
    {
        return [
            'user_id' => $query->principalId,
            'facility_id' => $query->facilityId,
            'organization_unit_ids' => $query->organizationUnitIds,
        ];
    }

    /** @param array<string, mixed> $context */
    private function projectRow(stdClass $row, array $context, AccessDecision $decision): AuditActivityItem
    {
        $projection = AccessProjection::fromDecision($decision);
        $projectedContext = $this->contexts->apply($context, $decision);

        return new AuditActivityItem(
            eventId: (string) $row->id,
            sourceModule: (string) $row->source_module,
            action: (string) $row->action,
            eventType: (string) $row->event_type,
            actorType: (string) $row->actor_type,
            actorId: $row->actor_id === null ? null : (string) $row->actor_id,
            originalActorId: $row->original_actor_id === null ? null : (string) $row->original_actor_id,
            subjectType: (string) $row->subject_type,
            subjectId: $row->subject_id === null ? null : (string) $row->subject_id,
            correlationId: (string) $row->correlation_id,
            outcome: (string) $row->outcome,
            classification: (string) $row->classification,
            context: $projectedContext,
            occurredAt: $this->dateTime((string) $row->occurred_at),
            recordedAt: $this->dateTime((string) $row->recorded_at),
            accessDecisionId: $projection->decisionId,
            retentionUntil: $this->dateTime((string) $row->retention_until),
            integrityStatus: AuditActivityItem::INTEGRITY_UNVERIFIED,
            allowedActions: $projection->allowedActions,
        );
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }

    private function databaseTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function normalizeDatabaseTimestamp(string $value): string
    {
        return $this->dateTime($value)->format('Y-m-d H:i:s.v');
    }

    private function isDatabaseTimestamp(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d H:i:s.v') === $value;
    }
}
