<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\RecordAuditEvent as AuditRecordAuditEvent;
use Shared\Contracts\RecordAuditEvent;

/**
 * Adapter that exposes the Audit module's RecordAuditEvent implementation
 * through the Shared\Contracts\RecordAuditEvent port. Foreign modules
 * (Authorization, Documents, etc.) inject this Shared port and never need
 * to import any Modules\Audit symbol directly.
 *
 * The adapter translates the Shared plain-array payload into a fully
 * validated AuditEventInput (all canonical invariants), invokes the inner
 * Audit port exactly once, and discards the returned AuditEventReceipt
 * because the Shared contract returns void.
 */
final class SharedRecordAuditEventAdapter implements RecordAuditEvent
{
    /**
     * @var list<string>
     */
    private const REQUIRED_FIELDS = [
        'source_module',
        'action',
        'event_type',
        'actor_type',
        'subject_type',
        'correlation_id',
        'outcome',
        'classification',
        'retention_class',
        'occurred_at',
    ];

    public function __construct(private readonly AuditRecordAuditEvent $inner) {}

    public function record(array $event): void
    {
        foreach (self::REQUIRED_FIELDS as $required) {
            if (! array_key_exists($required, $event)) {
                throw new InvalidArgumentException("audit_field_missing:{$required}");
            }
        }

        $correlationId = (string) $event['correlation_id'];
        // Correlation identifier must be UUIDv7 to match the inner Audit
        // invariant; assert before building the AuditEventInput so the
        // Shared-side adapter surfaces the documented failure code.
        AuditEventInput::assertUuidV7($correlationId, 'correlation_id');

        $occurredAt = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.v\Z',
            (string) $event['occurred_at'],
            new DateTimeZone('UTC'),
        );
        if ($occurredAt === false) {
            throw new InvalidArgumentException('audit_occurred_at_invalid');
        }

        $eventId = $event['event_id'] ?? null;
        if (! is_string($eventId) || $eventId === '') {
            $eventId = Str::uuid7()->toString();
        }

        $context = $event['context'] ?? [];
        if (! is_array($context)) {
            $context = [];
        }

        $input = new AuditEventInput(
            eventId: $eventId,
            sourceModule: (string) $event['source_module'],
            action: (string) $event['action'],
            eventType: (string) $event['event_type'],
            actorType: (string) $event['actor_type'],
            actorId: isset($event['actor_id']) ? (string) $event['actor_id'] : null,
            originalActorId: isset($event['original_actor_id']) ? (string) $event['original_actor_id'] : null,
            subjectType: (string) $event['subject_type'],
            subjectId: isset($event['subject_id']) ? (string) $event['subject_id'] : null,
            correlationId: $correlationId,
            outcome: (string) $event['outcome'],
            classification: (string) $event['classification'],
            context: $context,
            occurredAt: $occurredAt,
            retentionClass: (string) $event['retention_class'],
        );

        $this->inner->record($input);
    }
}