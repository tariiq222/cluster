<?php

declare(strict_types=1);

namespace Modules\Audit\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Audit\Contracts\AuditActivityItem;
use Modules\Audit\Contracts\AuditActivityPage;
use Modules\Audit\Contracts\AuditActivityQuery;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\AuditEventReceipt;
use Modules\Audit\Contracts\QueryAuditActivity;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Audit\Domain\AuditIntegrityHasher;
use Modules\Audit\Domain\AuditRetentionPolicy;
use Modules\Audit\Events\AuditEventRecordedV1;
use Modules\Audit\Events\AuditExportCompletedV1;
use Modules\Audit\Events\AuditIntegrityViolationDetectedV1;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AuditContractsTest extends TestCase
{
    private const EVENT_ID = '018f6f7d-0c00-7000-8000-000000000101';

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000102';

    private const SUBJECT_ID = '018f6f7d-0c00-7000-8000-000000000103';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000104';

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000105';

    public function test_m00_contract_signatures_and_event_types_are_exact(): void
    {
        $this->assertSame('record', (new ReflectionClass(RecordAuditEvent::class))->getMethods()[0]->getName());
        $this->assertSame('query', (new ReflectionClass(QueryAuditActivity::class))->getMethods()[0]->getName());
        $this->assertSame(AuditEventReceipt::class, (string) (new ReflectionMethod(RecordAuditEvent::class, 'record'))->getReturnType());
        $this->assertSame(AuditActivityPage::class, (string) (new ReflectionMethod(QueryAuditActivity::class, 'query'))->getReturnType());

        $this->assertConstructorFields(AuditEventInput::class, [
            'eventId', 'sourceModule', 'action', 'eventType', 'actorType', 'actorId',
            'originalActorId', 'subjectType', 'subjectId', 'correlationId', 'outcome',
            'classification', 'context', 'occurredAt', 'retentionClass',
        ]);
        $this->assertConstructorFields(AuditEventReceipt::class, [
            'eventId', 'streamKey', 'streamSequence', 'eventHash', 'recordedAt', 'replayed',
        ]);
        $this->assertConstructorFields(AuditActivityQuery::class, [
            'principalId', 'facilityId', 'organizationUnitIds', 'cursor', 'sourceModule',
            'action', 'actorId', 'subjectType', 'subjectId', 'correlationId',
            'classification', 'occurredFrom', 'occurredTo', 'limit',
        ]);
        $this->assertConstructorFields(AuditActivityItem::class, [
            'eventId', 'sourceModule', 'action', 'eventType', 'actorType', 'actorId',
            'originalActorId', 'subjectType', 'subjectId', 'correlationId', 'outcome',
            'classification', 'context', 'occurredAt', 'recordedAt', 'accessDecisionId',
            'retentionUntil', 'integrityStatus', 'allowedActions',
        ]);

        $this->assertSame('com.cluster.audit.auditeventrecorded.v1', AuditEventRecordedV1::EVENT_TYPE);
        $this->assertSame('com.cluster.audit.auditexportcompleted.v1', AuditExportCompletedV1::EVENT_TYPE);
        $this->assertSame('com.cluster.audit.auditintegrityviolationdetected.v1', AuditIntegrityViolationDetectedV1::EVENT_TYPE);

    }

    public function test_event_input_accepts_only_uuidv7_catalog_enums_utc_milliseconds_and_bounded_context(): void
    {
        $input = $this->validInput();

        $this->assertSame(self::EVENT_ID, $input->eventId);
        $this->assertSame('2026-07-27T10:11:12.123Z', $input->occurredAt->format('Y-m-d\TH:i:s.v\Z'));
        $this->assertSame(['request' => ['method' => 'POST'], 'resource_id' => self::SUBJECT_ID], $input->context);

        foreach (self::invalidInputs() as $case) {
            [$field, $value] = $case;
            $this->assertInvalidInput($field, $value);
        }
    }

    public function test_query_is_principal_and_scope_bound_with_safe_filters_and_bounded_limit(): void
    {
        $query = new AuditActivityQuery(
            self::ACTOR_ID,
            self::FACILITY_ID,
            [self::FACILITY_ID],
            null,
            'documents',
            'document.uploaded',
            self::ACTOR_ID,
            'document',
            self::SUBJECT_ID,
            self::CORRELATION_ID,
            'confidential',
            new DateTimeImmutable('2026-07-01T00:00:00.000Z'),
            new DateTimeImmutable('2026-07-27T23:59:59.999Z'),
            100,
        );

        $this->assertSame(100, $query->limit);
        $this->assertSame([self::FACILITY_ID], $query->organizationUnitIds);

        $this->expectException(InvalidArgumentException::class);
        new AuditActivityQuery(
            self::ACTOR_ID,
            self::FACILITY_ID,
            [],
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            101,
        );
    }

    public function test_hmac_integrity_hash_is_keyed_deterministic_and_verifiable(): void
    {
        $hasher = new AuditIntegrityHasher([
            'v1' => 'testing-only-32-byte-key-material',
        ]);
        $event = [
            'stream_key' => 'documents:document:'.self::SUBJECT_ID,
            'stream_sequence' => 1,
            'event_id' => self::EVENT_ID,
            'occurred_at' => '2026-07-27T10:11:12.123Z',
            'context' => ['b' => 2, 'a' => ['z' => true, 'y' => null]],
        ];

        $hash = $hasher->eventHash($event, null, 'v1');
        $reordered = $event;
        $reordered['context'] = ['a' => ['y' => null, 'z' => true], 'b' => 2];

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash);
        $this->assertSame($hash, $hasher->eventHash($reordered, null, 'v1'));
        $this->assertTrue($hasher->verify($event, null, 'v1', $hash));
        $this->assertFalse($hasher->verify($event, str_repeat('a', 64), 'v1', $hash));

        $this->expectException(InvalidArgumentException::class);
        $hasher->eventHash($event, null, 'missing');
    }

    #[DataProvider('retentionClassProvider')]
    public function test_retention_policy_enforces_frozen_minimums(string $class, int $days): void
    {
        $recordedAt = new DateTimeImmutable('2026-07-27T00:00:00.000Z');
        $policy = new AuditRetentionPolicy(2555);

        $this->assertSame(
            $recordedAt->modify('+'.$days.' days')->format('Y-m-d\TH:i:s.v\Z'),
            $policy->retentionUntil($recordedAt, $class)->format('Y-m-d\TH:i:s.v\Z'),
        );
    }

    /** @return iterable<string, array{string, int}> */
    public static function retentionClassProvider(): iterable
    {
        yield 'standard' => ['standard', 2555];
        yield 'security' => ['security', 3650];
        yield 'regulated' => ['regulated', 3650];
    }

    /** @return list<array{string, mixed}> */
    private static function invalidInputs(): array
    {
        return [
            ['eventId', '018F6F7D-0C00-7000-8000-000000000101'],
            ['sourceModule', 'Documents/../../'],
            ['action', 'document uploaded'],
            ['eventType', 'Document Uploaded'],
            ['actorType', 'robot'],
            ['actorId', 'not-a-uuid'],
            ['correlationId', 'not-a-uuid'],
            ['outcome', 'ok'],
            ['classification', 'secret'],
            ['retentionClass', 'short'],
            ['occurredAt', new DateTimeImmutable('2026-07-27T13:11:12.123+03:00')],
            ['context', ['float' => 1.25]],
            ['context', ['object' => new \stdClass]],
            ['context', self::nestedContext(7)],
            ['context', array_fill_keys(array_map(static fn (int $i): string => 'key_'.$i, range(1, 101)), true)],
            ['context', ['oversized' => str_repeat('x', 16 * 1024)]],
        ];
    }

    private function validInput(): AuditEventInput
    {
        return new AuditEventInput(
            self::EVENT_ID,
            'documents',
            'document.uploaded',
            'com.cluster.documents.documentuploaded.v1',
            'user',
            self::ACTOR_ID,
            self::ACTOR_ID,
            'document',
            self::SUBJECT_ID,
            self::CORRELATION_ID,
            'succeeded',
            'confidential',
            ['request' => ['method' => 'POST'], 'resource_id' => self::SUBJECT_ID],
            new DateTimeImmutable('2026-07-27T10:11:12.123Z'),
            'regulated',
        );
    }

    private function assertInvalidInput(string $field, mixed $value): void
    {
        $values = [
            'eventId' => self::EVENT_ID,
            'sourceModule' => 'documents',
            'action' => 'document.uploaded',
            'eventType' => 'com.cluster.documents.documentuploaded.v1',
            'actorType' => 'user',
            'actorId' => self::ACTOR_ID,
            'originalActorId' => self::ACTOR_ID,
            'subjectType' => 'document',
            'subjectId' => self::SUBJECT_ID,
            'correlationId' => self::CORRELATION_ID,
            'outcome' => 'succeeded',
            'classification' => 'confidential',
            'context' => ['resource_id' => self::SUBJECT_ID],
            'occurredAt' => new DateTimeImmutable('2026-07-27T10:11:12.123Z'),
            'retentionClass' => 'regulated',
        ];
        $values[$field] = $value;

        try {
            new AuditEventInput(...array_values($values));
            $this->fail('Expected invalid '.$field.' to be rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array<string, mixed> */
    private static function nestedContext(int $depth): array
    {
        $value = ['leaf' => true];
        for ($i = 0; $i < $depth; $i++) {
            $value = ['level_'.$i => $value];
        }

        return $value;
    }

    /** @param class-string $class */
    private function assertConstructorFields(string $class, array $expected): void
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        self::assertNotNull($constructor);
        $this->assertSame($expected, array_map(static fn ($parameter): string => $parameter->getName(), $constructor->getParameters()));
    }
}
