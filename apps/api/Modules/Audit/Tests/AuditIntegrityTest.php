<?php

declare(strict_types=1);

namespace Modules\Audit\Tests;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\AuditEventReceipt;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Audit\Domain\AuditEventCanonicalizer;
use Modules\Audit\Domain\AuditIntegrityHasher;
use Modules\Audit\Features\VerifyAuditIntegrity\Handler\AuditIdempotencyMismatch;
use Modules\Audit\Features\VerifyAuditIntegrity\Handler\VerifyAuditIntegrityHandler;
use Modules\Audit\Features\VerifyAuditIntegrity\Http\VerifyAuditIntegrityController;
use Modules\Audit\Http\AuditApi;
use Modules\Audit\Infrastructure\Persistence\AuditIntegrityRepository;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\PrincipalContext;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Tests\TestCase;

final class AuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT_ID = '018f6f7d-0c00-7000-8000-000000000601';

    public const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000602';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000603';

    public const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000604';

    public const UNIT_ID = '018f6f7d-0c00-7000-8000-000000000605';

    private const STREAM_KEY = 'documents:document:'.self::SUBJECT_ID;

    private const ANOTHER_STREAM_KEY = 'documents:document:018f6f7d-0c00-7000-8000-000000000699';

    private const INTEGRITY_KEY_VERSION = 'integrity_v1';

    private const INTEGRITY_KEY = 'audit-integrity-test-key-material-32-bytes';

    private const SECOND_KEY_VERSION = 'integrity_v2';

    private const SECOND_KEY = 'audit-integrity-test-secondary-key-32-bytes-2';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-27T12:34:56.789Z');

        config()->set('audit.integrity.keys', [
            self::INTEGRITY_KEY_VERSION => self::INTEGRITY_KEY,
            self::SECOND_KEY_VERSION => self::SECOND_KEY,
        ]);
        config()->set('audit.integrity.active_key_version', self::INTEGRITY_KEY_VERSION);
        config()->set('audit.retention.floor_days', 2555);

        $this->app->instance(ResolvePrincipalContext::class, new IntegrityTestPrincipalResolver);
        $this->app->instance(DecideAccess::class, new IntegrityTestDecisionEngine);
        $this->app->forgetInstance(AuditIntegrityRepository::class);
        $this->app->forgetInstance(VerifyAuditIntegrityHandler::class);

        Route::middleware(IntegrityTestSessionMiddleware::class)
            ->post(AuditApi::ROUTE_VERIFY_INTEGRITY, VerifyAuditIntegrityController::class)
            ->name('audit.integrity-test.verify');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_valid_chain_writes_immutable_verified_checkpoint_with_anchor_and_count(): void
    {
        $this->recordThreeEvents();

        $result = $this->repository()->verifyStream(
            verificationId: (string) Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
        );

        $this->assertSame(self::STREAM_KEY, $result['stream_key']);
        $this->assertSame(1, $result['first_sequence']);
        $this->assertSame(3, $result['last_sequence']);
        $this->assertSame(3, $result['verified_event_count']);
        $this->assertSame(AuditIntegrityRepository::STATUS_VERIFIED, $result['status']);
        $this->assertSame(self::INTEGRITY_KEY_VERSION, $result['integrity_key_version']);
        $this->assertNotSame('', $result['checkpoint_id']);

        $checkpoint = DB::table('audit_integrity_checkpoints')
            ->where('id', $result['checkpoint_id'])
            ->first();
        $this->assertNotNull($checkpoint);
        $this->assertSame('verification', $checkpoint->kind);
        $this->assertSame('verified', $checkpoint->status);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $checkpoint->terminal_event_hash);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $checkpoint->checkpoint_hash);

        $boundary = DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->orderByDesc('stream_sequence')
            ->first();
        $this->assertSame($boundary->event_hash, $checkpoint->terminal_event_hash);
    }

    public function test_changed_context_is_detected_and_violation_emits_outbox_atomically(): void
    {
        $first = $this->recorder()->record($this->input(self::firstEventId(), ['method' => 'POST']));
        $this->recorder()->record($this->input(self::secondEventId(), ['method' => 'POST']));

        DB::statement('DROP TRIGGER IF EXISTS audit_events_update_prevent');
        $tampered = DB::table('audit_events')->where('id', $first->eventId)->first();
        DB::table('audit_events')->where('id', $tampered->id)->update([
            'context' => json_encode(['method' => 'DELETE'], JSON_THROW_ON_ERROR),
        ]);
        $this->recreateSqliteAppendOnlyGuards();

        $result = $this->repository()->verifyStream(
            verificationId: (string) Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
        );

        $this->assertSame(AuditIntegrityRepository::STATUS_VIOLATED, $result['status']);
        $this->assertSame(0, $result['verified_event_count']);

        $checkpoint = DB::table('audit_integrity_checkpoints')
            ->where('id', $result['checkpoint_id'])
            ->first();
        $this->assertNotNull($checkpoint);
        $this->assertSame('violated', $checkpoint->status);

        $outbox = DB::table('outbox_events')
            ->where('event_type', 'com.cluster.audit.auditintegrityviolationdetected.v1')
            ->first();
        $this->assertNotNull($outbox, 'Violation outbox event must be emitted atomically with the checkpoint.');
        $payload = json_decode((string) $outbox->cloud_event, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('violated', $payload['data']['integrity_status']);
        $this->assertSame(self::STREAM_KEY, $payload['data']['stream_key']);
        $this->assertSame(0, $payload['data']['verified_event_count']);
        $this->assertSame(1, $payload['data']['first_mismatch_stream_sequence']);
        $this->assertArrayNotHasKey('event_hash', $payload['data']);
        $this->assertArrayNotHasKey('previous_hash', $payload['data']);
        $this->assertArrayNotHasKey('integrity_key_version', $payload['data']);
    }

    public function test_repeated_unchanged_violation_reuses_checkpoint_without_duplicate_outbox(): void
    {
        $first = $this->recorder()->record($this->input(self::firstEventId(), ['method' => 'POST']));
        $this->recorder()->record($this->input(self::secondEventId(), ['method' => 'POST']));

        DB::statement('DROP TRIGGER IF EXISTS audit_events_update_prevent');
        DB::table('audit_events')->where('id', $first->eventId)->update([
            'context' => json_encode(['method' => 'DELETE'], JSON_THROW_ON_ERROR),
        ]);
        $this->recreateSqliteAppendOnlyGuards();

        $firstResult = $this->repository()->verifyStream(
            verificationId: (string) Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
        );
        $secondResult = $this->repository()->verifyStream(
            verificationId: (string) Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
        );

        $this->assertSame($firstResult['checkpoint_id'], $secondResult['checkpoint_id']);
        $this->assertSame(1, DB::table('audit_integrity_checkpoints')->where('kind', 'verification')->count());
        $this->assertSame(1, DB::table('outbox_events')
            ->where('event_type', 'com.cluster.audit.auditintegrityviolationdetected.v1')
            ->count());
    }

    public function test_fresh_violation_after_prior_verified_checkpoint_writes_immutable_violated_row_and_outbox(): void
    {
        $this->recordThreeEvents();

        $verified = $this->invokeHandler(['stream_key' => self::STREAM_KEY], 'clean-idempotency-key');
        $this->assertSame(201, $verified['status']);
        $this->assertFalse($verified['replayed']);

        $tampered = DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->orderBy('stream_sequence')
            ->first();
        DB::statement('DROP TRIGGER IF EXISTS audit_events_update_prevent');
        DB::table('audit_events')->where('id', $tampered->id)->update([
            'context' => json_encode(['method' => 'PATCH'], JSON_THROW_ON_ERROR),
        ]);

        try {
            $response = $this->postJson(
                AuditApi::ROUTE_VERIFY_INTEGRITY,
                ['stream_key' => self::STREAM_KEY],
                $this->headers(idempotencyKey: 'fresh-violation-key'),
            )->assertStatus(409)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'https://cluster.example/problems/audit-integrity-violation');

            $content = $response->getContent();
            $this->assertStringNotContainsString('event_hash', $content);
            $this->assertStringNotContainsString('previous_hash', $content);
            $this->assertStringNotContainsString('integrity_key_version', $content);

            $this->assertSame(1, DB::table('audit_integrity_checkpoints')
                ->where('kind', 'verification')
                ->where('status', 'verified')
                ->count());
            $this->assertSame(1, DB::table('audit_integrity_checkpoints')
                ->where('kind', 'verification')
                ->where('status', 'violated')
                ->count());
            $this->assertSame(1, DB::table('outbox_events')
                ->where('event_type', 'com.cluster.audit.auditintegrityviolationdetected.v1')
                ->count());
            $this->assertSame(1, DB::table('audit_idempotency_keys')
                ->where('operation', VerifyAuditIntegrityHandler::OPERATION)
                ->where('response_status', 409)
                ->count());
        } finally {
            $this->recreateSqliteAppendOnlyGuards();
        }
    }

    public function test_replay_with_same_idempotency_key_after_violation_returns_409_without_repeating_outbox(): void
    {
        $this->recordThreeEvents();

        $tampered = DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->orderBy('stream_sequence')
            ->first();
        DB::statement('DROP TRIGGER IF EXISTS audit_events_update_prevent');
        DB::table('audit_events')->where('id', $tampered->id)->update([
            'context' => json_encode(['method' => 'PATCH'], JSON_THROW_ON_ERROR),
        ]);

        try {
            $first = $this->invokeHandler(['stream_key' => self::STREAM_KEY], 'shared-violation-replay');
            $second = $this->invokeHandler(['stream_key' => self::STREAM_KEY], 'shared-violation-replay');

            $this->assertSame(409, $first['status']);
            $this->assertSame(409, $second['status']);
            $this->assertFalse($first['replayed']);
            $this->assertTrue($second['replayed']);
            $this->assertSame($first['result'], $second['result']);

            $this->assertSame(1, DB::table('audit_integrity_checkpoints')
                ->where('kind', 'verification')
                ->count());
            $this->assertSame(1, DB::table('outbox_events')
                ->where('event_type', 'com.cluster.audit.auditintegrityviolationdetected.v1')
                ->count());
            $this->assertSame(1, DB::table('audit_idempotency_keys')
                ->where('operation', VerifyAuditIntegrityHandler::OPERATION)
                ->count());
        } finally {
            $this->recreateSqliteAppendOnlyGuards();
        }
    }

    public function test_removed_middle_row_breaks_chain_and_records_zero_terminal_count(): void
    {
        [$first, $second, $third] = $this->recordThreeEvents();

        // Bypass the append-only guards to simulate tampering.
        DB::statement('DROP TRIGGER IF EXISTS audit_events_update_prevent');
        DB::statement('DELETE FROM audit_events WHERE id = ?', [$second->eventId]);

        $remaining = DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->orderBy('stream_sequence')
            ->get();

        $rows = $remaining->values()->all();
        for ($i = 1; $i < count($rows); $i++) {
            $previous = $rows[$i - 1];
            DB::table('audit_events')->where('id', $rows[$i]->id)->update([
                'previous_hash' => (string) $previous->event_hash,
            ]);
        }

        try {
            $result = $this->repository()->verifyStream(
                verificationId: (string) Str::uuid7(),
                correlationId: self::CORRELATION_ID,
                streamKey: self::STREAM_KEY,
                actorId: self::ACTOR_ID,
            );
            $this->assertSame(AuditIntegrityRepository::STATUS_VIOLATED, $result['status']);
            $this->assertSame(1, $result['verified_event_count']);
            $this->assertSame(3, $result['last_sequence']);
        } finally {
            $this->recreateSqliteAppendOnlyGuards();
        }
    }

    public function test_altered_previous_hash_row_breaks_chain_with_no_keys_or_hashes_in_checkpoint_or_payload(): void
    {
        $this->recordThreeEvents();

        $middle = DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->orderBy('stream_sequence')
            ->skip(1)
            ->first();
        DB::statement('DROP TRIGGER IF EXISTS audit_events_update_prevent');
        DB::table('audit_events')->where('id', $middle->id)->update([
            'previous_hash' => str_repeat('a', 64),
        ]);

        try {
            $result = $this->repository()->verifyStream(
                verificationId: (string) Str::uuid7(),
                correlationId: self::CORRELATION_ID,
                streamKey: self::STREAM_KEY,
                actorId: self::ACTOR_ID,
            );
            $this->assertSame(AuditIntegrityRepository::STATUS_VIOLATED, $result['status']);
            $this->assertSame(1, $result['verified_event_count']);

            $checkpoint = DB::table('audit_integrity_checkpoints')
                ->where('id', $result['checkpoint_id'])
                ->first();
            $payload = json_decode((string) $checkpoint->details, true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame('chain_mismatch', $payload['reason']);
            $this->assertSame(2, $payload['first_mismatch_stream_sequence']);
            $this->assertArrayNotHasKey('previous_hash', $payload);
            $this->assertArrayNotHasKey('event_hash', $payload);
            $this->assertArrayNotHasKey('integrity_key_version', $payload);
            $this->assertArrayNotHasKey('context', $payload);

            $outbox = DB::table('outbox_events')
                ->where('event_type', 'com.cluster.audit.auditintegrityviolationdetected.v1')
                ->first();
            $this->assertNotNull($outbox);
            $cloudEvent = json_decode((string) $outbox->cloud_event, true, 32, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('event_hash', $cloudEvent['data']);
            $this->assertArrayNotHasKey('previous_hash', $cloudEvent['data']);
            $this->assertArrayNotHasKey('integrity_key_version', $cloudEvent['data']);
        } finally {
            $this->recreateSqliteAppendOnlyGuards();
        }
    }

    public function test_unavailable_historical_integrity_key_version_returns_safe_503_without_persistence(): void
    {
        $this->recordThreeEvents();

        config()->set('audit.integrity.keys', [
            self::INTEGRITY_KEY_VERSION => self::INTEGRITY_KEY,
        ]);
        $this->app->forgetInstance(AuditIntegrityHasher::class);
        $this->app->forgetInstance(AuditIntegrityRepository::class);

        $middle = DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->orderBy('stream_sequence')
            ->skip(1)
            ->first();
        DB::statement('DROP TRIGGER IF EXISTS audit_events_update_prevent');
        DB::table('audit_events')->where('id', $middle->id)->update([
            'integrity_key_version' => self::SECOND_KEY_VERSION,
        ]);

        try {
            $response = $this->postJson(
                AuditApi::ROUTE_VERIFY_INTEGRITY,
                ['stream_key' => self::STREAM_KEY],
                $this->headers(idempotencyKey: 'historical-key-unavailable'),
            )->assertStatus(503)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'https://cluster.example/problems/audit-runtime-unavailable')
                ->assertJsonPath('detail', 'Audit integrity verification is temporarily unavailable.');

            $content = $response->getContent();
            $this->assertStringNotContainsString('integrity_key_version', $content);
            $this->assertStringNotContainsString(self::SECOND_KEY_VERSION, $content);

            $this->assertSame(0, DB::table('audit_integrity_checkpoints')->count());
            $this->assertSame(0, DB::table('outbox_events')
                ->where('event_type', 'com.cluster.audit.auditintegrityviolationdetected.v1')
                ->count());
            $this->assertSame(3, DB::table('audit_events')
                ->where('stream_key', self::STREAM_KEY)->count());
        } finally {
            $this->recreateSqliteAppendOnlyGuards();
        }
    }

    public function test_bounded_range_verification_returns_verified_for_clean_window(): void
    {
        $this->recordThreeEvents();

        $result = $this->repository()->verifyRange(
            verificationId: (string) Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
            firstSequence: 2,
            lastSequence: 3,
        );

        $this->assertSame(AuditIntegrityRepository::STATUS_VERIFIED, $result['status']);
        $this->assertSame(2, $result['first_sequence']);
        $this->assertSame(3, $result['last_sequence']);
        $this->assertSame(2, $result['verified_event_count']);
    }

    public function test_existing_immutable_checkpoint_blocks_checkpoint_update_path(): void
    {
        $this->recordThreeEvents();
        $first = $this->repository()->verifyStream(
            verificationId: (string) Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
        );

        $checkpoint = DB::table('audit_integrity_checkpoints')->where('id', $first['checkpoint_id'])->first();
        $originalHash = $checkpoint->checkpoint_hash;
        $originalTerminal = $checkpoint->terminal_event_hash;

        $caught = null;
        try {
            DB::table('audit_integrity_checkpoints')->where('id', $first['checkpoint_id'])->update([
                'terminal_event_hash' => str_repeat('b', 64),
                'checkpoint_hash' => str_repeat('c', 64),
            ]);
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertStringContainsString('audit_integrity_checkpoints_immutable', strtolower($caught->getMessage()));

        $reloaded = DB::table('audit_integrity_checkpoints')->where('id', $first['checkpoint_id'])->first();
        $this->assertSame($originalHash, $reloaded->checkpoint_hash);
        $this->assertSame($originalTerminal, $reloaded->terminal_event_hash);
    }

    public function test_immutable_checkpoints_reject_delete_path(): void
    {
        $this->recordThreeEvents();
        $first = $this->repository()->verifyStream(
            verificationId: (string) Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
        );

        $caught = null;
        try {
            DB::table('audit_integrity_checkpoints')
                ->where('id', $first['checkpoint_id'])
                ->delete();
        } catch (QueryException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertStringContainsString('audit_integrity_checkpoints_immutable', strtolower($caught->getMessage()));
        $this->assertNotNull(DB::table('audit_integrity_checkpoints')->where('id', $first['checkpoint_id'])->first());
    }

    public function test_equal_idempotent_replay_returns_stored_body_status_and_etag_without_redoing_verification(): void
    {
        $this->recordThreeEvents();

        $first = $this->invokeHandler(['stream_key' => self::STREAM_KEY], 'fixed-replay-key');
        $second = $this->invokeHandler(['stream_key' => self::STREAM_KEY], 'fixed-replay-key');

        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['result'], $second['result']);
        $this->assertSame($first['etag'], $second['etag']);

        $this->assertSame(1, DB::table('audit_idempotency_keys')->count());

        $checkpoints = DB::table('audit_integrity_checkpoints')
            ->where('kind', 'verification')
            ->count();
        $this->assertSame(1, $checkpoints, 'Replay must not write a duplicate checkpoint.');
        $this->assertSame(3, DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count());
    }

    public function test_mismatched_idempotency_replay_returns_typed_409_conflict_without_storing_new_response(): void
    {
        $this->recordThreeEvents();

        $this->invokeHandler(['stream_key' => self::STREAM_KEY], 'shared-key');

        $caught = null;
        try {
            $this->invokeHandler(
                ['stream_key' => self::ANOTHER_STREAM_KEY],
                'shared-key',
            );
        } catch (AuditIdempotencyMismatch $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'Replay with a different body must surface a typed 409 conflict.');
        $this->assertSame('audit_idempotency_mismatch', $caught->getMessage());
        $this->assertSame(1, DB::table('audit_idempotency_keys')->count());
        $this->assertSame(1, DB::table('audit_integrity_checkpoints')->count());
    }

    public function test_recorder_outbox_failure_rolls_back_verification_checkpoint_and_idempotency(): void
    {
        $this->recordThreeEvents();
        $recordedOutboxCount = DB::table('outbox_events')
            ->where('event_type', 'com.cluster.audit.auditeventrecorded.v1')
            ->count();

        $brokenRecorder = new class implements RecordAuditEvent
        {
            public function record(AuditEventInput $input): AuditEventReceipt
            {
                throw new \RuntimeException('audit_recorder_simulated_failure');
            }
        };
        $this->app->instance(RecordAuditEvent::class, $brokenRecorder);
        $this->app->forgetInstance(VerifyAuditIntegrityHandler::class);

        $caught = null;
        try {
            $this->invokeHandler(['stream_key' => self::STREAM_KEY], 'recorder-failure-key');
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertSame('audit_recorder_simulated_failure', $caught->getMessage());

        $this->assertSame(0, DB::table('audit_integrity_checkpoints')
            ->where('kind', 'verification')
            ->count());
        $this->assertSame(0, DB::table('audit_idempotency_keys')->count());
        $this->assertSame($recordedOutboxCount, DB::table('outbox_events')
            ->where('event_type', 'com.cluster.audit.auditeventrecorded.v1')
            ->count());
        $this->assertSame(3, DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count());
    }

    public function test_post_handler_returns_safe_problem_for_unauthenticated_calls(): void
    {
        $response = $this->postJson(
            AuditApi::ROUTE_VERIFY_INTEGRITY,
            ['stream_key' => self::STREAM_KEY],
            ['Idempotency-Key' => 'unauthenticated', 'X-Test-Audit-Authenticated' => '0'],
        );
        $response->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/authentication-required')
            ->assertJsonMissingPath('errors')
            ->assertJsonMissingPath('stream_key');
        $this->assertSame([], $this->decisionCalls());
    }

    public function test_post_handler_returns_safe_problem_for_forbidden_calls(): void
    {
        $this->disableCapability();

        $response = $this->postJson(
            AuditApi::ROUTE_VERIFY_INTEGRITY,
            ['stream_key' => self::STREAM_KEY],
            $this->headers(),
        );

        $response->assertForbidden()
            ->assertJsonPath('type', 'https://cluster.example/problems/access-denied')
            ->assertJsonMissingPath('stream_key')
            ->assertJsonMissingPath('errors');
    }

    public function test_post_handler_rejects_missing_correlation_or_idempotency_key_with_safe_problem(): void
    {
        $this->postJson(
            AuditApi::ROUTE_VERIFY_INTEGRITY,
            ['stream_key' => self::STREAM_KEY],
            [
                'X-Test-Audit-Authenticated' => '1',
                'Idempotency-Key' => 'present',
            ],
        )->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-correlation-id');

        $this->postJson(
            AuditApi::ROUTE_VERIFY_INTEGRITY,
            ['stream_key' => self::STREAM_KEY],
            ['X-Correlation-ID' => self::CORRELATION_ID, 'X-Test-Audit-Authenticated' => '1'],
        )->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-idempotency-key')
            ->assertJsonMissingPath('Idempotency-Key')
            ->assertJsonMissingPath('errors');
    }

    public function test_post_handler_rejects_unknown_query_and_malformed_stream_key(): void
    {
        $this->postJson(
            AuditApi::ROUTE_VERIFY_INTEGRITY.'?unsupported=1',
            ['stream_key' => self::STREAM_KEY],
            $this->headers(),
        )->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-query-parameter')
            ->assertJsonMissing(['unsupported' => '1']);

        $this->postJson(
            AuditApi::ROUTE_VERIFY_INTEGRITY,
            [],
            $this->headers(),
        )->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-stream-key')
            ->assertJsonMissingPath('stream_key');
    }

    public function test_post_handler_rejects_malformed_stream_key_full_grammar_with_400(): void
    {
        $this->postJson(
            AuditApi::ROUTE_VERIFY_INTEGRITY,
            ['stream_key' => 'documents:not-a-uuid:global-ish'],
            $this->headers(),
        )->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-stream-key')
            ->assertJsonMissingPath('stream_key');

        $this->postJson(
            AuditApi::ROUTE_VERIFY_INTEGRITY,
            ['stream_key' => 'documents:document:NOT-A-UUID'],
            $this->headers(),
        )->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-stream-key');
    }

    public function test_post_handler_returns_422_range_too_large_when_stream_exceeds_max_verification_window(): void
    {
        $this->recordThreeEvents();

        $template = (array) DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->orderByDesc('stream_sequence')
            ->first();

        $batch = [];
        for ($sequence = 4; $sequence <= AuditIntegrityRepository::MAX_VERIFICATION_RANGE + 1; $sequence++) {
            $previousHash = hash('sha256', 'oversized-stream-'.(string) ($sequence - 1));
            $eventHash = hash('sha256', 'oversized-stream-'.(string) $sequence);
            $batch[] = [
                ...$template,
                'id' => '018f6f7d-0c00-7001-8000-'.str_pad((string) $sequence, 12, '0', STR_PAD_LEFT),
                'request_hash' => hash('sha256', 'oversized-request-'.(string) $sequence),
                'stream_sequence' => $sequence,
                'previous_hash' => $previousHash,
                'event_hash' => $eventHash,
            ];
            if (count($batch) === 25) {
                DB::table('audit_events')->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('audit_events')->insert($batch);
        }

        $this->postJson(
            AuditApi::ROUTE_VERIFY_INTEGRITY,
            ['stream_key' => self::STREAM_KEY],
            $this->headers(idempotencyKey: 'oversized-stream-key'),
        )->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/range-too-large');
    }

    public function test_post_handler_rejects_half_open_or_reversed_range(): void
    {
        $this->postJson(
            AuditApi::ROUTE_VERIFY_INTEGRITY,
            ['stream_key' => self::STREAM_KEY, 'first_sequence' => 1],
            $this->headers(),
        )->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-pagination-or-range');

        $this->postJson(
            AuditApi::ROUTE_VERIFY_INTEGRITY,
            ['stream_key' => self::STREAM_KEY, 'first_sequence' => 4, 'last_sequence' => 1],
            $this->headers(),
        )->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-pagination-or-range');
    }

    public function test_post_handler_returns_201_with_sanitized_body_on_success(): void
    {
        $this->recordThreeEvents();

        $response = $this->postJson(
            AuditApi::ROUTE_VERIFY_INTEGRITY,
            ['stream_key' => self::STREAM_KEY],
            $this->headers(idempotencyKey: 'create-verification-success'),
        )->assertStatus(201)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-Correlation-ID', self::CORRELATION_ID);

        $response->assertJsonPath('data.stream_key', self::STREAM_KEY);
        $response->assertJsonPath('data.integrity_status', 'verified');
        $response->assertJsonPath('data.verified_event_count', 3);
        $response->assertJsonMissingPath('data.event_hash');
        $response->assertJsonMissingPath('data.previous_hash');
        $response->assertJsonMissingPath('data.integrity_key_version');
        $response->assertJsonMissingPath('data.checkpoint_hash');

        $this->assertNotEmpty($response->headers->get('ETag'));
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringEndsWith($response->json('data.checkpoint_id'), $location);
    }

    public function test_post_handler_returns_409_safe_problem_on_violation_without_keys_or_hashes(): void
    {
        $this->recordThreeEvents();
        $tampered = DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->orderBy('stream_sequence')->first();
        DB::statement('DROP TRIGGER IF EXISTS audit_events_update_prevent');
        DB::table('audit_events')->where('id', $tampered->id)->update([
            'context' => json_encode(['method' => 'PATCH'], JSON_THROW_ON_ERROR),
        ]);

        try {
            $response = $this->postJson(
                AuditApi::ROUTE_VERIFY_INTEGRITY,
                ['stream_key' => self::STREAM_KEY],
                $this->headers(idempotencyKey: 'create-verification-violation'),
            )->assertStatus(409)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'https://cluster.example/problems/audit-integrity-violation')
                ->assertJsonPath('detail', 'The audit chain reported a violation.');

            $content = $response->getContent();
            $this->assertStringNotContainsString('event_hash', $content);
            $this->assertStringNotContainsString('previous_hash', $content);
            $this->assertStringNotContainsString('integrity_key_version', $content);
            $this->assertStringNotContainsString(self::INTEGRITY_KEY, $content);

            $this->assertSame(1, DB::table('outbox_events')
                ->where('event_type', 'com.cluster.audit.auditintegrityviolationdetected.v1')
                ->count());
            $this->assertSame(1, DB::table('audit_integrity_checkpoints')
                ->where('kind', 'verification')
                ->where('status', 'violated')
                ->count());
        } finally {
            $this->recreateSqliteAppendOnlyGuards();
        }
    }

    public function test_canonical_event_reconstruction_matches_recorder_for_identical_input(): void
    {
        $this->recordThreeEvents();
        $row = DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->first();

        $canonicalizer = $this->app->make(AuditEventCanonicalizer::class);
        $hasher = $this->app->make(AuditIntegrityHasher::class);

        $canonical = $canonicalizer->canonicalizeFromRow((array) $row);
        $reconstructedHash = $hasher->eventHash(
            $canonical,
            $row->previous_hash,
            (string) $row->integrity_key_version,
        );

        $this->assertSame($row->event_hash, $reconstructedHash);
    }

    private function repository(): AuditIntegrityRepository
    {
        return $this->app->make(AuditIntegrityRepository::class);
    }

    private function recorder(): RecordAuditEvent
    {
        return $this->app->make(RecordAuditEvent::class);
    }

    private function invokeHandler(array $payload, string $idempotencyKey): array
    {
        $handler = $this->app->make(VerifyAuditIntegrityHandler::class);

        return $handler->handle([
            'principal_id' => self::ACTOR_ID,
            'facility_id' => self::FACILITY_ID,
            'correlation_id' => self::CORRELATION_ID,
            'stream_key' => $payload['stream_key'],
            'first_sequence' => $payload['first_sequence'] ?? null,
            'last_sequence' => $payload['last_sequence'] ?? null,
            'occurred_at' => new DateTimeImmutable('2026-07-27T12:34:56.789Z'),
        ], $idempotencyKey);
    }

    private function recordThreeEvents(): array
    {
        $first = $this->recorder()->record($this->input(self::firstEventId(), ['method' => 'POST']));
        $second = $this->recorder()->record($this->input(self::secondEventId(), ['method' => 'POST']));
        $third = $this->recorder()->record($this->input(self::thirdEventId(), ['method' => 'POST']));

        return [$first, $second, $third];
    }

    private static function firstEventId(): string
    {
        return '018f6f7d-0c00-7000-8000-000000000610';
    }

    private static function secondEventId(): string
    {
        return '018f6f7d-0c00-7000-8000-000000000611';
    }

    private static function thirdEventId(): string
    {
        return '018f6f7d-0c00-7000-8000-000000000612';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function input(
        string $eventId,
        array $context,
        ?DateTimeImmutable $occurredAt = null,
    ): \Modules\Audit\Contracts\AuditEventInput {
        return new \Modules\Audit\Contracts\AuditEventInput(
            eventId: $eventId,
            sourceModule: 'documents',
            action: 'document.viewed',
            eventType: 'com.cluster.documents.documentviewed.v1',
            actorType: AuditEventInput::ACTOR_USER,
            actorId: self::ACTOR_ID,
            originalActorId: null,
            subjectType: 'document',
            subjectId: self::SUBJECT_ID,
            correlationId: self::CORRELATION_ID,
            outcome: AuditEventInput::OUTCOME_SUCCEEDED,
            classification: AuditEventInput::CLASSIFICATION_INTERNAL,
            context: $context + ['resource_id' => self::SUBJECT_ID],
            occurredAt: $occurredAt ?? new DateTimeImmutable('2026-07-27T10:11:12.123Z'),
            retentionClass: AuditEventInput::RETENTION_STANDARD,
        );
    }

    public function test_verify_range_stream_sequence_gap_writes_violated_checkpoint_with_first_mismatch_sequence(): void
    {
        $this->recordThreeEvents();

        DB::statement('DROP TRIGGER IF EXISTS audit_events_update_prevent');
        DB::statement('DELETE FROM audit_events WHERE stream_key = ? AND stream_sequence = 2', [self::STREAM_KEY]);

        $result = $this->repository()->verifyRange(
            verificationId: (string) Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
            firstSequence: 1,
            lastSequence: 3,
        );

        $this->assertSame(AuditIntegrityRepository::STATUS_VIOLATED, $result['status']);
        $this->assertSame(1, $result['first_sequence']);
        $this->assertSame(3, $result['last_sequence']);

        $checkpoint = DB::table('audit_integrity_checkpoints')
            ->where('id', $result['checkpoint_id'])
            ->first();
        $this->assertNotNull($checkpoint);
        $this->assertSame('violated', $checkpoint->status);
        $details = json_decode((string) $checkpoint->details, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('stream_sequence_gap', $details['reason']);
        $this->assertSame(2, $details['first_mismatch_stream_sequence']);
        $this->assertArrayNotHasKey('event_hash', $details);
        $this->assertArrayNotHasKey('previous_hash', $details);
        $this->assertArrayNotHasKey('integrity_key_version', $details);
    }

    public function test_replay_of_stream_sequence_gap_returns_same_checkpoint_without_500(): void
    {
        $this->recordThreeEvents();

        DB::statement('DROP TRIGGER IF EXISTS audit_events_update_prevent');
        DB::statement('DELETE FROM audit_events WHERE stream_key = ? AND stream_sequence = 2', [self::STREAM_KEY]);

        $firstId = (string) Str::uuid7();
        $secondId = (string) Str::uuid7();
        $first = $this->repository()->verifyRange(
            verificationId: $firstId,
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
            firstSequence: 1,
            lastSequence: 3,
        );
        $second = $this->repository()->verifyRange(
            verificationId: $secondId,
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
            firstSequence: 1,
            lastSequence: 3,
        );

        $this->assertSame(AuditIntegrityRepository::STATUS_VIOLATED, $first['status']);
        $this->assertSame(AuditIntegrityRepository::STATUS_VIOLATED, $second['status']);
        $this->assertSame($first['checkpoint_id'], $second['checkpoint_id']);
        $this->assertSame(1, DB::table('audit_integrity_checkpoints')
            ->where('kind', 'verification')
            ->where('stream_key', self::STREAM_KEY)
            ->count());
    }

    public function test_verify_range_resumes_at_first_surviving_sequence_after_legal_retention_purge_gap(): void
    {
        $expiredAt = new DateTimeImmutable('2010-01-01T10:11:12.123Z');
        Carbon::setTestNow('2010-01-01T12:34:56.789Z');
        $this->recorder()->record($this->input(self::firstEventId(), ['method' => 'POST'], $expiredAt));
        $this->recorder()->record($this->input(self::secondEventId(), ['method' => 'POST'], $expiredAt));
        $this->recorder()->record($this->input(self::thirdEventId(), ['method' => 'POST'], $expiredAt));
        Carbon::setTestNow('2026-07-27T12:34:56.789Z');
        $this->recorder()->record($this->input('018f6f7d-0c00-7000-8000-000000000613', ['method' => 'POST']));

        $purgeHandler = $this->app->make(\Modules\Audit\Features\Retention\Handler\PurgeExpiredAuditEvents::class);
        $purge = $purgeHandler->run(self::STREAM_KEY, $this->legalCutoff());
        $this->assertSame(3, $purge['deleted_event_count']);

        $result = $this->repository()->verifyRange(
            verificationId: (string) Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
            firstSequence: 1,
            lastSequence: 4,
        );

        $this->assertSame(AuditIntegrityRepository::STATUS_VERIFIED, $result['status']);
        $this->assertSame(1, $result['first_sequence']);
        $this->assertSame(4, $result['last_sequence']);
        $this->assertSame(1, $result['verified_event_count']);

        $checkpoint = DB::table('audit_integrity_checkpoints')
            ->where('id', $result['checkpoint_id'])
            ->first();
        $this->assertNotNull($checkpoint);
        $this->assertSame('verification', $checkpoint->kind);
        $this->assertSame('verified', $checkpoint->status);
        $purgeCheckpoint = DB::table('audit_integrity_checkpoints')
            ->where('id', $purge['checkpoint_id'])
            ->first();
        $this->assertSame($purgeCheckpoint->checkpoint_hash, $checkpoint->previous_checkpoint_hash);
    }

    public function test_verify_range_fully_purged_covered_range_writes_safe_verified_checkpoint(): void
    {
        $expiredAt = new DateTimeImmutable('2010-01-01T10:11:12.123Z');
        Carbon::setTestNow('2010-01-01T12:34:56.789Z');
        $this->recorder()->record($this->input(self::firstEventId(), ['method' => 'POST'], $expiredAt));
        $this->recorder()->record($this->input(self::secondEventId(), ['method' => 'POST'], $expiredAt));
        $this->recorder()->record($this->input(self::thirdEventId(), ['method' => 'POST'], $expiredAt));
        Carbon::setTestNow('2026-07-27T12:34:56.789Z');

        $purgeHandler = $this->app->make(\Modules\Audit\Features\Retention\Handler\PurgeExpiredAuditEvents::class);
        $purge = $purgeHandler->run(self::STREAM_KEY, $this->legalCutoff());
        $this->assertSame(3, $purge['deleted_event_count']);

        $result = $this->repository()->verifyRange(
            verificationId: (string) Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
            firstSequence: 1,
            lastSequence: 3,
        );

        $this->assertSame(AuditIntegrityRepository::STATUS_VERIFIED, $result['status']);
        $this->assertSame(0, $result['verified_event_count']);

        $checkpoint = DB::table('audit_integrity_checkpoints')
            ->where('id', $result['checkpoint_id'])
            ->first();
        $this->assertNotNull($checkpoint);
        $this->assertSame('verified', $checkpoint->status);
    }

    public function test_verify_range_real_gap_without_retention_purge_anchor_writes_violation(): void
    {
        [$first, $second, $third] = $this->recordThreeEvents();

        DB::statement('DROP TRIGGER IF EXISTS audit_events_update_prevent');
        DB::statement('DELETE FROM audit_events WHERE id = ?', [$second->eventId]);

        $survivor = DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->orderBy('stream_sequence')
            ->first();
        DB::table('audit_events')->where('id', $survivor->id)->update([
            'previous_hash' => str_repeat('f', 64),
        ]);

        try {
            $result = $this->repository()->verifyRange(
                verificationId: (string) Str::uuid7(),
                correlationId: self::CORRELATION_ID,
                streamKey: self::STREAM_KEY,
                actorId: self::ACTOR_ID,
                firstSequence: 1,
                lastSequence: 3,
            );

            $this->assertSame(AuditIntegrityRepository::STATUS_VIOLATED, $result['status']);
            $this->assertSame(1, $result['first_sequence']);
            $this->assertSame(3, $result['last_sequence']);
        } finally {
            $this->recreateSqliteAppendOnlyGuards();
        }
    }

    public function test_verify_stream_above_max_range_without_anchor_throws_range_too_large_without_checkpoint(): void
    {
        $this->recordThreeEvents();
        $this->assertNull($this->repository()->latestCheckpointAnchorForStream(self::STREAM_KEY));

        $template = (array) DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->orderByDesc('stream_sequence')
            ->first();
        $batch = [];
        for ($sequence = 4; $sequence <= AuditIntegrityRepository::MAX_VERIFICATION_RANGE + 1; $sequence++) {
            $previousHash = hash('sha256', 'oversized-stream-'.(string) ($sequence - 1));
            $eventHash = hash('sha256', 'oversized-stream-'.(string) $sequence);
            $batch[] = [
                ...$template,
                'id' => '018f6f7d-0c00-7001-8000-'.str_pad((string) $sequence, 12, '0', STR_PAD_LEFT),
                'request_hash' => hash('sha256', 'oversized-request-'.(string) $sequence),
                'stream_sequence' => $sequence,
                'previous_hash' => $previousHash,
                'event_hash' => $eventHash,
            ];
            if (count($batch) === 25) {
                DB::table('audit_events')->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('audit_events')->insert($batch);
        }

        $caught = null;
        try {
            $this->repository()->verifyStream(
                verificationId: (string) Str::uuid7(),
                correlationId: self::CORRELATION_ID,
                streamKey: self::STREAM_KEY,
                actorId: self::ACTOR_ID,
            );
        } catch (\InvalidArgumentException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertSame('audit_integrity_range_too_large', $caught->getMessage());
        $this->assertSame(0, DB::table('audit_integrity_checkpoints')
            ->where('kind', 'verification')
            ->count());
    }

    public function test_post_purge_verification_anchors_at_retention_purge_checkpoint(): void
    {
        $expiredAt = new DateTimeImmutable('2010-01-01T10:11:12.123Z');
        Carbon::setTestNow('2010-01-01T12:34:56.789Z');
        $this->recorder()->record($this->input(self::firstEventId(), ['method' => 'POST'], $expiredAt));
        $this->recorder()->record($this->input(self::secondEventId(), ['method' => 'POST'], $expiredAt));
        Carbon::setTestNow('2026-07-27T12:34:56.789Z');
        $this->recorder()->record($this->input(self::thirdEventId(), ['method' => 'POST']));

        $handler = $this->app->make(\Modules\Audit\Features\Retention\Handler\PurgeExpiredAuditEvents::class);
        $purge = $handler->run(self::STREAM_KEY, $this->legalCutoff());
        $this->assertSame(2, $purge['deleted_event_count']);

        $purgeCheckpoint = DB::table('audit_integrity_checkpoints')
            ->where('id', $purge['checkpoint_id'])
            ->first();
        $this->assertNotNull($purgeCheckpoint);
        $remaining = DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->first();
        $this->assertNotNull($remaining);
        $this->assertSame(3, (int) $remaining->stream_sequence);
        $this->assertSame($purgeCheckpoint->terminal_event_hash, $remaining->previous_hash);

        $verification = $this->repository()->verifyStream(
            verificationId: (string) Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
        );

        $this->assertSame(AuditIntegrityRepository::STATUS_VERIFIED, $verification['status']);
        $this->assertSame(1, $verification['verified_event_count']);
        $verificationCheckpoint = DB::table('audit_integrity_checkpoints')
            ->where('id', $verification['checkpoint_id'])
            ->first();
        $this->assertNotNull($verificationCheckpoint);
        $this->assertSame($purgeCheckpoint->checkpoint_hash, $verificationCheckpoint->previous_checkpoint_hash);
    }

    private function legalCutoff(): DateTimeImmutable
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $now->modify('-'.(string) (\Modules\Audit\Domain\AuditRetentionPolicy::MINIMUM_RETENTION_DAYS + 10).' days');
    }

    private function recreateSqliteAppendOnlyGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_events_update_prevent
            BEFORE UPDATE ON audit_events
            BEGIN
                SELECT RAISE(ABORT, 'audit_events_immutable');
            END
            SQL);
    }

    private function disableCapability(): void
    {
        $engine = $this->app->make(DecideAccess::class);
        assert($engine instanceof IntegrityTestDecisionEngine);
        $engine->allowIntegrity = false;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $idempotencyKey = 'verified-key'): array
    {
        return [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'Idempotency-Key' => $idempotencyKey,
            'X-Test-Audit-Authenticated' => '1',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decisionCalls(): array
    {
        $engine = $this->app->make(DecideAccess::class);
        assert($engine instanceof IntegrityTestDecisionEngine);

        return $engine->calls;
    }
}

final class IntegrityTestSessionMiddleware
{
    public function __construct(private readonly ResolvePrincipalContext $principals) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->principals->resolve($request) === null) {
            return AuditApi::problem(
                401,
                'authentication-required',
                'Unauthorized',
                'Authentication is required.',
                AuditApi::correlationId($request),
            );
        }

        return $next($request);
    }
}

final class IntegrityTestPrincipalResolver implements ResolvePrincipalContext
{
    public function resolve(Request $request): ?PrincipalContext
    {
        if ($request->header('X-Test-Audit-Authenticated') !== '1') {
            return null;
        }

        return new PrincipalContext(
            userId: AuditIntegrityTest::ACTOR_ID,
            personId: null,
            accountStatus: 'active',
            clusterIds: [],
            facilityIds: [AuditIntegrityTest::FACILITY_ID],
            organizationUnitIds: [AuditIntegrityTest::UNIT_ID],
            primaryOrganizationUnitId: AuditIntegrityTest::UNIT_ID,
            selectedScope: ['scope_type' => 'facility', 'scope_id' => AuditIntegrityTest::FACILITY_ID],
            sessionRestricted: false,
        );
    }

    public function resolveSelectedScope(Request $request): ?array
    {
        return $this->resolve($request)?->selectedScope;
    }

    public function persistSelectedScope(Request $request, string $scopeType, string $scopeId): void {}
}

final class IntegrityTestDecisionEngine implements DecideAccess
{
    public bool $allowIntegrity = true;

    /** @var list<array{actor: array<string, mixed>, capability: string, facts: RecordFacts}> */
    public array $calls = [];

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        assert($facts instanceof RecordFacts);
        $this->calls[] = compact('actor', 'capability', 'facts');

        $allowed = match ($capability) {
            'audit.integrity.verify' => $this->allowIntegrity,
            default => true,
        };

        return new AccessDecision(
            decision: $allowed ? 'allow' : 'deny',
            action: $capability,
            resourceType: $facts->resourceType,
            reasonCodes: [$allowed ? 'integrity_test_allowed' : 'integrity_test_denied'],
            policyVersion: 'audit-integrity-test-v1',
            factsVersion: 'audit-integrity-test-v1',
            classification: $facts->classification,
            decisionId: $allowed ? '018f6f7d-0c00-7000-8000-000000000699' : null,
            allowedActions: $allowed ? ['audit.integrity.verify'] : [],
            fieldAccess: [],
        );
    }
}
