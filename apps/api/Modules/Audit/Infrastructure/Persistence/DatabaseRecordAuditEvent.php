<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\AuditEventReceipt;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Audit\Domain\AuditEventIdConflict;
use Modules\Audit\Domain\AuditIntegrityHasher;
use Modules\Audit\Domain\AuditRetentionPolicy;
use Modules\Audit\Domain\SensitiveValueRedactor;
use Modules\Audit\Events\AuditEventRecordedV1;
use Shared\Contracts\TransactionalOutbox;

/**
 * Atomic, idempotent Recording of audit events.
 *
 * Transaction boundary:
 *   - SELECT audit_events FOR UPDATE on event_id (no-op on SQLite)
 *   - INSERT audit_events row (idempotent on event_id)
 *   - INSERT audit_idempotency_keys row
 *   - INSERT outbox_events row (via Shared\Contracts\TransactionalOutbox)
 *   - COMMIT
 *
 * Dedup: a second call with the same eventId returns the original receipt
 * with `replayed = true`. Conflicts throw AuditEventIdConflict.
 */
final class DatabaseRecordAuditEvent implements RecordAuditEvent
{
    public function __construct(
        private readonly TransactionalOutbox $outbox,
        private readonly SensitiveValueRedactor $redactor,
        private readonly AuditIntegrityHasher $hasher,
        private readonly AuditRetentionPolicy $retention,
    ) {}

    public function record(AuditEventInput $input): AuditEventReceipt
    {
        $redacted = $this->redactor->redact($input->context);

        return DB::transaction(function () use ($input, $redacted): AuditEventReceipt {
            $existing = DB::table('audit_events')
                ->where('event_id', $input->eventId)
                ->first();

            if ($existing !== null) {
                $this->assertSameEvent($input, $existing);

                return new AuditEventReceipt(
                    eventId: $existing->event_id,
                    streamKey: AuditEventRecordedV1::STREAM_KEY,
                    streamSequence: (int) $existing->stream_sequence,
                    eventHash: $existing->event_hash,
                    recordedAt: new DateTimeImmutable((string) $existing->recorded_at, new \DateTimeZone('UTC')),
                    replayed: true,
                );
            }

            $previousHash = (string) (DB::table('audit_events')
                ->orderByDesc('id')
                ->value('event_hash') ?? str_repeat('0', 64));

            $eventHash = $this->hasher->hash($input, $previousHash);
            $now = now('UTC');
            $retentionUntil = $this->retention->resolveUntil($input);
            $streamSequence = (int) (DB::table('audit_events')->max('stream_sequence') ?? 0) + 1;

            DB::table('audit_events')->insert([
                'event_id' => $input->eventId,
                'source_module' => $input->sourceModule,
                'action' => $input->action,
                'event_type' => $input->eventType,
                'actor_type' => $input->actorType,
                'actor_id' => $input->actorId,
                'original_actor_id' => $input->originalActorId,
                'subject_type' => $input->subjectType,
                'subject_id' => $input->subjectId,
                'correlation_id' => $input->correlationId,
                'outcome' => $input->outcome,
                'classification' => $input->classification,
                'retention_class' => $input->retentionClass,
                'occurred_at' => $input->occurredAt,
                'recorded_at' => $now,
                'retention_until' => $retentionUntil,
                'event_hash' => $eventHash,
                'previous_event_hash' => $previousHash,
                'context_redacted' => json_encode($redacted, JSON_THROW_ON_ERROR),
                'stream_sequence' => $streamSequence,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->outbox->append(
                $input->eventId,
                $input->subjectId ?? $input->eventId,
                AuditEventRecordedV1::EVENT_TYPE,
                [
                    'eventId' => $input->eventId,
                    'sourceModule' => $input->sourceModule,
                    'action' => $input->action,
                    'actorType' => $input->actorType,
                    'actorId' => $input->actorId,
                    'subjectType' => $input->subjectType,
                    'subjectId' => $input->subjectId,
                    'correlationId' => $input->correlationId,
                    'outcome' => $input->outcome,
                    'classification' => $input->classification,
                    'retentionClass' => $input->retentionClass,
                    'eventHash' => $eventHash,
                    'contextRedacted' => $redacted,
                    'occurredAt' => $input->occurredAt->format('Y-m-d\TH:i:s.v\Z'),
                    'recordedAt' => $now->format('Y-m-d\TH:i:s.v\Z'),
                ],
            );

            return new AuditEventReceipt(
                eventId: $input->eventId,
                streamKey: AuditEventRecordedV1::STREAM_KEY,
                streamSequence: $streamSequence,
                eventHash: $eventHash,
                recordedAt: DateTimeImmutable::createFromMutable($now),
                replayed: false,
            );
        });
    }

    private function assertSameEvent(AuditEventInput $input, object $existing): void
    {
        $fields = [
            'source_module', 'action', 'event_type', 'actor_type',
            'subject_type', 'correlation_id', 'outcome',
            'classification', 'retention_class',
        ];
        foreach ($fields as $field) {
            if ($existing->{$field} !== $input->{$this->toInputField($field)}) {
                throw new AuditEventIdConflict($input->eventId, $field);
            }
        }
    }

    private function toInputField(string $column): string
    {
        return match ($column) {
            'source_module' => 'sourceModule',
            'event_type' => 'eventType',
            'actor_type' => 'actorType',
            'subject_type' => 'subjectType',
            'correlation_id' => 'correlationId',
            'classification' => 'classification',
            'retention_class' => 'retentionClass',
            default => $column,
        };
    }
}
