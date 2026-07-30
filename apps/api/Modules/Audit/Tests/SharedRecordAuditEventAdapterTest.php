<?php

declare(strict_types=1);

namespace Modules\Audit\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\AuditEventReceipt;
use Modules\Audit\Contracts\RecordAuditEvent as AuditRecordAuditEvent;
use Modules\Audit\Infrastructure\Persistence\SharedRecordAuditEventAdapter;
use PHPUnit\Framework\TestCase;
use Shared\Contracts\RecordAuditEvent as SharedRecordAuditEvent;

/**
 * Focused tests for the Shared-port adapter. These tests do not need the
 * Laravel database: they verify that the adapter correctly translates the
 * Shared plain-array payload into a fully-validated AuditEventInput, calls
 * the inner Audit port exactly once, and aliases the returned receipt to
 * void.
 *
 * They are red-green TDD tests for the Task 2 invariants listed in the
 * plan. They do NOT verify the inner DatabaseRecordAuditEvent persistence
 * — that is covered by RecordAuditEventTest.
 */
final class SharedRecordAuditEventAdapterTest extends TestCase
{
    private const EVENT_ID = '018f6f7d-0c00-7000-8000-000000000701';

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000702';

    private const SUBJECT_ID = '018f6f7d-0c00-7000-8000-000000000703';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000704';

    public function test_adapter_implements_shared_record_audit_event_contract(): void
    {
        $this->assertContains(
            SharedRecordAuditEvent::class,
            class_implements(SharedRecordAuditEventAdapter::class) ?: [],
            'Adapter must implement Shared\\Contracts\\RecordAuditEvent',
        );
    }

    public function test_record_accepts_the_documented_payload_and_calls_inner_once(): void
    {
        $inner = new RecordingInnerRecordAuditEvent;
        $adapter = new SharedRecordAuditEventAdapter($inner);

        $payload = $this->validPayload();

        $adapter->record($payload);

        $this->assertCount(1, $inner->calls);
        $captured = $inner->calls[0];

        $this->assertInstanceOf(AuditEventInput::class, $captured);
        $this->assertSame(self::EVENT_ID, $captured->eventId);
        $this->assertSame('authorization', $captured->sourceModule);
        $this->assertSame('authorization.role.created', $captured->action);
        $this->assertSame('com.cluster.authorization.rolecreated.v1', $captured->eventType);
        $this->assertSame(AuditEventInput::ACTOR_USER, $captured->actorType);
        $this->assertSame(self::ACTOR_ID, $captured->actorId);
        $this->assertSame(self::ACTOR_ID, $captured->originalActorId);
        $this->assertSame('role', $captured->subjectType);
        $this->assertSame(self::SUBJECT_ID, $captured->subjectId);
        $this->assertSame(self::CORRELATION_ID, $captured->correlationId);
        $this->assertSame(AuditEventInput::OUTCOME_SUCCEEDED, $captured->outcome);
        $this->assertSame(AuditEventInput::CLASSIFICATION_INTERNAL, $captured->classification);
        $this->assertSame(AuditEventInput::RETENTION_REGULATED, $captured->retentionClass);
        $this->assertSame(['method' => 'POST', 'resource_id' => self::SUBJECT_ID], $captured->context);
        $this->assertSame('2026-07-27T10:11:12.123Z', $captured->occurredAt->format('Y-m-d\TH:i:s.v\Z'));
    }

    public function test_record_returns_void_and_discards_audit_event_receipt(): void
    {
        $inner = new RecordingInnerRecordAuditEvent;
        $adapter = new SharedRecordAuditEventAdapter($inner);

        // Void contract: assert the inner-port side-effect instead.
        $adapter->record($this->validPayload());
        $this->assertCount(1, $inner->calls);
        $this->assertInstanceOf(AuditEventInput::class, $inner->calls[0]);
        $this->assertSame(self::EVENT_ID, $inner->calls[0]->eventId);

    }

    public function test_outcome_succeeded_failed_denied_map_to_audit_event_input_constants(): void
    {
        $inner = new RecordingInnerRecordAuditEvent;
        $adapter = new SharedRecordAuditEventAdapter($inner);

        $adapter->record($this->validPayload(outcome: 'succeeded'));
        $adapter->record($this->validPayload(outcome: 'failed'));
        $adapter->record($this->validPayload(outcome: 'denied'));

        $this->assertCount(3, $inner->calls);
        $this->assertSame(AuditEventInput::OUTCOME_SUCCEEDED, $inner->calls[0]->outcome);
        $this->assertSame(AuditEventInput::OUTCOME_FAILED, $inner->calls[1]->outcome);
        $this->assertSame(AuditEventInput::OUTCOME_DENIED, $inner->calls[2]->outcome);
    }

    public function test_non_uuid_correlation_id_throws_audit_correlation_id_invalid(): void
    {
        $inner = new RecordingInnerRecordAuditEvent;
        $adapter = new SharedRecordAuditEventAdapter($inner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('audit_correlation_id_invalid');

        $adapter->record($this->validPayload(correlationId: 'not-a-uuid'));
    }

    public function test_event_id_missing_is_generated_as_uuid_v7(): void
    {
        $inner = new RecordingInnerRecordAuditEvent;
        $adapter = new SharedRecordAuditEventAdapter($inner);

        $payload = $this->validPayload();
        unset($payload['event_id']);

        $adapter->record($payload);

        $this->assertCount(1, $inner->calls);
        $generated = $inner->calls[0]->eventId;
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $generated,
            'Generated eventId must be UUIDv7',
        );
    }

