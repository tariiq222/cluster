<?php

declare(strict_types=1);

namespace Modules\Audit\Features\CreateAuditExport\Handler;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Audit\Events\AuditExportCompletedV1;
use Modules\Audit\Infrastructure\Persistence\AuditExportReadStore;
use Modules\Audit\Infrastructure\Persistence\AuditExportRepository;
use Modules\Audit\Infrastructure\Persistence\AuditIdempotencyConflict;
use Modules\Audit\Infrastructure\Persistence\AuditIdempotencyStore;

/**
 * Synchronous, ready-only Audit export creation handler.
 *
 * One DB transaction writes all four effects atomically:
 *
 *   1. Claim the (principalId, operation, keyHash) idempotency record,
 *      or fall back to the stored response if the same key already
 *      matches the canonical request hash.
 *   2. The `audit_export_jobs` descriptor row with `status = 'ready'`
 *      and a frozen `snapshot_recorded_at` upper bound.
 *   3. One immutable Audit creation activity recorded via
 *      {@see RecordAuditEvent}.
 *   4. Exactly one `AuditExportCompletedV1` outbox event.
 *
 * Mismatched replay returns typed 409; concurrent first-time writers
 * race on the idempotency unique index and only one wins. The losing
 * transaction rolls back every effect.
 *
 * No bytes, paths, or hashes are persisted. The download handler
 * re-authorizes against the descriptor's frozen snapshot bound.
 */
final class CreateAuditExportHandler
{
    public const OPERATION = 'audit.exports.create';

    /** @var list<string> */
    private const ALLOWED_FILTER_KEYS = [
        'source_module',
        'action',
        'correlation_id',
        'occurred_from',
        'occurred_to',
    ];

    public function __construct(
        private readonly RecordAuditEvent $recorder,
        private readonly AuditExportRepository $exports,
        private readonly AuditExportReadStore $reads,
        private readonly AuditIdempotencyStore $idempotency,
        private readonly int $expiresAfterDays,
        private readonly int $maxWindowDays,
    ) {
        if ($expiresAfterDays < 1) {
            throw new InvalidArgumentException('audit_export_expires_after_days_invalid');
        }
        if ($maxWindowDays < 1) {
            throw new InvalidArgumentException('audit_export_max_window_days_invalid');
        }
    }

    /**
     * @param  array{
     *     principal_id: string,
     *     facility_id: ?string,
     *     organization_unit_ids: list<string>,
     *     correlation_id: string,
     *     format: 'csv'|'ndjson',
     *     filters: array<string, mixed>,
     *     reason: string,
     *     occurred_at: DateTimeImmutable
     * }  $command
     * @return array{
     *     descriptor: array<string, mixed>,
     *     etag: string,
     *     status: 201,
     *     replayed: bool
     * }
     */
    public function handle(array $command, string $idempotencyKey): array
    {
        AuditEventInput::assertUuidV7($command['principal_id'], 'principalId');
        AuditEventInput::assertNullableUuidV7($command['facility_id'], 'facilityId');
        $this->assertOrganizationUnitIds($command['organization_unit_ids']);
        AuditEventInput::assertUuidV7($command['correlation_id'], 'correlationId');

        $filters = $this->canonicalFilters($command['filters']);
        $reasonRedacted = $this->redactReason((string) $command['reason']);
        $snapshotRecordedAt = $this->assertSnapshotUpperBound(
            $command['occurred_at'],
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $keyHash = hash('sha256', $idempotencyKey);
        $canonicalRequest = [
            'principal_id' => $command['principal_id'],
            'facility_id' => $command['facility_id'],
            'format' => $command['format'],
            'filters' => $filters,
            'reason_redacted' => $reasonRedacted,
        ];
        $requestHash = hash(
            'sha256',
            (string) json_encode($canonicalRequest, JSON_THROW_ON_ERROR),
        );

        // Equal replay short-circuit: if the same (principal, op, keyHash)
        // already exists with the same canonical request hash, return the
        // stored descriptor.
        $existing = $this->idempotency->findResponse(
            $command['principal_id'],
            self::OPERATION,
            $keyHash,
        );
        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                throw new AuditIdempotencyMismatch(self::OPERATION);
            }
            $payload = $this->decodePayload((string) $existing->response_payload);
            $descriptor = $this->projectDescriptorFromPayload($payload);

            return [
                'descriptor' => $descriptor,
                'etag' => '"'.(string) $descriptor['id'].'"',
                'status' => 201,
                'replayed' => true,
            ];
        }
        $eventCount = $this->countSnapshotRows(
            $snapshotRecordedAt,
            $filters,
            $command['principal_id'],
            $command['facility_id'],
            $command['organization_unit_ids'],
        );

