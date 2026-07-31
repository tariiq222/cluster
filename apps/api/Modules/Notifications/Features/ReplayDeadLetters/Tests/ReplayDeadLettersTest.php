<?php

namespace Modules\Notifications\Features\ReplayDeadLetters\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Notifications\Contracts\ResolveTechnicalAlertRecipients;
use Modules\Notifications\Features\ReplayDeadLetters\Handler\ReplayDeadLettersHandler;
use Tests\TestCase;

class ReplayDeadLettersTest extends TestCase
{
    use RefreshDatabase;

    private const WORK_RECORD_TYPE = 'com.cluster.workrecord.submitted.v1';

    public function test_replay_feeds_a_valid_work_record_dead_letter_into_the_pipeline_and_stamps_it(): void
    {
        $event = $this->workRecordEvent();
        $this->deadLetter('platform.work-record.submitted.v1', '1784192400000-0', $event, 'PROCESSING_FAILED');

        $counts = $this->app->make(ReplayDeadLettersHandler::class)->replayOnce();

        $this->assertSame(['replayed' => 1, 'skipped' => 0, 'failed' => 0], $counts);
        $this->assertDatabaseHas('notification_inbox', ['event_id' => $event['id']]);
        $this->assertDatabaseHas('notifications', [
            'event_id' => $event['id'],
            'recipient_user_id' => $event['data']['record']['owner']['user_id'],
        ]);
        $this->assertNotNull(DB::table('notification_dead_letters')
            ->where('source_stream', 'platform.work-record.submitted.v1')
            ->value('replayed_at'));
    }

    public function test_replay_routes_a_technical_alert_dead_letter_to_its_handler(): void
    {
        $this->app->instance(ResolveTechnicalAlertRecipients::class, new FixedReplayRecipients([
            '019f8e3b-3368-7192-85a6-3da3949fd763',
        ]));
        $event = $this->technicalAlertEvent();
        $this->deadLetter('platform.technical-alert.v1', '1784192400001-0', $event, 'PROCESSING_FAILED');

        $counts = $this->app->make(ReplayDeadLettersHandler::class)->replayOnce();

        $this->assertSame(['replayed' => 1, 'skipped' => 0, 'failed' => 0], $counts);
        $this->assertDatabaseHas('notification_inbox', ['event_id' => $event['id']]);
        $this->assertDatabaseHas('notifications', ['event_id' => $event['id']]);
    }

    public function test_replay_is_idempotent_and_skips_unreplayable_rows(): void
    {
        $this->deadLetter('platform.work-record.submitted.v1', '1784192400002-0', $this->workRecordEvent(), 'PROCESSING_FAILED');
        $this->deadLetter('platform.work-record.submitted.v1', '1784192400003-0', ['stream_id' => '1784192400003-0', 'raw_payload' => '{"secret":'], 'MALFORMED_EVENT');
        $this->deadLetter('platform.work-record.submitted.v1', '1784192400004-0', ['type' => 'com.cluster.workrecord.unknown.v1'], 'INVALID_EVENT');

        $first = $this->app->make(ReplayDeadLettersHandler::class)->replayOnce();
        $this->assertSame(1, $first['replayed']);
        $this->assertSame(2, $first['skipped']);

        $second = $this->app->make(ReplayDeadLettersHandler::class)->replayOnce();
        $this->assertSame(['replayed' => 0, 'skipped' => 2, 'failed' => 0], $second);
        $this->assertSame(1, DB::table('notification_inbox')->count());
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_replay_command_requires_once_and_reports_counts(): void
    {
        $event = $this->workRecordEvent();
        $this->deadLetter('platform.work-record.submitted.v1', '1784192400005-0', $event, 'PROCESSING_FAILED');

        Artisan::call('notifications:replay-dlq', ['--once' => true, '--limit' => 10]);
        $output = Artisan::output();

        $this->assertStringContainsString('Replayed dead letters: 1 (skipped: 0, failed: 0)', $output);
        $this->assertDatabaseHas('notification_inbox', ['event_id' => $event['id']]);
        Artisan::call('notifications:replay-dlq', ['--limit' => 10]);
        $this->assertStringContainsString('The bounded --once mode is required.', Artisan::output());
    }

    /** @param array<string, mixed> $event */
    private function deadLetter(string $stream, string $messageId, array $event, string $failureCode): void
    {
        DB::table('notification_dead_letters')->insert([
            'id' => fake()->uuid(),
            'source_stream' => $stream,
            'source_message_id' => $messageId,
            'original_event' => json_encode($event, JSON_THROW_ON_ERROR),
            'failure_code' => $failureCode,
            'attempts' => 3,
            'consumer' => 'worker-poison',
            'failed_at' => now('UTC')->subMinutes(5),
            'created_at' => now('UTC')->subMinutes(5),
            'updated_at' => now('UTC')->subMinutes(5),
        ]);
    }

    /** @return array<string, mixed> */
    private function workRecordEvent(): array
    {
        return [
            'specversion' => '1.0',
            'id' => '018f6f7d-0c00-7000-8000-000000000301',
            'source' => '/work-records',
            'type' => self::WORK_RECORD_TYPE,
            'subject' => '/work-records/018f6f7d-0c00-7000-8000-000000000401',
            'time' => '2026-07-16T09:00:00Z',
            'datacontenttype' => 'application/json',
            'correlationid' => '018f6f7d-0c00-7000-8000-000000000501',
            'data' => [
                'record' => [
                    'id' => '018f6f7d-0c00-7000-8000-000000000401',
                    'record_number' => 'WR-REPLAY',
                    'work_type_version_id' => '0197f0e0-0000-7000-8000-000000000001',
                    'owner' => [
                        'facility_id' => '018f6f7d-0c00-7000-8000-000000000011',
                        'user_id' => '018f6f7d-0c00-7000-8000-000000000021',
                    ],
                    'status' => 'submitted',
                    'classification' => 'internal',
                    'payload' => ['title' => 'إعادة تشغيل', 'description' => 'وصف'],
                    'lock_version' => 1,
                    'submitted_at' => '2026-07-16T09:00:00Z',
                    'created_at' => '2026-07-16T09:00:00Z',
                    'updated_at' => '2026-07-16T09:00:00Z',
                ],
                'access_context' => ['owner_facility_id' => '018f6f7d-0c00-7000-8000-000000000011'],
                'classification' => 'internal',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function technicalAlertEvent(): array
    {
        return [
            'specversion' => '1.0',
            'id' => '019f8e3b-3368-7192-85a6-3da3949fd75a',
            'source' => '/platform-settings',
            'type' => 'com.cluster.platform.technical-alert.v1',
            'time' => '2026-07-23T10:15:00Z',
            'datacontenttype' => 'application/json',
            'data' => [
                'alert_code' => 'database-latency',
                'severity' => 'critical',
                'recipient_capability' => 'platform_operations.alerts.manage',
                'occurred_at' => '2026-07-23T10:15:00+03:00',
                'correlation_id' => '019f8e3b-3368-7192-85a6-3da3949fd75b',
            ],
        ];
    }
}

final class FixedReplayRecipients implements ResolveTechnicalAlertRecipients
{
    /** @param list<string> $recipients */
    public function __construct(private readonly array $recipients) {}

    public function resolve(string $recipientCapability): array
    {
        return $this->recipients;
    }
}
