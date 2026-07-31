<?php

namespace Tests\Unit\Shared\Infrastructure\Outbox\Relay;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Shared\Infrastructure\Outbox\DatabaseOutboxRelayStore;
use Shared\Infrastructure\Outbox\Relay\RedisOutboxRelay;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Tests\TestCase;

class RedisOutboxRelayTest extends TestCase
{
    use RefreshDatabase;

    private const STREAM = 'platform.work-record.submitted.v1';

    public function test_async_stream_seam_is_present(): void
    {
        $missing = array_values(array_filter([
            interface_exists(RedisStreamTransport::class) ? null : RedisStreamTransport::class,
            class_exists(RedisOutboxRelay::class) ? null : RedisOutboxRelay::class,
            class_exists('Modules\\Notifications\\Features\\ConsumeWorkRecordSubmitted\\Handler\\ConsumeWorkRecordSubmittedHandler') ? null : 'notification handler',
            class_exists('Modules\\Notifications\\Features\\ConsumeWorkRecordSubmitted\\Worker\\NotificationsStreamWorker') ? null : 'notification worker',
        ]));

        if ($missing !== []) {
            $this->fail('MISSING_ASYNC_STREAM_IMPLEMENTATION');
        }

        $this->assertSame([], $missing);
    }

    public function test_relay_publishes_one_bounded_committed_batch_and_marks_only_xadd_successes(): void
    {
        $this->requireAsyncImplementation();
        $first = $this->cloudEvent('018f6f7d-0c00-7000-8000-000000000301', '018f6f7d-0c00-7000-8000-000000000401');
        $second = $this->cloudEvent('018f6f7d-0c00-7000-8000-000000000302', '018f6f7d-0c00-7000-8000-000000000402');
        $this->insertOutbox($first, '2026-07-16 09:00:00');
        $this->insertOutbox($second, '2026-07-16 09:00:01');

        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldReceive('xadd')
            ->once()
            ->withArgs(function (string $stream, array $fields) use ($first): bool {
                $this->assertSame(self::STREAM, $stream);
                $this->assertSame($first, json_decode((string) ($fields['event'] ?? ''), true, 512, JSON_THROW_ON_ERROR));
                $this->assertNull(DB::table('outbox_events')->where('event_id', $first['id'])->value('published_at'));

                return true;
            })
            ->andReturn('1784192400000-0');
        $this->app->instance(RedisStreamTransport::class, $transport);

        $published = $this->app->make(RedisOutboxRelay::class)->relayPending(1);

        $this->assertSame(1, $published);
        $this->assertNotNull(DB::table('outbox_events')->where('event_id', $first['id'])->value('published_at'));
        $this->assertSame(1, DB::table('outbox_events')->where('event_id', $first['id'])->value('delivery_attempts'));
        $this->assertNull(DB::table('outbox_events')->where('event_id', $second['id'])->value('published_at'));
        $this->assertSame(0, DB::table('outbox_events')->where('event_id', $second['id'])->value('delivery_attempts'));
    }

    public function test_xadd_failure_keeps_the_event_retryable_and_the_once_command_finite(): void
    {
        $this->requireAsyncImplementation();
        $event = $this->cloudEvent('018f6f7d-0c00-7000-8000-000000000303', '018f6f7d-0c00-7000-8000-000000000403');
        $this->insertOutbox($event, '2026-07-16 09:00:00');

        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldReceive('xadd')->once()->andThrow(new RuntimeException('transport unavailable'));
        $this->app->instance(RedisStreamTransport::class, $transport);

        $this->artisan('work-records:relay-pending --once')
            ->assertFailed()
            ->doesntExpectOutputToContain('سر لا يسجل في التشخيص')
            ->doesntExpectOutputToContain('access_context');

        $this->assertNull(DB::table('outbox_events')->where('event_id', $event['id'])->value('published_at'));
        $this->assertSame(
            0,
            (int) DB::table('outbox_events')->where('event_id', $event['id'])->value('delivery_attempts'),
            'claim must be released after XADD failure so the next relay iteration can retry',
        );
    }

    public function test_relay_refuses_to_xadd_when_claim_is_lost_to_a_concurrent_worker(): void
    {
        $this->requireAsyncImplementation();
        $event = $this->cloudEvent('018f6f7d-0c00-7000-8000-000000000304', '018f6f7d-0c00-7000-8000-000000000404');
        $this->insertOutbox($event, '2026-07-16 09:00:00');

        $store = $this->app->make(DatabaseOutboxRelayStore::class);
        $this->assertTrue(
            $store->claim($event['id']),
            'precondition: another worker has already claimed this row',
        );

        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldNotReceive('xadd');
        $this->app->instance(RedisStreamTransport::class, $transport);

        $published = $this->app->make(RedisOutboxRelay::class)->relayPending(10);

        $this->assertSame(0, $published, 'relay must not report a publish when the claim was lost');
        $this->assertNull(
            DB::table('outbox_events')->where('event_id', $event['id'])->value('published_at'),
            'relay must not flip published_at on a row it did not claim',
        );
    }

