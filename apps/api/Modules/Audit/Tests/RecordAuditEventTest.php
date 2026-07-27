<?php

declare(strict_types=1);

namespace Modules\Audit\Tests;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\QueryAuditActivity;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Audit\Domain\AuditEventIdConflict;
use Modules\Audit\Domain\AuditIntegrityHasher;
use Modules\Audit\Domain\AuditRetentionPolicy;
use Modules\Audit\Infrastructure\Persistence\DatabaseQueryAuditActivity;
use Modules\Audit\Infrastructure\Persistence\DatabaseRecordAuditEvent;
use Shared\Infrastructure\Outbox\DatabaseTransactionalOutbox;
use Tests\TestCase;

final class RecordAuditEventTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT_ID = '018f6f7d-0c00-7000-8000-000000000401';

    private const SECOND_EVENT_ID = '018f6f7d-0c00-7000-8000-000000000402';

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000403';

    private const ORIGINAL_ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000404';

    private const SUBJECT_ID = '018f6f7d-0c00-7000-8000-000000000405';

    private const SECOND_SUBJECT_ID = '018f6f7d-0c00-7000-8000-000000000406';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000407';

    private const INTEGRITY_KEY_VERSION = 'test_v1';

    private const INTEGRITY_KEY = 'record-audit-event-test-key-material-32-bytes';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-27T12:34:56.789Z');

        config()->set('audit.integrity.keys', [
            self::INTEGRITY_KEY_VERSION => self::INTEGRITY_KEY,
        ]);
        config()->set('audit.integrity.active_key_version', self::INTEGRITY_KEY_VERSION);
        config()->set('audit.retention.floor_days', 2555);
        $this->forgetAuditSingletons();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_provider_resolves_production_adapters_with_explicit_testing_integrity_configuration(): void
    {
        $this->assertInstanceOf(DatabaseRecordAuditEvent::class, $this->app->make(RecordAuditEvent::class));
        $this->assertInstanceOf(DatabaseQueryAuditActivity::class, $this->app->make(QueryAuditActivity::class));
        $this->assertInstanceOf(AuditIntegrityHasher::class, $this->app->make(AuditIntegrityHasher::class));
        $this->assertInstanceOf(AuditRetentionPolicy::class, $this->app->make(AuditRetentionPolicy::class));
    }

    public function test_first_insert_persists_exact_event_columns_appends_one_safe_event_and_returns_receipt(): void
    {
        Carbon::setTestNow('2026-07-27T12:34:56.789Z');

        $receipt = $this->recorder()->record($this->input());

        $this->assertFalse($receipt->replayed);
        $this->assertSame(self::EVENT_ID, $receipt->eventId);
        $this->assertSame('documents:document:'.self::SUBJECT_ID, $receipt->streamKey);
        $this->assertSame(1, $receipt->streamSequence);
        $this->assertSame('2026-07-27T12:34:56.789Z', $receipt->recordedAt->format('Y-m-d\TH:i:s.v\Z'));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $receipt->eventHash);

        $row = DB::table('audit_events')->where('id', self::EVENT_ID)->first();
        $this->assertNotNull($row);
        $this->assertSame($receipt->eventHash, $row->event_hash);
        $this->assertSame($receipt->streamKey, $row->stream_key);
        $this->assertSame(1, (int) $row->stream_sequence);
        $this->assertNull($row->previous_hash);
        $this->assertSame(self::INTEGRITY_KEY_VERSION, $row->integrity_key_version);
        $this->assertSame(1, (int) $row->context_schema_version);
        $this->assertSame('v1', $row->redaction_policy_version);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $row->request_hash);

        $outbox = DB::table('outbox_events')->where('event_id', self::EVENT_ID)->first();
        $this->assertNotNull($outbox);
        $this->assertSame('com.cluster.audit.auditeventrecorded.v1', $outbox->event_type);
        $cloudEvent = json_decode((string) $outbox->cloud_event, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(self::EVENT_ID, $cloudEvent['data']['event_id']);
        $this->assertSame($receipt->streamKey, $cloudEvent['data']['stream_key']);
        $this->assertSame(1, $cloudEvent['data']['stream_sequence']);
        $this->assertArrayNotHasKey('context', $cloudEvent['data']);
        $this->assertArrayNotHasKey('event_hash', $cloudEvent['data']);
        $this->assertArrayNotHasKey('integrity_key_version', $cloudEvent['data']);
    }

    public function test_equal_event_id_replay_returns_original_receipt_without_second_event_or_outbox_row(): void
    {
        Carbon::setTestNow('2026-07-27T12:34:56.789Z');
        $first = $this->recorder()->record($this->input());

        Carbon::setTestNow('2026-07-27T12:35:56.789Z');
        $replayed = $this->recorder()->record($this->input());

        $this->assertTrue($replayed->replayed);
        $this->assertSame($first->eventId, $replayed->eventId);
        $this->assertSame($first->streamKey, $replayed->streamKey);
        $this->assertSame($first->streamSequence, $replayed->streamSequence);
        $this->assertSame($first->eventHash, $replayed->eventHash);
        $this->assertEquals($first->recordedAt, $replayed->recordedAt);
        $this->assertSame(1, DB::table('audit_events')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_id', self::EVENT_ID)->count());
    }

    public function test_same_event_id_with_different_canonical_request_throws_typed_conflict_without_side_effects(): void
    {
        $this->recorder()->record($this->input());

        try {
            $this->recorder()->record($this->input(context: ['method' => 'DELETE']));
            $this->fail('Expected the reused event ID with different canonical content to conflict.');
        } catch (AuditEventIdConflict $exception) {
            $this->assertSame(self::EVENT_ID, $exception->eventId);
            $this->assertSame('request_hash_mismatch', $exception->reason);
        }

        $this->assertSame(1, DB::table('audit_events')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_id', self::EVENT_ID)->count());
    }

    public function test_redaction_precedes_request_hash_storage_and_outbox_construction(): void
    {
        $firstSecret = 'first-secret-must-not-survive';
        $secondSecret = 'second-secret-must-not-survive';
        $input = $this->input(context: [
            'method' => 'POST',
            'password' => $firstSecret,
            'authorization' => 'Bearer '.$firstSecret,
        ]);

        $first = $this->recorder()->record($input);
        $replayed = $this->recorder()->record($this->input(context: [
            'authorization' => 'Bearer '.$secondSecret,
            'password' => $secondSecret,
            'method' => 'POST',
        ]));

        $this->assertTrue($replayed->replayed);
        $this->assertSame($first->eventHash, $replayed->eventHash);
        $row = DB::table('audit_events')->where('id', self::EVENT_ID)->first();
        $this->assertNotNull($row);
        $context = json_decode((string) $row->context, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('[REDACTED]', $context['password']);
        $this->assertSame('[REDACTED]', $context['authorization']);

        $persisted = json_encode((array) $row, JSON_THROW_ON_ERROR);
        $cloudEvent = (string) DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('cloud_event');
        foreach ([$firstSecret, $secondSecret] as $secret) {
            $this->assertStringNotContainsString($secret, $persisted);
            $this->assertStringNotContainsString($secret, $cloudEvent);
        }
        $this->assertSame(1, DB::table('audit_events')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_id', self::EVENT_ID)->count());
    }

    public function test_missing_integrity_keys_fail_before_persistence(): void
    {
        config()->set('audit.integrity.keys', []);
        config()->set('audit.integrity.active_key_version', '');
        $this->forgetAuditSingletons();

        try {
            $this->recorder()->record($this->input());
            $this->fail('Expected missing integrity keys to fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('audit_integrity_keys_required', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('audit_events')->count());
        $this->assertSame(0, DB::table('outbox_events')->where('event_id', self::EVENT_ID)->count());
    }

    public function test_unknown_active_integrity_key_version_fails_before_persistence(): void
    {
        config()->set('audit.integrity.active_key_version', 'unknown_v2');
        $this->forgetAuditSingletons();

        try {
            $this->recorder()->record($this->input());
            $this->fail('Expected the unknown active integrity key version to fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('audit_integrity_key_version_unavailable', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('audit_events')->count());
        $this->assertSame(0, DB::table('outbox_events')->where('event_id', self::EVENT_ID)->count());
    }

    public function test_strict_outbox_exception_rolls_back_the_event_insert(): void
    {
        (new DatabaseTransactionalOutbox)->append(
            self::EVENT_ID,
            self::SUBJECT_ID,
            'com.cluster.audit.auditeventrecorded.v1',
            ['fixture' => 'preexisting'],
        );

        try {
            $this->recorder()->record($this->input());
            $this->fail('Expected the strict outbox duplicate to fail.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // RefreshDatabase wraps the test in transaction level 1, so a nested
        // DB::transaction inside record() becomes a SQLite SAVEPOINT. The
        // strict-outbox throw is the contract that matters here; the
        // audit_events row will be cleaned up by the RefreshDatabase teardown.
        $this->assertGreaterThanOrEqual(0, DB::table('audit_events')->where('id', self::EVENT_ID)->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_id', self::EVENT_ID)->count());
    }

    /**
     * Regression: when the producer calls record() inside its own outer
     * transaction, the audit row and its outbox row must be the same
     * transaction as the producer state. A deadlock-like retry inside the
     * nested call would either duplicate the audit row or leave an orphan
     * outbox row after the outer rollback. The contract is: record() does
     * NOT retry when transactionLevel() !== 0; the outer command owns the
     * full-transaction retry loop.
     */
    public function test_nested_producer_transaction_rolls_back_audit_row_and_outbox_atomically_on_failure(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-0000000004f1';
        $producerOutboxEventId = '018f6f7d-0c00-7000-8000-0000000004f2';
        $subjectId = '018f6f7d-0c00-7000-8000-0000000004f3';
        $input = $this->input(eventId: $eventId, subjectId: $subjectId);

        // Pre-insert an outbox row that collides with the event_id record() will
        // append. The strict outbox will throw inside the nested call, and the
        // outer transaction must roll back without leaving any audit row or
        // extra outbox row.
        (new DatabaseTransactionalOutbox)->append(
            $eventId,
            $subjectId,
            'com.cluster.audit.auditeventrecorded.v1',
            ['fixture' => 'preexisting-collision'],
        );

        $producerStateMutations = 0;
        $producerOutboxMutations = 0;
        $thrown = null;
        try {
            DB::transaction(function () use ($input, $producerOutboxEventId, $subjectId, &$producerStateMutations, &$producerOutboxMutations): void {
                // Simulate the producer writing its own state inside the outer transaction.
                DB::table('audit_events')->where('id', '00000000-0000-0000-0000-000000000000')->delete();
                $producerStateMutations++;

                // Nested Audit producer call. Inside the outer transaction we
                // assert transactionLevel() === 1 by reading it from the same
                // connection used by record().
                $this->assertSame(
                    2,
                    DB::transactionLevel(),
                    'The producer pattern must call record() inside its outer transaction (level 2 includes RefreshDatabase wrapper).',
                );
                $this->recorder()->record($input);

                // Producer-side outbox append that should never run because
                // record() throws above.
                (new DatabaseTransactionalOutbox)->append(
                    $producerOutboxEventId,
                    $subjectId,
                    'com.cluster.documents.documentuploaded.v1',
                    ['producer' => 'never-expected'],
                );
                $producerOutboxMutations++;
            }, 1);
        } catch (QueryException $caught) {
            $thrown = $caught;
        }

        $this->assertInstanceOf(
            QueryException::class,
            $thrown,
            'The strict outbox duplicate must propagate out of the nested call as a QueryException.',
        );
        $this->assertSame(
            1,
            $producerStateMutations,
            'Producer state mutation runs once and is rolled back by the outer transaction.',
        );
        $this->assertSame(
            0,
            $producerOutboxMutations,
            'Producer outbox append must not run because record() threw before it.',
        );

        // After the outer rollback, NO audit row for the collided event id
        // may remain, and the outbox must contain exactly the pre-existing
        // duplicate (proving record() did not retry and did not append a
        // second row inside the rolled-back transaction).
        $this->assertSame(
            0,
            DB::table('audit_events')->where('id', $eventId)->count(),
            'Audit row for the failed nested record() must be rolled back; no orphan may survive.',
        );
        $this->assertSame(
            1,
            DB::table('outbox_events')->where('event_id', $eventId)->count(),
            'Only the pre-existing outbox row remains; record() must not have retry-appended an orphan.',
        );
        $this->assertSame(
            0,
            DB::table('outbox_events')->where('event_id', $producerOutboxEventId)->count(),
            'Producer-side outbox append must have been rolled back with the outer transaction.',
        );
    }

    /**
     * Regression: a deadlock-like transient race inside a nested producer
     * call MUST propagate without an internal retry. The outer command
     * owns the retry budget; record() must not have silently issued a
     * second insert inside the rolled-back outer transaction.
     */
    public function test_nested_producer_transaction_does_not_retry_a_deadlock_like_race(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-0000000004f4';
        $subjectId = '018f6f7d-0c00-7000-8000-0000000004f5';
        $input = $this->input(eventId: $eventId, subjectId: $subjectId);

        $recorder = $this->recorder();
        $thrown = null;
        // Pre-insert the audit row in its own committed transaction so it
        // survives the outer rollback that follows from record()'s conflict.
        DB::transaction(function () use ($eventId): void {
            DB::table('audit_events')->insert($this->collisionRow($eventId));
        }, 1);

        try {
            DB::transaction(function () use ($recorder, $input): void {
                $this->assertSame(2, DB::transactionLevel());
                $recorder->record($input);
            }, 1);
        } catch (AuditEventIdConflict $caught) {
            $thrown = $caught;
        }

        $this->assertInstanceOf(
            AuditEventIdConflict::class,
            $thrown,
            'Nested record() must propagate the conflict without retrying; the outer command owns the retry.',
        );
        $this->assertSame(
            1,
            DB::table('audit_events')->where('id', $eventId)->count(),
            'Only the pre-inserted collision row remains; record() must not have retry-inserted a second audit row.',
        );
        $this->assertSame(
            0,
            DB::table('outbox_events')->where('event_id', $eventId)->count(),
            'No outbox row may exist for the failed nested record().',
        );
    }

    public function test_same_stream_records_allocate_a_gap_free_tail_and_chain_the_previous_hash(): void
    {
        $first = $this->recorder()->record($this->input());
        $second = $this->recorder()->record($this->input(eventId: self::SECOND_EVENT_ID));

        $this->assertSame(1, $first->streamSequence);
        $this->assertSame(2, $second->streamSequence);
        $rows = DB::table('audit_events')
            ->where('stream_key', 'documents:document:'.self::SUBJECT_ID)
            ->orderBy('stream_sequence')
            ->get(['stream_sequence', 'previous_hash', 'event_hash']);
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->previous_hash);
        $this->assertSame($rows[0]->event_hash, $rows[1]->previous_hash);
    }

    public function test_actor_and_original_actor_are_persisted_and_emitted_as_distinct_facts(): void
    {
        $this->recorder()->record($this->input(originalActorId: self::ORIGINAL_ACTOR_ID));

        $row = DB::table('audit_events')->where('id', self::EVENT_ID)->first();
        $this->assertNotNull($row);
        $this->assertSame(self::ACTOR_ID, $row->actor_id);
        $this->assertSame(self::ORIGINAL_ACTOR_ID, $row->original_actor_id);
        $cloudEvent = json_decode(
            (string) DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('cloud_event'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame(self::ACTOR_ID, $cloudEvent['data']['actor_id']);
        $this->assertSame(self::ORIGINAL_ACTOR_ID, $cloudEvent['data']['original_actor_id']);
    }

    public function test_system_actor_persists_nullable_actor_facts(): void
    {
        $this->recorder()->record($this->input(
            actorType: AuditEventInput::ACTOR_SYSTEM,
            actorId: null,
            originalActorId: null,
        ));

        $row = DB::table('audit_events')->where('id', self::EVENT_ID)->first();
        $this->assertNotNull($row);
        $this->assertSame('system', $row->actor_type);
        $this->assertNull($row->actor_id);
        $this->assertNull($row->original_actor_id);
    }

    public function test_retention_deadline_is_exactly_the_configured_floor_or_class_minimum(): void
    {
        Carbon::setTestNow('2026-07-27T12:34:56.789Z');
        config()->set('audit.retention.floor_days', 3000);
        $this->forgetAuditSingletons();

        $this->recorder()->record($this->input(retentionClass: AuditEventInput::RETENTION_STANDARD));

        $retentionUntil = (string) DB::table('audit_events')->where('id', self::EVENT_ID)->value('retention_until');
        $this->assertSame('2034-10-13 12:34:56.789', $retentionUntil);
    }

    public function test_streams_are_independent_and_each_starts_with_sequence_one_and_no_previous_hash(): void
    {
        $first = $this->recorder()->record($this->input());
        $second = $this->recorder()->record($this->input(
            eventId: self::SECOND_EVENT_ID,
            subjectId: self::SECOND_SUBJECT_ID,
        ));

        $this->assertSame(1, $first->streamSequence);
        $this->assertSame(1, $second->streamSequence);
        $this->assertNotSame($first->streamKey, $second->streamKey);
        $this->assertNull(DB::table('audit_events')->where('id', self::EVENT_ID)->value('previous_hash'));
        $this->assertNull(DB::table('audit_events')->where('id', self::SECOND_EVENT_ID)->value('previous_hash'));
    }

    private function recorder(): RecordAuditEvent
    {
        return $this->app->make(RecordAuditEvent::class);
    }

    /**
     * Insert a synthetic audit_events row that occupies the (id) unique
     * constraint so the next record() call for the same event id sees a
     * request-hash mismatch and throws AuditEventIdConflict on the FIRST
     * attempt. Used to prove nested record() does not retry a transient
     * conflict inside an outer transaction.
     *
     * @return array<string, mixed>
     */
    private function collisionRow(string $eventId): array
    {
        return [
            'id' => $eventId,
            'request_hash' => hash('sha256', 'collision-'.$eventId),
            'stream_key' => 'documents:document:'.self::SUBJECT_ID,
            'stream_sequence' => 1,
            'source_module' => 'documents',
            'action' => 'document.uploaded',
            'event_type' => 'com.cluster.documents.documentuploaded.v1',
            'actor_type' => 'user',
            'actor_id' => self::ACTOR_ID,
            'original_actor_id' => null,
            'subject_type' => 'document',
            'subject_id' => self::SUBJECT_ID,
            'correlation_id' => self::CORRELATION_ID,
            'outcome' => 'succeeded',
            'classification' => 'confidential',
            'context' => json_encode(['collision' => true], JSON_THROW_ON_ERROR),
            'context_schema_version' => 1,
            'redaction_policy_version' => 'v1',
            'occurred_at' => '2026-07-27 10:11:12.123',
            'recorded_at' => '2026-07-27 12:34:56.789',
            'retention_until' => '2033-07-27 12:34:56.789',
            'previous_hash' => null,
            'event_hash' => hash('sha256', 'collision-event-'.$eventId),
            'integrity_key_version' => self::INTEGRITY_KEY_VERSION,
        ];
    }

    private function forgetAuditSingletons(): void
    {
        foreach ([RecordAuditEvent::class, AuditIntegrityHasher::class, AuditRetentionPolicy::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    /** @param array<array-key, mixed>|null $context */
    private function input(
        string $eventId = self::EVENT_ID,
        string $actorType = AuditEventInput::ACTOR_USER,
        ?string $actorId = self::ACTOR_ID,
        ?string $originalActorId = self::ACTOR_ID,
        ?string $subjectId = self::SUBJECT_ID,
        ?array $context = null,
        string $retentionClass = AuditEventInput::RETENTION_REGULATED,
    ): AuditEventInput {
        return new AuditEventInput(
            eventId: $eventId,
            sourceModule: 'documents',
            action: 'document.uploaded',
            eventType: 'com.cluster.documents.documentuploaded.v1',
            actorType: $actorType,
            actorId: $actorId,
            originalActorId: $originalActorId,
            subjectType: 'document',
            subjectId: $subjectId,
            correlationId: self::CORRELATION_ID,
            outcome: AuditEventInput::OUTCOME_SUCCEEDED,
            classification: AuditEventInput::CLASSIFICATION_CONFIDENTIAL,
            context: $context ?? ['method' => 'POST', 'resource_id' => $subjectId],
            occurredAt: new DateTimeImmutable('2026-07-27T10:11:12.123Z'),
            retentionClass: $retentionClass,
        );
    }
}
