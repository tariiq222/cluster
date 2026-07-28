<?php

namespace Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Handler\ConsumeWorkRecordSubmittedHandler;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Worker\NotificationsStreamWorker;
use PDOException;
use ReflectionMethod;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Tests\TestCase;

class ConsumeWorkRecordSubmittedTest extends TestCase
{
    use RefreshDatabase;

    public function test_handler_commits_inbox_before_one_minimal_notification_effect(): void
    {
        $this->requireAsyncImplementation();
        $event = $this->cloudEvent();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->handler()->handle($event);

        $this->assertDatabaseHas('notification_inbox', ['event_id' => $event['id']]);
        $this->assertDatabaseHas('notifications', [
            'event_id' => $event['id'],
            'recipient_user_id' => $event['data']['record']['owner']['user_id'],
            'source_record_id' => $event['data']['record']['id'],
            'title' => 'تم تقديم سجل عمل',
            'is_read' => false,
        ]);
        $this->assertDatabaseCount('notification_inbox', 1);
        $this->assertDatabaseCount('notifications', 1);

        $inboxInsert = $this->firstQueryIndex($queries, 'insert into "notification_inbox"');
        $notificationInsert = $this->firstQueryIndex($queries, 'insert into "notifications"');
        $this->assertLessThan($notificationInsert, $inboxInsert, 'Inbox receipt must be inserted before the effect.');
        $this->assertSame([], array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'work_records') || str_contains($sql, 'outbox_events'),
        )), 'Notifications must not read or write WorkRecords-owned persistence.');

        $columns = Schema::getColumnListing('notifications');
        foreach (['record_payload', 'owner_facility_id', 'facility_id', 'access_context', 'classification'] as $sourceOwnedField) {
            $this->assertNotContains($sourceOwnedField, $columns);
        }
    }

    public function test_duplicate_cloud_event_id_is_idempotent_without_a_second_effect(): void
    {
        $this->requireAsyncImplementation();
        $event = $this->cloudEvent();

        $this->handler()->handle($event);
        $this->handler()->handle($event);

        $this->assertDatabaseCount('notification_inbox', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_duplicate_inbox_constraint_detection_is_stable_across_supported_drivers(): void
    {
        $matcher = new ReflectionMethod(ConsumeWorkRecordSubmittedHandler::class, 'isDuplicateInboxEvent');
        $handler = $this->handler();

        $cases = [
            [true, ['23505', 7, 'localized unique violation'], 'PostgreSQL unique violation'],
            [true, ['23000', 1062, 'Duplicate entry for an implementation-defined key'], 'MySQL duplicate key'],
            [true, ['23000', 19, 'UNIQUE constraint failed: notification_inbox.event_id'], 'SQLite inbox primary key'],
            [false, ['23000', 1048, 'Column processed_at cannot be null'], 'unrelated MySQL constraint'],
            [false, ['23000', 19, 'NOT NULL constraint failed: notification_inbox.processed_at'], 'unrelated SQLite constraint'],
        ];

        foreach ($cases as [$expected, $errorInfo, $label]) {
            $previous = new PDOException($errorInfo[2]);
            $previous->errorInfo = $errorInfo;
            $exception = new QueryException('test', 'insert into notification_inbox', [], $previous);

            $this->assertSame($expected, $matcher->invoke($handler, $exception), $label);
        }
    }

    public function test_effect_failure_rolls_back_the_new_inbox_receipt(): void
    {
        $this->requireAsyncImplementation();
        $event = $this->cloudEvent();
        DB::table('notifications')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000601',
            'event_id' => $event['id'],
            'recipient_user_id' => $event['data']['record']['owner']['user_id'],
            'title' => 'إشعار موجود',
            'source_record_id' => $event['data']['record']['id'],
            'is_read' => false,
            'created_at' => '2026-07-16 09:00:00',
            'updated_at' => '2026-07-16 09:00:00',
        ]);

        try {
            $this->handler()->handle($event);
            $this->fail('Expected the unique notification association to reject the effect.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('notification_inbox', ['event_id' => $event['id']]);
            $this->assertDatabaseCount('notifications', 1);
        }
    }

    private function handler(): ConsumeWorkRecordSubmittedHandler
    {
        return $this->app->make(ConsumeWorkRecordSubmittedHandler::class);
    }

    private function requireAsyncImplementation(): void
    {
        if (! interface_exists(RedisStreamTransport::class)
            || ! class_exists('Shared\\Infrastructure\\Outbox\\Relay\\RedisOutboxRelay')
            || ! class_exists(ConsumeWorkRecordSubmittedHandler::class)
            || ! class_exists(NotificationsStreamWorker::class)) {
            $this->markTestSkipped('The relay suite owns the deliberate missing-implementation RED marker.');
        }
    }

    /** @param list<string> $queries */
    private function firstQueryIndex(array $queries, string $needle): int
    {
        foreach ($queries as $index => $query) {
            if (str_contains($query, $needle)) {
                return $index;
            }
        }

        $this->fail("Expected query containing {$needle}.");
    }

    /** @return array<string, mixed> */
    private function cloudEvent(): array
    {
        return [
            'specversion' => '1.0',
            'id' => '018f6f7d-0c00-7000-8000-000000000301',
            'source' => '/work-records',
            'type' => 'com.cluster.workrecord.submitted.v1',
            'subject' => '/work-records/018f6f7d-0c00-7000-8000-000000000401',
            'time' => '2026-07-16T09:00:00Z',
            'datacontenttype' => 'application/json',
            'correlationid' => '018f6f7d-0c00-7000-8000-000000000501',
            'data' => [
                'record' => [
                    'id' => '018f6f7d-0c00-7000-8000-000000000401',
                    'record_number' => 'WR-TEST',
                    'work_type_version_id' => '0197f0e0-0000-7000-8000-000000000001',
                    'owner' => [
                        'facility_id' => '018f6f7d-0c00-7000-8000-000000000011',
                        'user_id' => '018f6f7d-0c00-7000-8000-000000000021',
                    ],
                    'status' => 'submitted',
                    'classification' => 'internal',
                    'payload' => ['title' => 'بيانات مصدر لا تحفظ', 'description' => 'وصف سري'],
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
}