    public function test_relay_releases_claim_when_xadd_throws_so_the_event_is_retryable(): void
    {
        $this->requireAsyncImplementation();
        $event = $this->cloudEvent('018f6f7d-0c00-7000-8000-000000000305', '018f6f7d-0c00-7000-8000-000000000405');
        $this->insertOutbox($event, '2026-07-16 09:00:00');

        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldReceive('xadd')->once()->andThrow(new RuntimeException('transport unavailable'));
        $this->app->instance(RedisStreamTransport::class, $transport);

        try {
            $this->app->make(RedisOutboxRelay::class)->relayPending(10);
            $this->fail('XADD failure must propagate so the once-command fails.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNull(
            DB::table('outbox_events')->where('event_id', $event['id'])->value('published_at'),
            'XADD failure must not leak into published_at',
        );
        $this->assertSame(
            0,
            (int) DB::table('outbox_events')->where('event_id', $event['id'])->value('delivery_attempts'),
            'claim must be released after XADD failure so the next claim can win',
        );

        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldReceive('xadd')->once()->andReturn('1784192400000-0');
        $this->app->instance(RedisStreamTransport::class, $transport);

        $this->assertSame(
            1,
            $this->app->make(RedisOutboxRelay::class)->relayPending(10),
            'the second relay must successfully claim and publish the still-pending event',
        );
        $this->assertNotNull(
            DB::table('outbox_events')->where('event_id', $event['id'])->value('published_at'),
        );
    }

    public function test_two_relays_acting_on_the_same_row_xadd_at_most_once_in_a_single_iteration(): void
    {
        $this->requireAsyncImplementation();
        $event = $this->cloudEvent('018f6f7d-0c00-7000-8000-000000000306', '018f6f7d-0c00-7000-8000-000000000406');
        $this->insertOutbox($event, '2026-07-16 09:00:00');

        $firstSeen = false;
        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldReceive('xadd')
            ->zeroOrMoreTimes()
            ->withArgs(function (string $stream, array $fields) use (&$firstSeen, $event): bool {
                if ($firstSeen) {
                    $this->fail('Two relays XADDed the same event id; the atomic claim did not gate the second relay.');
                }
                $firstSeen = true;

                $this->assertSame(self::STREAM, $stream);
                $this->assertSame($event['id'], json_decode((string) ($fields['event'] ?? '{}'), true)['id'] ?? null);

                return true;
            })
            ->andReturn('1784192400000-0');
        $this->app->instance(RedisStreamTransport::class, $transport);

        $relay = $this->app->make(RedisOutboxRelay::class);
        $publishedA = $relay->relayPending(10);
        $publishedB = $relay->relayPending(10);

        $this->assertSame(1, $publishedA, 'first relay must publish the row exactly once');
        $this->assertSame(0, $publishedB, 'second relay must observe the row is already published and skip XADD');
        $this->assertSame(
            1,
            (int) DB::table('outbox_events')->where('event_id', $event['id'])->value('delivery_attempts'),
        );
    }

    public function test_relay_command_rejects_accidental_unbounded_execution(): void
    {
        $this->requireAsyncImplementation();

        $this->artisan('work-records:relay-pending')->assertFailed();
    }

    private function requireAsyncImplementation(): void
    {
        if (! interface_exists(RedisStreamTransport::class)
            || ! class_exists(RedisOutboxRelay::class)
            || ! class_exists('Modules\\Notifications\\Features\\ConsumeWorkRecordSubmitted\\Handler\\ConsumeWorkRecordSubmittedHandler')
            || ! class_exists('Modules\\Notifications\\Features\\ConsumeWorkRecordSubmitted\\Worker\\NotificationsStreamWorker')) {
            $this->markTestSkipped('The deliberate missing-implementation test owns the RED marker.');
        }
    }

    /** @param array<string, mixed> $event */
    private function insertOutbox(array $event, string $occurredAt): void
    {
        DB::table('outbox_events')->insert([
            'event_id' => $event['id'],
            'aggregate_id' => $event['data']['record']['id'],
            'event_type' => $event['type'],
            'cloud_event' => json_encode($event, JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
            'published_at' => null,
            'delivery_attempts' => 0,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function cloudEvent(string $eventId, string $recordId): array
    {
        return [
            'specversion' => '1.0',
            'id' => $eventId,
            'source' => '/work-records',
            'type' => 'com.cluster.workrecord.submitted.v1',
            'subject' => '/work-records/'.$recordId,
            'time' => '2026-07-16T09:00:00Z',
            'datacontenttype' => 'application/json',
            'correlationid' => '018f6f7d-0c00-7000-8000-000000000501',
            'data' => [
                'record' => [
                    'id' => $recordId,
                    'record_number' => 'WR-TEST',
                    'owner' => [
                        'facility_id' => '018f6f7d-0c00-7000-8000-000000000011',
                        'user_id' => '018f6f7d-0c00-7000-8000-000000000021',
                    ],
                    'payload' => ['title' => 'سر لا يسجل في التشخيص'],
                ],
                'access_context' => ['owner_facility_id' => '018f6f7d-0c00-7000-8000-000000000011'],
                'classification' => 'internal',
            ],
        ];
    }
}
