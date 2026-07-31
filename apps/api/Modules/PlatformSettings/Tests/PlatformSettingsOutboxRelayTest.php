<?php

declare(strict_types=1);

namespace Modules\PlatformSettings\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\PlatformSettings\Infrastructure\Outbox\PlatformSettingsOutbox;
use Modules\PlatformSettings\Infrastructure\Outbox\PlatformSettingsOutboxRelay;
use RuntimeException;
use Shared\Contracts\OutboxRelayStore;
use Shared\Contracts\TransactionalOutboxEnvelope;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Tests\TestCase;
use UnexpectedValueException;

final class PlatformSettingsOutboxRelayTest extends TestCase
{
    use RefreshDatabase;

    private const VERSION_ID = '018f6f7d-0c00-7000-8000-000000000941';

    public function test_relay_publishes_version_published_event_to_the_settings_stream_and_marks_it_delivered(): void
    {
        $this->outbox()->append(self::VERSION_ID, 'content-hash-1');
        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldReceive('xadd')
            ->once()
            ->withArgs(function (string $stream, array $fields): bool {
                $this->assertSame(PlatformSettingsOutboxRelay::STREAM, $stream);
                $envelope = json_decode((string) ($fields['event'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame('com.cluster.platform-settings.version-published.v1', $envelope['type']);
                $this->assertSame(self::VERSION_ID, $envelope['data']['version_id']);
                $this->assertNull(DB::table('outbox_events')->where('event_id', $envelope['id'])->value('published_at'), 'XADD must precede markPublished.');

                return true;
            })
            ->andReturn('1784192400000-0');

        $published = $this->relay($transport)->relayPending();

        $this->assertSame(1, $published);
        $eventId = (string) DB::table('outbox_events')->value('event_id');
        $this->assertNotNull(DB::table('outbox_events')->where('event_id', $eventId)->value('published_at'));
    }

    public function test_relay_publishes_backup_requested_and_restore_validation_requested_operations(): void
    {
        $this->outbox()->appendOperationRequested('backup-op-1', 'backup');
        $this->outbox()->appendOperationRequested('restore-op-1', 'restore_validation');
        $transport = Mockery::mock(RedisStreamTransport::class);
        $publishedTypes = [];
        $transport->shouldReceive('xadd')
            ->twice()
            ->withArgs(function (string $stream, array $fields) use (&$publishedTypes): bool {
                $this->assertSame(PlatformSettingsOutboxRelay::STREAM, $stream);
                $envelope = json_decode((string) ($fields['event'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                $publishedTypes[] = $envelope['type'];

                return true;
            })
            ->andReturn('1784192400000-0');

        $published = $this->relay($transport)->relayPending();

        $this->assertSame(2, $published);
        sort($publishedTypes);
        $this->assertSame([
            'com.cluster.platform-operations.backup-requested.v1',
            'com.cluster.platform-operations.restore_validation-requested.v1',
        ], $publishedTypes);
        $this->assertSame(2, DB::table('outbox_events')->whereNotNull('published_at')->count());
    }

    public function test_xadd_failure_keeps_the_event_unpublished_and_retryable(): void
    {
        $this->outbox()->append(self::VERSION_ID, 'content-hash-2');
        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldReceive('xadd')->once()->andThrow(new RuntimeException('injected transport failure'));

        try {
            $this->relay($transport)->relayPending();
            $this->fail('Transport failure must escape the bounded relay cycle.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected transport failure', $exception->getMessage());
        }

        $this->assertNull(DB::table('outbox_events')->value('published_at'), 'markPublished must only run after a successful XADD.');
        $this->assertSame(0, (int) DB::table('outbox_events')->value('delivery_attempts'));
    }

    public function test_relay_rejects_an_invalid_cloud_event_envelope(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-000000000942';
        $now = now();
        DB::table('outbox_events')->insert([
            'event_id' => $eventId,
            'aggregate_id' => '018f6f7d-0c00-7000-8000-000000000943',
            'event_type' => 'com.cluster.platform-settings.version-published.v1',
            'cloud_event' => json_encode([
                'specversion' => '1.0',
                'id' => $eventId,
                'source' => '/platform-settings',
                'type' => 'com.cluster.foreign.unknown.v1',
                'data' => ['version_id' => self::VERSION_ID],
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'published_at' => null,
            'delivery_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $transport = Mockery::mock(RedisStreamTransport::class);
        $transport->shouldNotReceive('xadd');

        try {
            $this->relay($transport)->relayPending();
            $this->fail('A malformed CloudEvent envelope must fail the relay batch.');
        } catch (UnexpectedValueException $exception) {
            $this->assertStringContainsString('is not a valid PlatformSettings CloudEvent', $exception->getMessage());
        }

        $this->assertNull(DB::table('outbox_events')->where('event_id', $eventId)->value('published_at'));
    }

    private function outbox(): PlatformSettingsOutbox
    {
        return new PlatformSettingsOutbox($this->app->make(TransactionalOutboxEnvelope::class));
    }

    private function relay(RedisStreamTransport $transport): PlatformSettingsOutboxRelay
    {
        return new PlatformSettingsOutboxRelay($this->app->make(OutboxRelayStore::class), $transport);
    }
}
