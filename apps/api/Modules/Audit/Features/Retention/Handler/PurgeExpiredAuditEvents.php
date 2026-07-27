<?php

declare(strict_types=1);

namespace Modules\Audit\Features\Retention\Handler;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Audit\Domain\AuditRetentionPolicy;
use Modules\Audit\Infrastructure\Persistence\AuditIntegrityRepository;

/**
 * Bounded retention purge — deletes ONLY a contiguous expired prefix of one
 * stream after the chain verifies and an immutable `retention_purge`
 * checkpoint is written in the same transaction. The retention activity is
 * recorded as one canonical `com.cluster.audit.auditeventrecorded.v1` event
 * through {@see RecordAuditEvent} so the durable operator trail shares the
 * producer's atomic commit with the checkpoint and the deletion. No fourth
 * outbox event type is introduced.
 *
 * Failure modes that leave every audit_events row untouched:
 *   - no candidate row has `retention_until < cutoff` (no-op)
 *   - chain mismatch in the candidate prefix
 *   - checkpoint insert fails (out-of-unique or otherwise)
 *   - delete affects a different number of rows than the prefix
 *   - prior retention_purge checkpoint already covers the prefix range
 *   - system key version is unavailable for any row
 *   - retention activity record fails (the entire transaction rolls back)
 */
final class PurgeExpiredAuditEvents
{
    public const SYSTEM_ACTOR_ID = '00000000-0000-7000-8000-000000000099';

    public function __construct(
        private readonly AuditIntegrityRepository $integrity,
        private readonly RecordAuditEvent $audit,
        private readonly AuditRetentionPolicy $retention,
    ) {}

    /**
     * @return array{
     *     checkpoint_id: string,
     *     stream_key: string,
     *     first_sequence: int,
     *     last_sequence: int,
     *     deleted_event_count: int,
     *     status: string,
     *     integrity_key_version: string,
     *     performed_at: string
     * }
     */
    public function run(string $streamKey, DateTimeImmutable $cutoff): array
    {
        try {
            AuditEventInput::assertModuleToken(explode(':', $streamKey)[0], 'sourceModule');
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('audit_stream_key_invalid');
        }
        if (strlen($streamKey) > 160) {
            throw new InvalidArgumentException('audit_stream_key_invalid');
        }

        $this->assertCutoff($cutoff);

        $checkpointId = (string) Str::uuid7();
        $correlationId = (string) Str::uuid7();
        $now = now('UTC')->toDateTimeImmutable();

        $result = DB::transaction(function () use ($checkpointId, $correlationId, $streamKey, $cutoff, $now): array {
            $purge = $this->integrity->purgeExpiredPrefix(
                checkpointId: $checkpointId,
                streamKey: $streamKey,
                cutoff: $cutoff,
                actorId: self::SYSTEM_ACTOR_ID,
                correlationId: $correlationId,
            );

            if ($purge['deleted_event_count'] > 0) {
                $this->audit->record(new AuditEventInput(
                    eventId: (string) Str::uuid7(),
                    sourceModule: 'audit',
                    action: 'audit.retention.purged',
                    eventType: 'com.cluster.audit.auditeventrecorded.v1',
                    actorType: AuditEventInput::ACTOR_SYSTEM,
                    actorId: null,
                    originalActorId: null,
                    subjectType: 'retention',
                    subjectId: null,
                    correlationId: $correlationId,
                    outcome: AuditEventInput::OUTCOME_SUCCEEDED,
                    classification: AuditEventInput::CLASSIFICATION_INTERNAL,
                    context: $this->sanitizedContext(
                        streamKey: $streamKey,
                        checkpointId: $checkpointId,
                        firstSequence: $purge['first_sequence'],
                        lastSequence: $purge['last_sequence'],
                        deletedEventCount: $purge['deleted_event_count'],
                    ),
                    occurredAt: $now,
                    retentionClass: AuditEventInput::RETENTION_SECURITY,
                ));
            }

            return $purge;
        }, 1);

        if ($result['deleted_event_count'] === 0) {
            return [
                'checkpoint_id' => '',
                'stream_key' => $streamKey,
                'first_sequence' => 0,
                'last_sequence' => 0,
                'deleted_event_count' => 0,
                'status' => $result['status'],
                'integrity_key_version' => '',
                'performed_at' => $now->format('Y-m-d\TH:i:s.v\Z'),
            ];
        }

        return [
            'checkpoint_id' => $result['checkpoint_id'],
            'stream_key' => $streamKey,
            'first_sequence' => $result['first_sequence'],
            'last_sequence' => $result['last_sequence'],
            'deleted_event_count' => $result['deleted_event_count'],
            'status' => $result['status'],
            'integrity_key_version' => $result['integrity_key_version'],
            'performed_at' => $now->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /**
     * Sanitized context carried by the canonical auditeventrecorded activity.
     * Carries only operational identifiers and counts; never any event hash,
     * integrity key version, raw canonical event JSON, or raw context JSON.
     *
     * @return array<string, int|string>
     */
    private function sanitizedContext(
        string $streamKey,
        string $checkpointId,
        int $firstSequence,
        int $lastSequence,
        int $deletedEventCount,
    ): array {
        return [
            'stream_key' => $streamKey,
            'checkpoint_id' => $checkpointId,
            'first_sequence' => $firstSequence,
            'last_sequence' => $lastSequence,
            'deleted_event_count' => $deletedEventCount,
        ];
    }

    private function assertCutoff(DateTimeImmutable $cutoff): void
    {
        $now = now('UTC')->toDateTimeImmutable();
        $today = new DateTimeImmutable($now->format('Y-m-d'), new DateTimeZone('UTC'));
        $legalFloor = $today->modify('-'.(string) $this->retention->floorDays().' days');

        if ($cutoff >= $legalFloor) {
            throw new InvalidArgumentException('audit_retention_floor_too_high');
        }
    }
}
