<?php

declare(strict_types=1);

namespace Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Handler\ConsumeWorkRecordSubmittedHandler;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Worker\NotificationsStreamWorker;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Tests\TestCase;

/**
 * Read-notification re-surface: the aggregation code in
 * ConsumeWorkRecordSubmittedHandler::incrementAggregation must flip a
 * notification that the recipient marked read back to unread when a new
 * event for the same group key arrives, and bump the aggregation count.
 */
final class ConsumeWorkRecordSubmittedResurfaceTest extends TestCase
{
    use RefreshDatabase;

    private const RECIPIENT_USER_ID = '018f6f7d-0c00-7000-8000-000000000021';

    private const RECORD_ID = '018f6f7d-0c00-7000-8000-000000000401';

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000031';

    public function test_read_notification_resurfaces_unread_when_the_same_group_event_arrives_again(): void
    {
        $this->requireAsyncImplementation();
        $token = $this->postJson('/api/v1/auth/login', [
            'username' => 'fixture-account-a',
            'password' => 'fixture-password-a',
        ], ['X-Correlation-ID' => self::CORRELATION_ID])->assertOk()->json('data.access_token');

        $handler = $this->app->make(ConsumeWorkRecordSubmittedHandler::class);
        $handler->handle($this->cloudEvent('018f6f7d-0c00-7000-8000-000000000301', '2026-07-16T09:00:00Z'));
        $notificationId = (string) DB::table('notifications')->where('recipient_user_id', self::RECIPIENT_USER_ID)->value('id');

        $markRead = $this->withToken($token)->postJson('/api/v1/notifications/'.$notificationId.'/read', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'Idempotency-Key' => 'resurface-read-1',
        ]);
        $markRead->assertOk()->assertExactJson(['data' => ['id' => $notificationId, 'is_read' => true]]);
        $this->assertTrue((bool) DB::table('notifications')->where('id', $notificationId)->value('is_read'));
        $this->assertSame('read', (string) DB::table('notifications')->where('id', $notificationId)->value('status'));

        $handler->handle($this->cloudEvent('018f6f7d-0c00-7000-8000-000000000302', '2026-07-16T09:05:00Z'));

        $row = DB::table('notifications')->where('id', $notificationId)->first();
        $this->assertNotNull($row);
        $this->assertSame(1, DB::table('notifications')->where('recipient_user_id', self::RECIPIENT_USER_ID)->count(), 'Same-group events must aggregate into one notification.');
        $this->assertFalse((bool) $row->is_read, 'A new same-group event must re-surface the notification as unread.');
        $this->assertSame('unread', (string) $row->status);
        $this->assertSame(2, (int) $row->aggregation_count);
        $this->assertSame('018f6f7d-0c00-7000-8000-000000000302', (string) $row->last_event_id);
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

    /** @return array<string, mixed> */
    private function cloudEvent(string $eventId, string $time): array
    {
        return [
            'specversion' => '1.0',
            'id' => $eventId,
            'source' => '/work-records',
            'type' => 'com.cluster.workrecord.submitted.v1',
            'subject' => '/work-records/'.self::RECORD_ID,
            'time' => $time,
            'datacontenttype' => 'application/json',
            'correlationid' => '018f6f7d-0c00-7000-8000-000000000501',
            'data' => [
                'record' => [
                    'id' => self::RECORD_ID,
                    'record_number' => 'WR-TEST',
                    'work_type_version_id' => '0197f0e0-0000-7000-8000-000000000001',
                    'owner' => [
                        'facility_id' => '018f6f7d-0c00-7000-8000-000000000011',
                        'user_id' => self::RECIPIENT_USER_ID,
                    ],
                    'status' => 'submitted',
                    'classification' => 'internal',
                    'payload' => ['title' => 'بيانات مصدر لا تحفظ'],
                    'lock_version' => 1,
                    'submitted_at' => $time,
                    'created_at' => $time,
                    'updated_at' => $time,
                ],
                'access_context' => ['owner_facility_id' => '018f6f7d-0c00-7000-8000-000000000011'],
                'classification' => 'internal',
            ],
        ];
    }
}
