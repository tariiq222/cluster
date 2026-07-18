<?php

namespace Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Handler\ConsumeWorkRecordSubmittedHandler;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Worker\NotificationsStreamWorker;
use RuntimeException;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Tests\TestCase;

class NotificationsStreamWorkerTest extends TestCase
{
    use RefreshDatabase;

    private const STREAM = 'platform.work-record.submitted.v1';

    private const GROUP = 'notifications.work-record-submitted.v1';

    private const DLQ = 'platform.dlq.v1';

    private const MESSAGE_ID = '1784192400000-0';

    public function test_worker_creates_the_group_reads_one_bounded_cycle_and_acks_only_after_commit(): void
    {
        $this->requireAsyncImplementation();
        $event = $this->cloudEvent();
        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldReceive('createGroup')->once()->with(self::STREAM, self::GROUP);
        $transport->shouldReceive('pending')->once()->with(self::STREAM, self::GROUP, 10)->andReturn([]);
        $transport->shouldReceive('readGroup')
            ->once()
            ->with(self::STREAM, self::GROUP, 'worker-a', 10)
            ->andReturn([$this->message($event)]);
        $transport->shouldReceive('ack')
            ->once()
            ->with(self::STREAM, self::GROUP, self::MESSAGE_ID)
            ->andReturnUsing(function () use ($event): void {
                $this->assertSame(1, DB::table('notification_inbox')->where('event_id', $event['id'])->count());
                $this->assertSame(1, DB::table('notifications')->where('event_id', $event['id'])->count());
            });
        $this->app->instance(RedisStreamTransport::class, $transport);

        $processed = $this->worker()->consumeOnce('worker-a', 10);

        $this->assertSame(1, $processed);
        $this->assertDatabaseCount('notification_inbox', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_post_commit_pre_ack_crash_is_reclaimed_without_a_second_effect(): void
    {
        $this->requireAsyncImplementation();
        $event = $this->cloudEvent();
        $firstTransport = Mockery::mock(RedisStreamTransport::class);
        $firstTransport->shouldReceive('createGroup')->once()->with(self::STREAM, self::GROUP);
        $firstTransport->shouldReceive('pending')->once()->with(self::STREAM, self::GROUP, 10)->andReturn([]);
        $firstTransport->shouldReceive('readGroup')
            ->once()
            ->with(self::STREAM, self::GROUP, 'worker-a', 10)
            ->andReturn([$this->message($event)]);
        $firstTransport->shouldReceive('ack')
            ->once()
            ->with(self::STREAM, self::GROUP, self::MESSAGE_ID)
            ->andThrow(new RuntimeException('CONTROLLED_POST_COMMIT_PRE_ACK_CRASH'));
        $this->app->instance(RedisStreamTransport::class, $firstTransport);

        try {
            $this->worker()->consumeOnce('worker-a', 10);
            $this->fail('Expected the controlled post-commit/pre-ack crash.');
        } catch (RuntimeException $exception) {
            $this->assertSame('CONTROLLED_POST_COMMIT_PRE_ACK_CRASH', $exception->getMessage());
        }

        $this->assertDatabaseCount('notification_inbox', 1);
        $this->assertDatabaseCount('notifications', 1);

        $restartedTransport = Mockery::mock(RedisStreamTransport::class);
        $restartedTransport->shouldReceive('createGroup')->once()->with(self::STREAM, self::GROUP);
        $restartedTransport->shouldReceive('pending')->once()->with(self::STREAM, self::GROUP, 10)->andReturn([[
            'id' => self::MESSAGE_ID,
            'consumer' => 'worker-a',
            'idle_ms' => 60_001,
            'deliveries' => 2,
        ]]);
        $restartedTransport->shouldReceive('reclaim')
            ->once()
            ->with(self::STREAM, self::GROUP, 'worker-b', 60_000, [self::MESSAGE_ID])
            ->andReturn([$this->message($event, 2)]);
        $restartedTransport->shouldReceive('readGroup')
            ->once()
            ->with(self::STREAM, self::GROUP, 'worker-b', 9)
            ->andReturn([]);
        $restartedTransport->shouldReceive('ack')
            ->once()
            ->with(self::STREAM, self::GROUP, self::MESSAGE_ID)
            ->andReturnUsing(function (): void {
                $this->assertDatabaseCount('notification_inbox', 1);
                $this->assertDatabaseCount('notifications', 1);
            });
        $this->app->instance(RedisStreamTransport::class, $restartedTransport);

        $processed = $this->worker()->consumeOnce('worker-b', 10);

        $this->assertSame(1, $processed);
        $this->assertDatabaseCount('notification_inbox', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_exhausted_invalid_event_publishes_the_asyncapi_dlq_envelope_before_ack(): void
    {
        $this->requireAsyncImplementation();
        $event = $this->cloudEvent();
        $event['type'] = 'com.cluster.workrecord.invalid.v1';
        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldReceive('createGroup')->once()->with(self::STREAM, self::GROUP);
        $transport->shouldReceive('pending')->once()->with(self::STREAM, self::GROUP, 10)->andReturn([]);
        $transport->shouldReceive('readGroup')
            ->once()
            ->with(self::STREAM, self::GROUP, 'worker-poison', 10)
            ->andReturn([$this->message($event, 3)]);
        $transport->shouldReceive('publishDlq')
            ->once()
            ->withArgs(function (string $stream, string $sourceMessageId, array $deadLetter) use ($event): bool {
                $this->assertSame(self::DLQ, $stream);
                $this->assertSame(self::STREAM.'|'.self::MESSAGE_ID, $sourceMessageId);
                $this->assertSame($event, $deadLetter['original_event'] ?? null);
                $this->assertIsString($deadLetter['failure_code'] ?? null);
                $this->assertNotSame('', $deadLetter['failure_code']);
                $this->assertSame(3, $deadLetter['attempts'] ?? null);
                $this->assertMatchesRegularExpression('/Z$/', (string) ($deadLetter['failed_at'] ?? ''));
                $this->assertSame('worker-poison', $deadLetter['consumer'] ?? null);

                return true;
            })
            ->ordered()
            ->andReturn('1784192400001-0');
        $transport->shouldReceive('ack')
            ->once()
            ->with(self::STREAM, self::GROUP, self::MESSAGE_ID)
            ->ordered();
        $this->app->instance(RedisStreamTransport::class, $transport);

        $processed = $this->worker()->consumeOnce('worker-poison', 10);

        $this->assertSame(1, $processed);
        $this->assertDatabaseCount('notification_inbox', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_exhausted_malformed_entry_publishes_a_reviewable_dlq_envelope_before_ack(): void
    {
        $this->requireAsyncImplementation();
        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldReceive('createGroup')->once()->with(self::STREAM, self::GROUP);
        $transport->shouldReceive('pending')->once()->with(self::STREAM, self::GROUP, 10)->andReturn([]);
        $transport->shouldReceive('readGroup')
            ->once()
            ->with(self::STREAM, self::GROUP, 'worker-malformed', 10)
            ->andReturn([$this->malformedMessage('{"secret":', 3)]);
        $transport->shouldReceive('publishDlq')
            ->once()
            ->withArgs(function (string $stream, string $sourceMessageId, array $deadLetter): bool {
                $this->assertSame(self::DLQ, $stream);
                $this->assertSame(self::STREAM.'|'.self::MESSAGE_ID, $sourceMessageId);
                $this->assertSame('MALFORMED_EVENT', $deadLetter['failure_code'] ?? null);
                $this->assertSame(['stream_id', 'raw_payload'], array_keys($deadLetter['original_event'] ?? []));
                $this->assertSame(self::MESSAGE_ID, $deadLetter['original_event']['stream_id'] ?? null);
                $this->assertSame('{"secret":', $deadLetter['original_event']['raw_payload'] ?? null);
                $this->assertSame(3, $deadLetter['attempts'] ?? null);

                return true;
            })
            ->ordered()
            ->andReturn('1784192400001-0');
        $transport->shouldReceive('ack')
            ->once()
            ->with(self::STREAM, self::GROUP, self::MESSAGE_ID)
            ->ordered();
        $this->app->instance(RedisStreamTransport::class, $transport);

        $this->assertSame(1, $this->worker()->consumeOnce('worker-malformed', 10));
    }

    public function test_malformed_entry_remains_pending_when_dlq_publication_fails(): void
    {
        $this->requireAsyncImplementation();
        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldReceive('createGroup')->once()->with(self::STREAM, self::GROUP);
        $transport->shouldReceive('pending')->once()->with(self::STREAM, self::GROUP, 10)->andReturn([]);
        $transport->shouldReceive('readGroup')
            ->once()
            ->with(self::STREAM, self::GROUP, 'worker-malformed', 10)
            ->andReturn([$this->malformedMessage('not-json', 3)]);
        $transport->shouldReceive('publishDlq')
            ->once()
            ->andThrow(new RuntimeException('DLQ_UNAVAILABLE'));
        $transport->shouldNotReceive('ack');
        $this->app->instance(RedisStreamTransport::class, $transport);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DLQ_UNAVAILABLE');
        $this->worker()->consumeOnce('worker-malformed', 10);
    }

    public function test_consumer_command_requires_once_and_a_named_consumer_then_exits(): void
    {
        $this->requireAsyncImplementation();
        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldReceive('createGroup')->once()->with(self::STREAM, self::GROUP);
        $transport->shouldReceive('pending')->once()->with(self::STREAM, self::GROUP, 10)->andReturn([]);
        $transport->shouldReceive('readGroup')
            ->once()
            ->with(self::STREAM, self::GROUP, 'worker-command', 10)
            ->andReturn([]);
        $this->app->instance(RedisStreamTransport::class, $transport);

        $this->artisan('notifications:consume-work-record-submitted --once --consumer=worker-command')
            ->assertSuccessful();
        $this->artisan('notifications:consume-work-record-submitted --consumer=worker-command')->assertFailed();
        $this->artisan('notifications:consume-work-record-submitted --once')->assertFailed();
    }

    private function worker(): NotificationsStreamWorker
    {
        $this->app->forgetInstance(NotificationsStreamWorker::class);

        return $this->app->make(NotificationsStreamWorker::class);
    }

    private function requireAsyncImplementation(): void
    {
        if (! interface_exists(RedisStreamTransport::class)
            || ! class_exists('Modules\\WorkRecords\\Infrastructure\\Outbox\\Relay\\RedisOutboxRelay')
            || ! class_exists(ConsumeWorkRecordSubmittedHandler::class)
            || ! class_exists(NotificationsStreamWorker::class)) {
            $this->markTestSkipped('The relay suite owns the deliberate missing-implementation RED marker.');
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{id: string, fields: array{event: string}, deliveries: int}
     */
    private function message(array $event, int $deliveries = 1): array
    {
        return [
            'id' => self::MESSAGE_ID,
            'fields' => ['event' => json_encode($event, JSON_THROW_ON_ERROR)],
            'deliveries' => $deliveries,
        ];
    }

    /** @return array{id: string, fields: array{event: string}, deliveries: int} */
    private function malformedMessage(string $payload, int $deliveries = 1): array
    {
        return [
            'id' => self::MESSAGE_ID,
            'fields' => ['event' => $payload],
            'deliveries' => $deliveries,
        ];
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