        $exportId = (string) Str::uuid7();
        $creationActivityEventId = (string) Str::uuid7();
        $completionEventId = (string) Str::uuid7();
        $expiresAt = $snapshotRecordedAt->modify(
            sprintf('+%d days', $this->expiresAfterDays),
        )->setTimezone(new DateTimeZone('UTC'));

        $completion = new AuditExportCompletedV1(
            eventId: $completionEventId,
            exportId: $exportId,
            principalId: $command['principal_id'],
            facilityId: $command['facility_id'],
            format: $command['format'],
            eventCount: $eventCount,
            correlationId: $command['correlation_id'],
            completedAt: $snapshotRecordedAt,
        );

        $descriptor = [
            'id' => $exportId,
            'principal_id' => $command['principal_id'],
            'facility_id' => $command['facility_id'],
            'query' => $filters,
            'format' => $command['format'],
            'snapshot_recorded_at' => $snapshotRecordedAt->format('Y-m-d\TH:i:s.v\Z'),
            'status' => AuditExportRepository::STATUS_READY,
            'event_count' => $eventCount,
            'expires_at' => $expiresAt->format('Y-m-d\TH:i:s.v\Z'),
            'created_at' => $snapshotRecordedAt->format('Y-m-d\TH:i:s.v\Z'),
        ];

        // Step 1: record the immutable Audit creation activity. The
        // eventId is shared with the completion event so consumers can
        $input = new AuditEventInput(
            eventId: $creationActivityEventId,
            sourceModule: 'audit',
            action: 'audit.export.created',
            eventType: 'com.cluster.audit.auditeventrecorded.v1',
            actorType: AuditEventInput::ACTOR_USER,
            actorId: $command['principal_id'],
            originalActorId: null,
            subjectType: 'audit_export',
            subjectId: $exportId,
            correlationId: $command['correlation_id'],
            outcome: AuditEventInput::OUTCOME_SUCCEEDED,
            classification: AuditEventInput::CLASSIFICATION_INTERNAL,
            context: [
                'format' => $command['format'],
                'event_count' => $eventCount,
                'snapshot_recorded_at' => $snapshotRecordedAt->format('Y-m-d\TH:i:s.v\Z'),
                'expires_at' => $expiresAt->format('Y-m-d\TH:i:s.v\Z'),
                'facility_id' => $command['facility_id'],
            ],
            occurredAt: $snapshotRecordedAt,
            retentionClass: AuditEventInput::RETENTION_SECURITY,
        );

