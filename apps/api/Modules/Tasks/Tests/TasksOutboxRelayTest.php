<?php

declare(strict_types=1);

namespace Modules\Tasks\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tasks\Infrastructure\Outbox\Relay\TasksOutboxRelay;
use Shared\Contracts\TransactionalOutbox;
use Shared\Infrastructure\Outbox\OutboxEventType;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Tests\TestCase;

final class TasksOutboxRelayTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_created_and_lifecycle_event_names_are_canonical_catalogue_entries(): void
    {
        $this->assertSame('com.cluster.tasks.created.v1', OutboxEventType::TaskCreated->value);
        $this->assertSame('com.cluster.tasks.started.v1', OutboxEventType::TaskStarted->value);
        $this->assertSame('com.cluster.tasks.completed.v1', OutboxEventType::TaskCompleted->value);
        $this->assertSame('com.cluster.tasks.cancelled.v1', OutboxEventType::TaskCancelled->value);
    }

    public function test_relay_is_bounded_and_marks_only_successfully_published_task_rows(): void
    {
        $outbox = $this->app->make(TransactionalOutbox::class);
        $createdId = (string) Str::uuid7();
        $completedId = (string) Str::uuid7();
        $outbox->append($createdId, (string) Str::uuid7(), OutboxEventType::TaskCreated->value, ['task_id' => 'task-1']);
        $outbox->append($completedId, (string) Str::uuid7(), OutboxEventType::TaskCompleted->value, ['task_id' => 'task-2']);
        $transport = new RecordingTaskStreamTransport(function () use ($createdId): void {
            $this->assertNull(DB::table('outbox_events')->where('event_id', $createdId)->value('published_at'));
        });

        $published = (new TasksOutboxRelay($this->app->make(\Shared\Contracts\OutboxRelayStore::class), $transport))->relayPending(1);

        $this->assertSame(1, $published);
        $this->assertNotNull(DB::table('outbox_events')->where('event_id', $createdId)->value('published_at'));
        $this->assertNull(DB::table('outbox_events')->where('event_id', $completedId)->value('published_at'));
        $this->assertSame([TasksOutboxRelay::STREAM], $transport->streams);
    }

    public function test_relay_failure_leaves_task_row_unpublished_for_retry(): void
    {
        $eventId = (string) Str::uuid7();
        $this->app->make(TransactionalOutbox::class)->append(
            $eventId,
            (string) Str::uuid7(),
            OutboxEventType::TaskStarted->value,
            ['task_id' => 'task-1'],
        );

        try {
            (new TasksOutboxRelay(
                $this->app->make(\Shared\Contracts\OutboxRelayStore::class),
                new FailingTaskStreamTransport,
            ))->relayPending();
            $this->fail('Expected stream publication to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('stream unavailable', $exception->getMessage());
        }

        $this->assertNull(DB::table('outbox_events')->where('event_id', $eventId)->value('published_at'));
    }

    public function test_forward_migration_canonicalizes_only_unpublished_legacy_task_events(): void
    {
        $unpublished = (string) Str::uuid7();
        $published = (string) Str::uuid7();
        $this->insertLegacyEvent($unpublished, 'task.created.v1', null);
        $this->insertLegacyEvent($published, 'task.complete.v1', now());

        $migration = require base_path('Shared/Infrastructure/Outbox/Migrations/W28CanonicalizeTaskOutboxEventTypes.php');
        $migration->up();

        $rewritten = DB::table('outbox_events')->where('event_id', $unpublished)->first();
        $historical = DB::table('outbox_events')->where('event_id', $published)->first();
        $this->assertSame('com.cluster.tasks.created.v1', $rewritten->event_type);
        $this->assertSame('com.cluster.tasks.created.v1', json_decode((string) $rewritten->cloud_event, true, 512, JSON_THROW_ON_ERROR)['type']);
        $this->assertSame('task.complete.v1', $historical->event_type);
        $this->assertSame('task.complete.v1', json_decode((string) $historical->cloud_event, true, 512, JSON_THROW_ON_ERROR)['type']);
    }

    private function insertLegacyEvent(string $eventId, string $type, mixed $publishedAt): void
    {
        DB::table('outbox_events')->insert([
            'event_id' => $eventId,
            'aggregate_id' => (string) Str::uuid7(),
            'event_type' => $type,
            'cloud_event' => json_encode([
                'specversion' => '1.0',
                'id' => $eventId,
                'source' => '/'.$type,
                'type' => $type,
                'subject' => '/task',
                'data' => ['task_id' => 'task'],
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'published_at' => $publishedAt,
            'delivery_attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

final class RecordingTaskStreamTransport implements RedisStreamTransport
{
    /** @var list<string> */
    public array $streams = [];

    public function __construct(private readonly \Closure $beforePublish) {}

    public function xadd(string $stream, array $fields): string
    {
        ($this->beforePublish)();
        $this->streams[] = $stream;

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

    public function publishDlq(string $stream, string $sourceMessageId, array $deadLetter): string
    {
        return '1-0';
    }

    public function purgeDlq(string $stream): void {}
}

final class FailingTaskStreamTransport implements RedisStreamTransport
{
    public function xadd(string $stream, array $fields): string
    {
        throw new \RuntimeException('stream unavailable');
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

    public function publishDlq(string $stream, string $sourceMessageId, array $deadLetter): string
    {
        return '1-0';
    }

    public function purgeDlq(string $stream): void {}
}
