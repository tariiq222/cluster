<?php

declare(strict_types=1);

namespace Modules\Documents\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Documents\Infrastructure\Outbox\Relay\DocumentsOutboxRelay;
use RuntimeException;
use Shared\Contracts\OutboxRelayStore;
use Shared\Contracts\TransactionalOutbox;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Tests\TestCase;

final class DocumentsOutboxRelayTest extends TestCase
{
    use RefreshDatabase;

    public function test_relay_publishes_committed_document_event_and_marks_it_delivered(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-000000000931';
        $this->app->make(TransactionalOutbox::class)->append(
            $eventId,
            '018f6f7d-0c00-7000-8000-000000000932',
            'com.cluster.documents.metadataupdated.v1',
            ['document_id' => '018f6f7d-0c00-7000-8000-000000000933'],
        );
        $transport = new RecordingDocumentsStreamTransport;
        $relay = new DocumentsOutboxRelay($this->app->make(OutboxRelayStore::class), $transport);

        $this->assertSame(1, $relay->relayPending());
        $this->assertSame('platform.documents-metadataupdated', $transport->stream);
        $envelope = json_decode($transport->event, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($eventId, $envelope['id']);
        $this->assertNotNull(DB::table('outbox_events')->where('event_id', $eventId)->value('published_at'));
        $this->assertSame(1, (int) DB::table('outbox_events')->where('event_id', $eventId)->value('delivery_attempts'));
    }

    public function test_relay_failure_keeps_document_event_retryable(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-000000000934';
        $this->app->make(TransactionalOutbox::class)->append(
            $eventId,
            '018f6f7d-0c00-7000-8000-000000000935',
            'com.cluster.documents.grantissued.v1',
            ['document_id' => '018f6f7d-0c00-7000-8000-000000000936'],
        );
        $transport = new RecordingDocumentsStreamTransport(true);
        $relay = new DocumentsOutboxRelay($this->app->make(OutboxRelayStore::class), $transport);

        try {
            $relay->relayPending();
            $this->fail('Transport failure must escape the bounded relay cycle.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected transport failure', $exception->getMessage());
        }

        $this->assertNull(DB::table('outbox_events')->where('event_id', $eventId)->value('published_at'));
        $this->assertSame(1, (int) DB::table('outbox_events')->where('event_id', $eventId)->value('delivery_attempts'));
    }
}

final class RecordingDocumentsStreamTransport implements RedisStreamTransport
{
    public string $stream = '';

    public string $event = '';

    public function __construct(private readonly bool $fail = false) {}

    public function xadd(string $stream, array $fields): string
    {
        if ($this->fail) {
            throw new RuntimeException('injected transport failure');
        }
        $this->stream = $stream;
        $this->event = $fields['event'];

        return '1-0';
    }

    public function createGroup(string $stream, string $group): void {}

    public function readGroup(string $stream, string $group, string $consumer, int $limit): array
    {
        return [];
    }

    public function pending(string $stream, string $group, int $limit): array
    {
        return [];
    }

    public function reclaim(string $stream, string $group, string $consumer, int $minimumIdleMilliseconds, array $messageIds): array
    {
        return [];
    }

    public function ack(string $stream, string $group, string $messageId): void {}

    public function publishDlq(string $stream, string $sourceMessageId, array $payload): string
    {
        return '2-0';
    }

    public function purgeDlq(string $stream): void {}
}
