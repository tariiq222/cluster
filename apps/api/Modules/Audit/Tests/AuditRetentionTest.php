<?php

declare(strict_types=1);

namespace Modules\Audit\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Audit\Domain\AuditRetentionPolicy;
use Modules\Audit\Features\Retention\Handler\PurgeExpiredAuditEvents;
use Modules\Audit\Infrastructure\Persistence\AuditIntegrityRepository;
use Tests\TestCase;

final class AuditRetentionTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT_ID = '018f6f7d-0c00-7000-8000-000000000701';

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000702';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000703';

    private const STREAM_KEY = 'documents:document:'.self::SUBJECT_ID;

    private const INTEGRITY_KEY_VERSION = 'retention_v1';

    private const INTEGRITY_KEY = 'audit-retention-test-key-material-32-byte';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-27T12:34:56.789Z');

        config()->set('audit.integrity.keys', [
            self::INTEGRITY_KEY_VERSION => self::INTEGRITY_KEY,
        ]);
        config()->set('audit.integrity.active_key_version', self::INTEGRITY_KEY_VERSION);
        config()->set('audit.retention.floor_days', 2555);

        $this->app->forgetInstance(AuditIntegrityRepository::class);
        $this->app->forgetInstance(PurgeExpiredAuditEvents::class);

    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unexpired_retention_cutoff_refuses_to_purge_anything(): void
    {
        $this->recordUnexpiredEvents();

        $cutoff = $this->legalCutoff();

        $result = $this->handler()->run(self::STREAM_KEY, $cutoff);

        $this->assertSame(0, $result['deleted_event_count']);
        $this->assertSame(0, DB::table('audit_integrity_checkpoints')->count());
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'audit.retention.purged')
            ->count());
        $this->assertSame(5, DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count());
    }

    public function test_floor_violation_refuses_purge_entirely(): void
    {
        $this->recordExpiredAndUnexpiredEvents();

        $cutoff = now('UTC')->toDateTimeImmutable()
            ->modify('-100 days');

        $caught = null;
        try {
            $this->handler()->run(self::STREAM_KEY, $cutoff);
        } catch (InvalidArgumentException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertSame('audit_retention_floor_too_high', $caught->getMessage());
        $this->assertSame(5, DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count());
        $this->assertSame(0, DB::table('audit_integrity_checkpoints')->count());
    }

    public function test_wedge_at_stream_head_purges_nothing_without_error(): void
    {
        $this->recordHeadWedgeEvents();

        $result = $this->handler()->run(self::STREAM_KEY, $this->legalCutoff());

        $this->assertSame(0, $result['deleted_event_count']);
        $this->assertSame(1, $result['stopped_at_sequence']);
        $this->assertSame(5, DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count());
        $this->assertSame(0, DB::table('audit_integrity_checkpoints')->count());
    }

    public function test_wedge_event_does_not_block_purging_of_earlier_expired_prefix(): void
    {
        $this->recordWedgeEvents();

        $cutoff = $this->legalCutoff();
        $result = $this->handler()->run(self::STREAM_KEY, $cutoff);

        $this->assertSame(1, $result['deleted_event_count']);
        $this->assertSame(1, $result['first_sequence']);
        $this->assertSame(1, $result['last_sequence']);
        $this->assertSame(2, $result['stopped_at_sequence']);

        $survivors = DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->orderBy('stream_sequence')
            ->pluck('stream_sequence')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
        $this->assertSame([2, 3, 4, 5], $survivors);

        $wedge = DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->where('stream_sequence', 2)
            ->first();
        $this->assertNotNull($wedge, 'The long-retention wedge event must survive the partial purge.');
        $this->assertTrue(
            $this->notExpiredAt((string) $wedge->retention_until, $cutoff),
            'The wedge must still be inside its own retention window.',
        );

        $checkpoint = DB::table('audit_integrity_checkpoints')
            ->where('id', $result['checkpoint_id'])
            ->first();
        $this->assertNotNull($checkpoint);
        $this->assertSame('retention_purge', $checkpoint->kind);
        $this->assertSame('verified', $checkpoint->status);
        $this->assertSame(1, (int) $checkpoint->first_sequence);
        $this->assertSame(1, (int) $checkpoint->last_sequence);
        $this->assertSame(1, (int) $checkpoint->event_count);
        $details = json_decode((string) $checkpoint->details, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $details['stopped_at_sequence']);
    }

    public function test_partial_prefix_purge_preserves_stream_verification(): void
    {
        $this->recordWedgeEvents();

        $purge = $this->handler()->run(self::STREAM_KEY, $this->legalCutoff());
        $this->assertSame(1, $purge['deleted_event_count']);
        $this->assertSame(2, $purge['stopped_at_sequence']);

        $verification = $this->app->make(AuditIntegrityRepository::class)->verifyStream(
            verificationId: (string) \Illuminate\Support\Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
        );

        $this->assertSame(AuditIntegrityRepository::STATUS_VERIFIED, $verification['status']);
        $this->assertSame(2, $verification['first_sequence']);
        $this->assertSame(5, $verification['last_sequence']);
        $this->assertSame(4, $verification['verified_event_count']);
    }

    public function test_wedge_event_is_purged_once_its_own_retention_passes(): void
    {
        $this->recordWedgeEvents();

        $partial = $this->handler()->run(self::STREAM_KEY, $this->legalCutoff());
        $this->assertSame(1, $partial['deleted_event_count']);

        Carbon::setTestNow('2028-01-15T12:00:00.000Z');
        $laterCutoff = Carbon::now()
            ->subDays(AuditRetentionPolicy::MINIMUM_RETENTION_DAYS + 10)
            ->toDateTimeImmutable();

        $later = $this->handler()->run(self::STREAM_KEY, $laterCutoff);

        $this->assertSame(2, $later['deleted_event_count']);
        $this->assertSame(2, $later['first_sequence']);
        $this->assertSame(3, $later['last_sequence']);
        $this->assertSame(4, $later['stopped_at_sequence']);

        $survivors = DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->orderBy('stream_sequence')
            ->pluck('stream_sequence')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
        $this->assertSame([4, 5], $survivors);

        $final = $this->handler()->run(self::STREAM_KEY, $laterCutoff);
        $this->assertSame(0, $final['deleted_event_count']);
        $this->assertSame(2, DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count());
    }

    public function test_committed_prefix_purge_records_checkpoint_activity_and_deletes_only_eligible_rows(): void
    {
        $this->recordExpiredAndUnexpiredEvents();

        $cutoff = $this->legalCutoff();
        $result = $this->handler()->run(self::STREAM_KEY, $cutoff);

        $this->assertGreaterThan(0, $result['deleted_event_count']);
        $this->assertSame(1, $result['first_sequence']);
        $this->assertSame($result['deleted_event_count'], $result['last_sequence']);
        $this->assertSame(5 - $result['deleted_event_count'], DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count());
        $this->assertSame(self::INTEGRITY_KEY_VERSION, $result['integrity_key_version']);

        $checkpoint = DB::table('audit_integrity_checkpoints')
            ->where('id', $result['checkpoint_id'])
            ->first();
        $this->assertNotNull($checkpoint);
        $this->assertSame('retention_purge', $checkpoint->kind);
        $this->assertSame('verified', $checkpoint->status);
        $this->assertSame($result['deleted_event_count'], (int) $checkpoint->event_count);
        $this->assertSame($result['deleted_event_count'], (int) $checkpoint->last_sequence);

        $activity = DB::table('audit_events')
            ->where('source_module', 'audit')
            ->where('action', 'audit.retention.purged')
            ->first();
        $this->assertNotNull($activity);
        $this->assertSame('retention', $activity->subject_type);
        $this->assertNull($activity->subject_id);

        $outbox = DB::table('outbox_events')
            ->where('event_type', 'com.cluster.audit.auditeventrecorded.v1')
            ->where('event_id', $activity->id)
            ->first();
        $this->assertNotNull($outbox);
    }

    public function test_checkpoint_before_delete_failure_rolls_back_every_audit_event(): void
    {
        $this->recordExpiredAndUnexpiredEvents();
        $countBefore = DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count();

        // Tamper the first expired row to force the chain verifier inside
        // the purge to fail. The transaction MUST roll back so the rest of
        // the expired prefix is preserved.
        $firstRow = DB::table('audit_events')
            ->where('stream_key', self::STREAM_KEY)
            ->where('stream_sequence', 1)
            ->first();
        DB::statement('DROP TRIGGER IF EXISTS audit_events_update_prevent');
        DB::table('audit_events')->where('id', $firstRow->id)->update([
            'context' => json_encode(['method' => 'PATCH'], JSON_THROW_ON_ERROR),
        ]);

        $caught = null;
        try {
            $this->handler()->run(self::STREAM_KEY, $this->legalCutoff());
        } catch (InvalidArgumentException $exception) {
            $caught = $exception;
        } finally {
            $this->recreateSqliteAppendOnlyGuards();
        }

        $this->assertNotNull($caught, 'Tampered chain must surface as a typed refusal.');
        $this->assertSame('audit_retention_chain_violated', $caught->getMessage());
        $this->assertSame(0, DB::table('audit_integrity_checkpoints')
            ->where('kind', 'retention_purge')->count());
        $this->assertSame($countBefore, DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count());
        $this->assertSame(0, DB::table('audit_events')
            ->where('action', 'audit.retention.purged')->count());
    }

    public function test_retention_activity_failure_rolls_back_checkpoint_and_deletion(): void
    {
        $this->recordExpiredAndUnexpiredEvents();
        $countBefore = DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count();

        $this->app->instance(RecordAuditEvent::class, new class implements RecordAuditEvent
        {
            public function record(\Modules\Audit\Contracts\AuditEventInput $input): \Modules\Audit\Contracts\AuditEventReceipt
            {
                throw new \RuntimeException('retention_activity_failed');
            }
        });
        $this->app->forgetInstance(PurgeExpiredAuditEvents::class);

        $caught = null;
        try {
            $this->handler()->run(self::STREAM_KEY, $this->legalCutoff());
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertSame('retention_activity_failed', $caught->getMessage());
        $this->assertSame($countBefore, DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count());
        $this->assertSame(0, DB::table('audit_integrity_checkpoints')->where('kind', 'retention_purge')->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'audit.retention.purged')->count());
    }

    public function test_surviving_checkpoint_and_link_integrity_after_subsequent_success(): void
    {
        $this->recordExpiredAndUnexpiredEvents();
        $cutoff = $this->legalCutoff();

        $first = $this->handler()->run(self::STREAM_KEY, $cutoff);
        $this->assertGreaterThan(0, $first['deleted_event_count']);

        $checkpointAnchor = $this->app->make(AuditIntegrityRepository::class)
            ->latestCheckpointAnchorForStream(self::STREAM_KEY);

        $second = $this->handler()->run(self::STREAM_KEY, $cutoff);

        $this->assertSame(0, $second['deleted_event_count']);
        $this->assertSame($checkpointAnchor, $this->app->make(AuditIntegrityRepository::class)
            ->latestCheckpointAnchorForStream(self::STREAM_KEY));

        $linkRow = DB::table('audit_integrity_checkpoints')
            ->where('id', $first['checkpoint_id'])
            ->first();
        $this->assertSame('retention_purge', $linkRow->kind);
        $this->assertSame('verified', $linkRow->status);

        $survivors = DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->get();
        $previous = null;
        foreach ($survivors as $row) {
            $expected = $previous === null
                ? (string) $linkRow->terminal_event_hash
                : (string) $previous->event_hash;
            $this->assertSame($expected, (string) $row->previous_hash);
            $previous = $row;
        }

        $verification = $this->app->make(AuditIntegrityRepository::class)->verifyStream(
            verificationId: (string) \Illuminate\Support\Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
        );
        $this->assertSame(AuditIntegrityRepository::STATUS_VERIFIED, $verification['status']);
        $this->assertSame($survivors->count(), $verification['verified_event_count']);
    }

    public function test_full_purge_anchor_continues_new_events_and_verifies_the_stream(): void
    {
        $this->recordExpiredEvents();
        Carbon::setTestNow('2026-07-27T12:34:56.789Z');

        $purge = $this->handler()->run(self::STREAM_KEY, $this->legalCutoff());
        $this->assertSame(2, $purge['deleted_event_count']);
        $this->assertNull($purge['stopped_at_sequence']);
        $this->assertSame(0, DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count());

        Carbon::setTestNow('2026-07-27T12:34:56.789Z');
        $receipt = $this->app->make(RecordAuditEvent::class)->record($this->input(
            eventId: '018f6f7d-0c00-7000-8000-000000000703',
            retentionClass: \Modules\Audit\Contracts\AuditEventInput::RETENTION_REGULATED,
        ));
        $this->assertSame(3, $receipt->streamSequence);

        $checkpoint = DB::table('audit_integrity_checkpoints')->where('id', $purge['checkpoint_id'])->first();
        $newEvent = DB::table('audit_events')->where('id', $receipt->eventId)->first();
        $this->assertSame($checkpoint->terminal_event_hash, $newEvent->previous_hash);

        $verification = $this->app->make(AuditIntegrityRepository::class)->verifyStream(
            verificationId: (string) \Illuminate\Support\Str::uuid7(),
            correlationId: self::CORRELATION_ID,
            streamKey: self::STREAM_KEY,
            actorId: self::ACTOR_ID,
        );
        $this->assertSame(AuditIntegrityRepository::STATUS_VERIFIED, $verification['status']);
        $this->assertSame(3, $verification['first_sequence']);
        $this->assertSame(1, $verification['verified_event_count']);
    }

    public function test_legal_and_regulated_minimums_are_enforced_by_handler(): void
    {
        $this->recordExpiredAndUnexpiredEvents();

        // 1 day ago is well above the legal floor of 2555 days; expect refusal.
        $caught = null;
        try {
            $this->handler()->run(
                self::STREAM_KEY,
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day'),
            );
        } catch (InvalidArgumentException $exception) {
            $caught = $exception;
        }
        $this->assertSame('audit_retention_floor_too_high', $caught->getMessage());
        $this->assertSame(0, DB::table('audit_integrity_checkpoints')->count());

        // Floor enforcement must remain in force even if the deployment
        // raises audit.retention.floor_days above the legal minimum.
        config()->set('audit.retention.floor_days', 3000);
        $this->app->forgetInstance(\Modules\Audit\Domain\AuditRetentionPolicy::class);
        $this->app->forgetInstance(PurgeExpiredAuditEvents::class);

        $caughtFloor = null;
        try {
            $this->handler()->run(
                self::STREAM_KEY,
                (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-2900 days'),
            );
        } catch (InvalidArgumentException $exception) {
            $caughtFloor = $exception;
        }
        $this->assertSame('audit_retention_floor_too_high', $caughtFloor->getMessage());
    }

    public function test_console_command_refuses_to_run_without_stream_or_cutoff(): void
    {
        config()->set('audit.integrity.keys', [
            self::INTEGRITY_KEY_VERSION => self::INTEGRITY_KEY,
        ]);

        $exitCode = Artisan::call('audit:retention:purge');
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--stream is required', Artisan::output());
    }

    public function test_console_command_rejects_malformed_iso_cutoff(): void
    {
        $exitCode = Artisan::call('audit:retention:purge', [
            '--stream' => self::STREAM_KEY,
            '--before' => '2026-07-27 12:34:56',
        ]);
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('UTC timestamp', Artisan::output());
    }

    public function test_console_command_purges_nothing_when_no_eligible_rows(): void
    {
        $exitCode = Artisan::call('audit:retention:purge', [
            '--stream' => self::STREAM_KEY,
            '--before' => $this->legalCutoff()->format('Y-m-d\TH:i:s.v\Z'),
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No eligible rows', Artisan::output());
        $this->assertSame(0, DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count());
    }

    public function test_console_command_purges_eligible_prefix_and_records_retention_activity(): void
    {
        $this->recordExpiredAndUnexpiredEvents();

        $exitCode = Artisan::call('audit:retention:purge', [
            '--stream' => self::STREAM_KEY,
            '--before' => $this->legalCutoff()->format('Y-m-d\TH:i:s.v\Z'),
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Purged', Artisan::output());
        $this->assertSame(1, DB::table('audit_integrity_checkpoints')->where('kind', 'retention_purge')->count());
        $activity = DB::table('audit_events')
            ->where('action', 'audit.retention.purged')
            ->first();
        $this->assertNotNull($activity);

        $eventRow = DB::table('outbox_events')
            ->where('event_type', 'com.cluster.audit.auditeventrecorded.v1')
            ->where('event_id', $activity->id)
            ->first();
        $this->assertNotNull($eventRow);
        $payload = json_decode((string) $eventRow->cloud_event, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('com.cluster.audit.auditeventrecorded.v1', $payload['type']);
        $this->assertSame('/'.$activity->id, $payload['subject']);
    }

    public function test_console_command_partial_purge_reports_stopped_at_wedge_and_exits_zero(): void
    {
        $this->recordWedgeEvents();

        $exitCode = Artisan::call('audit:retention:purge', [
            '--stream' => self::STREAM_KEY,
            '--before' => $this->legalCutoff()->format('Y-m-d\TH:i:s.v\Z'),
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Purged 1 event', $output);
        $this->assertStringContainsString('Stopped at non-expired event 2', $output);
        $this->assertSame(4, DB::table('audit_events')->where('stream_key', self::STREAM_KEY)->count());
        $this->assertSame(1, DB::table('audit_integrity_checkpoints')->where('kind', 'retention_purge')->count());
    }

    private function handler(): PurgeExpiredAuditEvents
    {
        return $this->app->make(PurgeExpiredAuditEvents::class);
    }

    private function recordUnexpiredEvents(): void
    {
        $recorder = $this->app->make(RecordAuditEvent::class);
        Carbon::setTestNow('2026-07-27T12:34:56.789Z');
        for ($i = 1; $i <= 5; $i++) {
            $recorder->record($this->input(
                eventId: sprintf('018f6f7d-0c00-7000-8000-0000000007%02X', $i),
                retentionClass: \Modules\Audit\Contracts\AuditEventInput::RETENTION_REGULATED,
            ));
        }
    }

    private function recordHeadWedgeEvents(): void
    {
        $recorder = $this->app->make(RecordAuditEvent::class);
        $specifications = [
            [1, '2010-01-01T00:00:00.000Z', \Modules\Audit\Contracts\AuditEventInput::RETENTION_REGULATED],
            [2, '2011-01-01T00:00:00.000Z', \Modules\Audit\Contracts\AuditEventInput::RETENTION_STANDARD],
            [3, '2026-07-27T12:34:56.789Z', \Modules\Audit\Contracts\AuditEventInput::RETENTION_REGULATED],
            [4, '2026-07-27T12:34:56.789Z', \Modules\Audit\Contracts\AuditEventInput::RETENTION_REGULATED],
            [5, '2026-07-27T12:34:56.789Z', \Modules\Audit\Contracts\AuditEventInput::RETENTION_REGULATED],
        ];

        foreach ($specifications as [$sequence, $recordedAt, $retentionClass]) {
            Carbon::setTestNow($recordedAt);
            $recorder->record($this->input(
                eventId: sprintf('018f6f7d-0c00-7000-8000-0000000007%02X', $sequence),
                retentionClass: $retentionClass,
            ));
        }

        Carbon::setTestNow('2026-07-27T12:34:56.789Z');
    }

    /**
     * Stream with a longer-retention wedge event in the middle: sequence 2
     * is regulated (3650 days, recorded 2010 → expires 2019-12-30) while
     * sequences 1 and 3 are standard (2555 days, recorded 2010 → expired
     * years before the legal cutoff). Sequences 4-5 are recent and far
     * inside their retention window.
     */
    private function recordWedgeEvents(): void
    {
        $recorder = $this->app->make(RecordAuditEvent::class);
        $specifications = [
            [1, '2010-01-01T00:00:00.000Z', \Modules\Audit\Contracts\AuditEventInput::RETENTION_STANDARD],
            [2, '2010-01-01T00:00:00.000Z', \Modules\Audit\Contracts\AuditEventInput::RETENTION_REGULATED],
            [3, '2010-01-01T00:00:00.000Z', \Modules\Audit\Contracts\AuditEventInput::RETENTION_STANDARD],
            [4, '2026-07-27T12:34:56.789Z', \Modules\Audit\Contracts\AuditEventInput::RETENTION_REGULATED],
            [5, '2026-07-27T12:34:56.789Z', \Modules\Audit\Contracts\AuditEventInput::RETENTION_REGULATED],
        ];

        foreach ($specifications as [$sequence, $recordedAt, $retentionClass]) {
            Carbon::setTestNow($recordedAt);
            $recorder->record($this->input(
                eventId: sprintf('018f6f7d-0c00-7000-8000-0000000007%02X', $sequence),
                retentionClass: $retentionClass,
            ));
        }

        Carbon::setTestNow('2026-07-27T12:34:56.789Z');
    }

    private function recordExpiredEvents(): void
    {
        $recorder = $this->app->make(RecordAuditEvent::class);
        Carbon::setTestNow('2000-01-01T00:00:00.000Z');
        for ($i = 1; $i <= 2; $i++) {
            $recorder->record($this->input(
                eventId: sprintf('018f6f7d-0c00-7000-8000-0000000007%02X', $i),
                retentionClass: \Modules\Audit\Contracts\AuditEventInput::RETENTION_REGULATED,
            ));
        }
    }

    private function recordExpiredAndUnexpiredEvents(): void
    {
        $recorder = $this->app->make(RecordAuditEvent::class);
        Carbon::setTestNow('2000-01-01T00:00:00.000Z');
        for ($i = 1; $i <= 2; $i++) {
            $recorder->record($this->input(
                eventId: sprintf('018f6f7d-0c00-7000-8000-0000000007%02X', $i),
                retentionClass: \Modules\Audit\Contracts\AuditEventInput::RETENTION_REGULATED,
            ));
        }
        Carbon::setTestNow('2026-07-27T12:34:56.789Z');
        for ($i = 3; $i <= 5; $i++) {
            $recorder->record($this->input(
                eventId: sprintf('018f6f7d-0c00-7000-8000-0000000007%02X', $i),
                retentionClass: \Modules\Audit\Contracts\AuditEventInput::RETENTION_REGULATED,
            ));
        }
    }

    private function input(string $eventId, string $retentionClass): \Modules\Audit\Contracts\AuditEventInput
    {
        return new \Modules\Audit\Contracts\AuditEventInput(
            eventId: $eventId,
            sourceModule: 'documents',
            action: 'document.viewed',
            eventType: 'com.cluster.documents.documentviewed.v1',
            actorType: \Modules\Audit\Contracts\AuditEventInput::ACTOR_USER,
            actorId: self::ACTOR_ID,
            originalActorId: null,
            subjectType: 'document',
            subjectId: self::SUBJECT_ID,
            correlationId: self::CORRELATION_ID,
            outcome: \Modules\Audit\Contracts\AuditEventInput::OUTCOME_SUCCEEDED,
            classification: \Modules\Audit\Contracts\AuditEventInput::CLASSIFICATION_INTERNAL,
            context: ['resource_id' => self::SUBJECT_ID],
            occurredAt: now('UTC')->toDateTimeImmutable(),
            retentionClass: $retentionClass,
        );
    }

    private function legalCutoff(): DateTimeImmutable
    {
        $now = now('UTC')->toDateTimeImmutable();

        return $now->modify('-'.(string) (AuditRetentionPolicy::MINIMUM_RETENTION_DAYS + 10).' days');
    }

    private function notExpiredAt(string $retentionUntil, DateTimeImmutable $cutoff): bool
    {
        return new DateTimeImmutable($retentionUntil) >= $cutoff;
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
}