        // Step 2: atomic transaction over the idempotency claim, the
        // descriptor, the audit activity, and the completion outbox event.
        try {
            DB::transaction(function () use (
                $exportId,
                $command,
                $filters,
                $reasonRedacted,
                $snapshotRecordedAt,
                $eventCount,
                $expiresAt,
                $completion,
                $input,
                $keyHash,
                $requestHash,
                $descriptor,
            ): void {
                // Claim idempotency first so a concurrent winner is
                // rejected and the loser rolls back every effect.
                $this->idempotency->storeResponse(
                    id: (string) Str::uuid7(),
                    principalId: $command['principal_id'],
                    operation: self::OPERATION,
                    keyHash: $keyHash,
                    requestHash: $requestHash,
                    responseStatus: 201,
                    responsePayload: $descriptor,
                    resourceId: $exportId,
                );

                // Record the immutable Audit creation activity.
                $this->recorder->record($input);

                // Append descriptor + AuditExportCompletedV1 outbox event.
                $this->exports->create(
                    id: $exportId,
                    principalId: $command['principal_id'],
                    facilityId: $command['facility_id'],
                    correlationId: $command['correlation_id'],
                    query: $filters,
                    queryHash: hash('sha256', (string) json_encode($filters, JSON_THROW_ON_ERROR)),
                    reasonRedacted: $reasonRedacted,
                    format: $command['format'],
                    snapshotRecordedAt: $snapshotRecordedAt,
                    eventCount: $eventCount,
                    expiresAt: $expiresAt,
                    completion: $completion,
                );
            });
        } catch (AuditIdempotencyConflict) {
            // The unique-constraint race means a concurrent writer
            // already won. Re-read the stored response, verify the
            // canonical request matches, and return it; if it does not
            // match, surface the typed 409.
            $existing = $this->idempotency->findResponse(
                $command['principal_id'],
                self::OPERATION,
                $keyHash,
            );
            if ($existing === null || ! hash_equals((string) $existing->request_hash, $requestHash)) {
                throw new AuditIdempotencyMismatch(self::OPERATION);
            }
            $payload = $this->decodePayload((string) $existing->response_payload);

            return [
                'descriptor' => $this->projectDescriptorFromPayload($payload),
                'etag' => '"'.(string) $payload['id'].'"',
                'status' => 201,
                'replayed' => true,
            ];
        } catch (QueryException $exception) {
            // A different unique-constraint violation (e.g. the audit
            // activity stream sequence race) is retried by the inner
            // adapters. If we get here, the inner adapters have already
            // exhausted their retries.
            throw $exception;
        }

        return [
            'descriptor' => $descriptor,
            'etag' => '"'.(string) $exportId.'"',
            'status' => 201,
            'replayed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function canonicalFilters(array $filters): array
    {
        $unknownKeys = array_diff(array_keys($filters), self::ALLOWED_FILTER_KEYS);
        if ($unknownKeys !== []) {
            throw new InvalidArgumentException('audit_export_filter_key_invalid');
        }
        $allowed = [];
        foreach (self::ALLOWED_FILTER_KEYS as $key) {
            if (! array_key_exists($key, $filters)) {
                continue;
            }
            $value = $filters[$key];
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value)) {
                throw new InvalidArgumentException('audit_export_filter_value_invalid');
            }
            $allowed[$key] = $value;
        }
        ksort($allowed, SORT_STRING);

        if (isset($allowed['source_module'])) {
            AuditEventInput::assertModuleToken($allowed['source_module'], 'source_module');
        }
        if (isset($allowed['action'])) {
            AuditEventInput::assertCatalogToken($allowed['action'], 128, 'action');
        }
        if (isset($allowed['correlation_id'])) {
            AuditEventInput::assertUuidV7($allowed['correlation_id'], 'correlation_id');
        }
        if (isset($allowed['occurred_from'])) {
            AuditEventInput::assertUtcMilliseconds(
                new DateTimeImmutable($allowed['occurred_from'], new DateTimeZone('UTC')),
                'occurred_from',
            );
        }
        if (isset($allowed['occurred_to'])) {
            AuditEventInput::assertUtcMilliseconds(
                new DateTimeImmutable($allowed['occurred_to'], new DateTimeZone('UTC')),
                'occurred_to',
            );
        }
        if (isset($allowed['occurred_from'], $allowed['occurred_to'])
            && $allowed['occurred_from'] > $allowed['occurred_to']) {
            throw new InvalidArgumentException('audit_export_filter_range_invalid');
        }

