<?php

namespace Tests\Unit\Support;

use Shared\Infrastructure\Streams\RedisStreamTransport;
use Tests\Support\Streams\BindsInMemoryRedisStreamTransport;
use Tests\Support\Streams\InMemoryRedisStreamTransport;
use Tests\TestCase;

class InMemoryRedisStreamTransportTest extends TestCase
{
    use BindsInMemoryRedisStreamTransport;

    private const STREAM = 'platform.work-record.submitted.v1';

    private const GROUP = 'notifications.work-record-submitted.v1';

    public function test_test_helper_explicitly_replaces_the_normal_container_binding(): void
    {
        $inMemory = new \Tests\Support\Streams\InMemoryRedisStreamTransport;
        $this->bindInMemoryRedisStreamTransport($inMemory);

        $this->assertSame($inMemory, $this->app->make(RedisStreamTransport::class));
    }

    public function test_xadd_generates_stable_ordered_ids_for_each_millisecond(): void
    {
        $now = 1_784_198_760_000;
        $transport = new InMemoryRedisStreamTransport(static function () use (&$now): int {
            return $now;
        });

        $this->assertSame('1784198760000-0', $transport->xadd(self::STREAM, ['event' => 'one']));
        $this->assertSame('1784198760000-1', $transport->xadd(self::STREAM, ['event' => 'two']));

        $now++;

        $this->assertSame('1784198760001-0', $transport->xadd(self::STREAM, ['event' => 'three']));
    }

    public function test_group_creation_is_idempotent_and_new_reads_become_pending(): void
    {
        $now = 1_784_198_760_000;
        $transport = new InMemoryRedisStreamTransport(static function () use (&$now): int {
            return $now;
        });
        $firstId = $transport->xadd(self::STREAM, ['event' => 'one']);
        $secondId = $transport->xadd(self::STREAM, ['event' => 'two']);

        $transport->createGroup(self::STREAM, self::GROUP);
        $transport->createGroup(self::STREAM, self::GROUP);

        $this->assertSame([
            ['id' => $firstId, 'fields' => ['event' => 'one'], 'deliveries' => 1],
        ], $transport->readGroup(self::STREAM, self::GROUP, 'consumer-a', 1));
        $this->assertSame([
            ['id' => $secondId, 'fields' => ['event' => 'two'], 'deliveries' => 1],
        ], $transport->readGroup(self::STREAM, self::GROUP, 'consumer-a', 10));
        $this->assertSame([], $transport->readGroup(self::STREAM, self::GROUP, 'consumer-a', 10));
        $this->assertSame([
            ['id' => $firstId, 'consumer' => 'consumer-a', 'idle_ms' => 0, 'deliveries' => 1],
            ['id' => $secondId, 'consumer' => 'consumer-a', 'idle_ms' => 0, 'deliveries' => 1],
        ], $transport->pending(self::STREAM, self::GROUP, 10));
    }

    public function test_reclaim_respects_idle_time_changes_owner_and_ack_removes_pending(): void
    {
        $now = 1_784_198_760_000;
        $transport = new InMemoryRedisStreamTransport(static function () use (&$now): int {
            return $now;
        });
        $messageId = $transport->xadd(self::STREAM, ['event' => 'one']);
        $transport->createGroup(self::STREAM, self::GROUP);
        $transport->readGroup(self::STREAM, self::GROUP, 'consumer-a', 1);

        $now += 59_999;
        $this->assertSame([], $transport->reclaim(self::STREAM, self::GROUP, 'consumer-b', 60_000, [$messageId]));

        $now++;
        $this->assertSame([
            ['id' => $messageId, 'fields' => ['event' => 'one'], 'deliveries' => 2],
        ], $transport->reclaim(self::STREAM, self::GROUP, 'consumer-b', 60_000, [$messageId]));
        $this->assertSame('consumer-b', $transport->pending(self::STREAM, self::GROUP, 1)[0]['consumer']);
        $this->assertSame(2, $transport->pending(self::STREAM, self::GROUP, 1)[0]['deliveries']);

        $transport->ack(self::STREAM, self::GROUP, $messageId);

        $this->assertSame([], $transport->pending(self::STREAM, self::GROUP, 10));
    }

    public function test_dlq_publication_uses_a_distinct_ordered_stream(): void
    {
        $transport = new InMemoryRedisStreamTransport(static fn (): int => 1_784_198_760_000);
        $transport->xadd(self::STREAM, ['event' => 'source']);

        $firstId = $transport->publishDlq('platform.dlq.v1', 'source-1', ['failure_code' => 'INVALID_EVENT']);
        $duplicateId = $transport->publishDlq('platform.dlq.v1', 'source-1', ['failure_code' => 'MUST_NOT_DUPLICATE']);
        $secondId = $transport->publishDlq('platform.dlq.v1', 'source-2', ['failure_code' => 'PROCESSING_FAILED']);

        $this->assertSame('1784198760000-0', $firstId);
        $this->assertSame($firstId, $duplicateId);
        $this->assertSame('1784198760000-1', $secondId);
        $this->assertCount(1, $transport->streamEntries(self::STREAM));
        $this->assertSame(
            ['INVALID_EVENT', 'PROCESSING_FAILED'],
            array_map(
                static fn (array $entry): string => json_decode($entry['fields']['event'], true, 512, JSON_THROW_ON_ERROR)['failure_code'],
                $transport->streamEntries('platform.dlq.v1'),
            ),
        );

        $transport->purgeDlq('platform.dlq.v1');
        $this->assertSame([], $transport->streamEntries('platform.dlq.v1'));
        $this->assertSame(
            '1784198760000-0',
            $transport->publishDlq('platform.dlq.v1', 'source-1', ['failure_code' => 'REPLAY_AFTER_PURGE']),
        );
    }
}
