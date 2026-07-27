<?php

declare(strict_types=1);

namespace Modules\Audit\Events;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Audit\Contracts\AuditEventInput;

final readonly class AuditEventRecordedV1
{
    public const EVENT_TYPE = 'com.cluster.audit.auditeventrecorded.v1';

    public const STREAM_KEY = 'audit.events.recorded';

    public function __construct(
        public string $eventId,
        public string $sourceModule,
        public string $action,
        public string $actorType,
        public ?string $actorId,
        public ?string $originalActorId,
        public string $subjectType,
        public ?string $subjectId,
        public string $correlationId,
        public string $outcome,
        public string $classification,
        public string $retentionClass,
        public string $streamKey,
        public int $streamSequence,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $recordedAt,
    ) {
        AuditEventInput::assertUuidV7($eventId, 'eventId');
        AuditEventInput::assertModuleToken($sourceModule, 'sourceModule');
        AuditEventInput::assertCatalogToken($action, 128, 'action');
        if (! in_array($actorType, AuditEventInput::ALLOWED_ACTOR_TYPES, true)) {
            throw new InvalidArgumentException('audit_actor_type_invalid');
        }
        if ($actorType === AuditEventInput::ACTOR_SYSTEM
            && ($actorId !== null || $originalActorId !== null)) {
            throw new InvalidArgumentException('audit_system_actor_must_not_have_id');
        }
        if ($actorType !== AuditEventInput::ACTOR_SYSTEM && $actorId === null) {
            throw new InvalidArgumentException('audit_actor_id_required');
        }
        AuditEventInput::assertNullableUuidV7($actorId, 'actorId');
        AuditEventInput::assertNullableUuidV7($originalActorId, 'originalActorId');
        AuditEventInput::assertModuleToken($subjectType, 'subjectType');
        AuditEventInput::assertNullableUuidV7($subjectId, 'subjectId');
        AuditEventInput::assertUuidV7($correlationId, 'correlationId');
        if (! in_array($outcome, AuditEventInput::ALLOWED_OUTCOMES, true)) {
            throw new InvalidArgumentException('audit_outcome_invalid');
        }
        if (! in_array($classification, AuditEventInput::ALLOWED_CLASSIFICATIONS, true)) {
            throw new InvalidArgumentException('audit_classification_invalid');
        }
        if (! in_array($retentionClass, AuditEventInput::ALLOWED_RETENTION_CLASSES, true)) {
            throw new InvalidArgumentException('audit_retention_class_invalid');
        }
        self::assertStreamKey($streamKey);
        if ($streamSequence < 1) {
            throw new InvalidArgumentException('audit_stream_sequence_invalid');
        }
        AuditEventInput::assertUtcMilliseconds($occurredAt, 'occurredAt');
        AuditEventInput::assertUtcMilliseconds($recordedAt, 'recordedAt');
        if ($recordedAt < $occurredAt) {
            throw new InvalidArgumentException('audit_recorded_before_occurred');
        }
    }

    public function eventType(): string
    {
        return self::EVENT_TYPE;
    }

    /**
     * @return array{
     *     event_id: string,
     *     source_module: string,
     *     action: string,
     *     actor_type: string,
     *     actor_id: ?string,
     *     original_actor_id: ?string,
     *     subject_type: string,
     *     subject_id: ?string,
     *     correlation_id: string,
     *     outcome: string,
     *     classification: string,
     *     retention_class: string,
     *     stream_key: string,
     *     stream_sequence: int,
     *     occurred_at: string,
     *     recorded_at: string
     * }
     */
    public function payload(): array
    {
        return [
            'event_id' => $this->eventId,
            'source_module' => $this->sourceModule,
            'action' => $this->action,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'original_actor_id' => $this->originalActorId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'correlation_id' => $this->correlationId,
            'outcome' => $this->outcome,
            'classification' => $this->classification,
            'retention_class' => $this->retentionClass,
            'stream_key' => $this->streamKey,
            'stream_sequence' => $this->streamSequence,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.v\Z'),
            'recorded_at' => $this->recordedAt->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private static function assertStreamKey(string $streamKey): void
    {
        if (strlen($streamKey) > 160
            || preg_match('/\A[a-z][a-z0-9_-]*:[a-z][a-z0-9_-]*:(?:[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|global)\z/', $streamKey) !== 1) {
            throw new InvalidArgumentException('audit_stream_key_invalid');
        }
    }
}