        return $allowed;
    }

    private function redactReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('audit_export_reason_required');
        }
        if (mb_strlen($reason, 'UTF-8') > 500) {
            throw new InvalidArgumentException('audit_export_reason_too_long');
        }

        return '[REDACTED]';
    }

    private function assertSnapshotUpperBound(
        DateTimeImmutable $candidate,
        DateTimeImmutable $now,
    ): DateTimeImmutable {
        if ($candidate > $now) {
            throw new InvalidArgumentException('audit_export_snapshot_in_future');
        }
        $maxAge = $now->modify(sprintf('-%d days', $this->maxWindowDays));
        if ($candidate < $maxAge) {
            throw new InvalidArgumentException('audit_export_snapshot_out_of_window');
        }

        return $candidate->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $organizationUnitIds
     */
    private function countSnapshotRows(
        DateTimeImmutable $snapshot,
        array $filters,
        string $principalId,
        ?string $facilityId,
        array $organizationUnitIds,
    ): int {
        return $this->reads->countAuthorizedSnapshotRows(
            $snapshot,
            $filters['source_module'] ?? null,
            $filters['action'] ?? null,
            $filters['correlation_id'] ?? null,
            isset($filters['occurred_from'])
                ? new DateTimeImmutable($filters['occurred_from'], new DateTimeZone('UTC'))
                : null,
            isset($filters['occurred_to'])
                ? new DateTimeImmutable($filters['occurred_to'], new DateTimeZone('UTC'))
                : null,
            $principalId,
            $facilityId,
            $organizationUnitIds,
        );
    }

    /** @param array<array-key, mixed> $organizationUnitIds */
    private function assertOrganizationUnitIds(array $organizationUnitIds): void
    {
        if (! array_is_list($organizationUnitIds)) {
            throw new InvalidArgumentException('audit_export_organization_unit_ids_invalid');
        }
        $seen = [];
        foreach ($organizationUnitIds as $organizationUnitId) {
            if (! is_string($organizationUnitId)) {
                throw new InvalidArgumentException('audit_export_organization_unit_ids_invalid');
            }
            AuditEventInput::assertUuidV7($organizationUnitId, 'organizationUnitId');
            if (isset($seen[$organizationUnitId])) {
                throw new InvalidArgumentException('audit_export_organization_unit_ids_duplicate');
            }
            $seen[$organizationUnitId] = true;
        }
    }

    /** @return array<string, mixed> */
    private function decodePayload(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('audit_export_idempotency_payload_corrupt');
        }
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('audit_export_idempotency_payload_corrupt');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private function projectDescriptorFromPayload(array $payload): array
    {
        return [
            'id' => (string) ($payload['id'] ?? ''),
            'principal_id' => (string) ($payload['principal_id'] ?? ''),
            'facility_id' => $payload['facility_id'] ?? null,
            'query' => is_array($payload['query'] ?? null) ? $payload['query'] : [],
            'format' => (string) ($payload['format'] ?? 'csv'),
            'snapshot_recorded_at' => (string) ($payload['snapshot_recorded_at'] ?? ''),
            'status' => (string) ($payload['status'] ?? AuditExportRepository::STATUS_READY),
            'event_count' => (int) ($payload['event_count'] ?? 0),
            'expires_at' => (string) ($payload['expires_at'] ?? ''),
            'created_at' => (string) ($payload['created_at'] ?? ''),
        ];
    }
}

/**
 * Raised when the same idempotency key is replayed with a request body
 * that differs from the original canonical request. The HTTP layer
 * surfaces this as a typed 409 `idempotency-conflict` problem.
 */
final class AuditIdempotencyMismatch extends InvalidArgumentException
{
    public function __construct(string $operation)
    {
        parent::__construct('audit_export_idempotency_mismatch:'.$operation);
    }
}