    public function test_event_id_empty_string_is_generated_as_uuid_v7(): void
    {
        $inner = new RecordingInnerRecordAuditEvent;
        $adapter = new SharedRecordAuditEventAdapter($inner);

        $adapter->record($this->validPayload(eventId: ''));

        $this->assertCount(1, $inner->calls);
        $this->assertNotSame('', $inner->calls[0]->eventId);
    }

    public function test_missing_correlation_id_throws_audit_field_missing_correlation_id(): void
    {
        $inner = new RecordingInnerRecordAuditEvent;
        $adapter = new SharedRecordAuditEventAdapter($inner);

        $payload = $this->validPayload();
        unset($payload['correlation_id']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('audit_field_missing:correlation_id');

        $adapter->record($payload);
    }

    public function test_missing_action_throws_audit_field_missing_action(): void
    {
        $inner = new RecordingInnerRecordAuditEvent;
        $adapter = new SharedRecordAuditEventAdapter($inner);

        $payload = $this->validPayload();
        unset($payload['action']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('audit_field_missing:action');

        $adapter->record($payload);
    }

    public function test_invalid_actor_type_throws_audit_actor_type_invalid(): void
    {
        $inner = new RecordingInnerRecordAuditEvent;
        $adapter = new SharedRecordAuditEventAdapter($inner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('audit_actor_type_invalid');

        $adapter->record($this->validPayload(actorType: 'robot'));
    }

    public function test_invalid_classification_throws_audit_classification_invalid(): void
    {
        $inner = new RecordingInnerRecordAuditEvent;
        $adapter = new SharedRecordAuditEventAdapter($inner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('audit_classification_invalid');

        $adapter->record($this->validPayload(classification: 'public-ish'));
    }

    public function test_invalid_retention_class_throws_audit_retention_class_invalid(): void
    {
        $inner = new RecordingInnerRecordAuditEvent;
        $adapter = new SharedRecordAuditEventAdapter($inner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('audit_retention_class_invalid');

        $adapter->record($this->validPayload(retentionClass: 'audit_only'));
    }

    public function test_invalid_event_type_throws_audit_eventtype_invalid(): void
    {
        $inner = new RecordingInnerRecordAuditEvent;
        $adapter = new SharedRecordAuditEventAdapter($inner);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('audit_eventType_invalid');

        $adapter->record($this->validPayload(eventType: 'not-a-catalog-token'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(
        ?string $eventId = self::EVENT_ID,
        ?string $sourceModule = 'authorization',
        ?string $action = 'authorization.role.created',
        ?string $eventType = 'com.cluster.authorization.rolecreated.v1',
        ?string $actorType = AuditEventInput::ACTOR_USER,
        ?string $actorId = self::ACTOR_ID,
        ?string $originalActorId = self::ACTOR_ID,
        ?string $subjectType = 'role',
        ?string $subjectId = self::SUBJECT_ID,
        ?string $correlationId = self::CORRELATION_ID,
        ?string $outcome = AuditEventInput::OUTCOME_SUCCEEDED,
        ?string $classification = AuditEventInput::CLASSIFICATION_INTERNAL,
        ?string $retentionClass = AuditEventInput::RETENTION_REGULATED,
        ?string $occurredAt = '2026-07-27T10:11:12.123Z',
        array $context = ['method' => 'POST', 'resource_id' => self::SUBJECT_ID],
    ): array {
        $payload = [
            'source_module' => $sourceModule,
            'action' => $action,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'subject_type' => $subjectType,
            'correlation_id' => $correlationId,
            'outcome' => $outcome,
            'classification' => $classification,
            'retention_class' => $retentionClass,
            'occurred_at' => $occurredAt,
            'context' => $context,
        ];

        if ($eventId !== null) {
            $payload['event_id'] = $eventId;
        }
        if ($actorId !== null) {
            $payload['actor_id'] = $actorId;
        }
        if ($originalActorId !== null) {
            $payload['original_actor_id'] = $originalActorId;
        }
        if ($subjectId !== null) {
            $payload['subject_id'] = $subjectId;
        }

        return $payload;
    }
}

/**
 * Test double for the inner Audit RecordAuditEvent port. Records every
 * AuditEventInput it receives so the test can assert that the adapter
 * invoked the inner port exactly once and supplied the expected payload.
 */
final class RecordingInnerRecordAuditEvent implements AuditRecordAuditEvent
{
    /** @var list<AuditEventInput> */
    public array $calls = [];

    public function record(AuditEventInput $input): AuditEventReceipt
    {
        $this->calls[] = $input;

        return new AuditEventReceipt(
            eventId: $input->eventId,
            streamKey: 'authorization:role:'.$input->subjectId,
            streamSequence: 1,
            eventHash: str_repeat('a', 64),
            recordedAt: new DateTimeImmutable('2026-07-27T12:34:56.789Z'),
            replayed: false,
        );
    }
}
