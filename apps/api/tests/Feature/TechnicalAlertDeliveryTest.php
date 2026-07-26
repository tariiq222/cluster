<?php

namespace Tests\Feature;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Notifications\Contracts\ResolveTechnicalAlertRecipients;
use Modules\Notifications\Features\ConsumeTechnicalAlert\Handler\ConsumeTechnicalAlertHandler;
use Modules\Notifications\Features\ConsumeTechnicalAlert\Worker\NotificationsTechnicalAlertWorker;
use Modules\PlatformSettings\Contracts\PublishTechnicalAlert;
use Modules\PlatformSettings\Infrastructure\Outbox\TechnicalAlertOutboxRelay;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Tests\TestCase;

final class TechnicalAlertDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_technical_alert_is_relayed_and_consumed_idempotently_without_user_ids(): void
    {
        $publisher = $this->app->make(PublishTechnicalAlert::class);
        $publisher->publish(
            'database-latency',
            'critical',
            'platform_operations.alerts.manage',
            new DateTimeImmutable('2026-07-23T10:15:00+03:00'),
            '019f8e3b-3368-7192-85a6-3da3949fd75a',
        );

        $transport = new InMemoryTechnicalAlertTransport;
        $this->app->instance(RedisStreamTransport::class, $transport);
        $this->assertSame(1, $this->app->make(TechnicalAlertOutboxRelay::class)->relayPending());
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'com.cluster.platform.technical-alert.v1',
            'published_at' => now(),
        ]);

        $event = json_decode($transport->event, true, 512, JSON_THROW_ON_ERROR);
        $recipients = new FixedTechnicalAlertRecipients([
            '019f8e3b-3368-7192-85a6-3da3949fd763',
            '019f8e3b-3368-7192-85a6-3da3949fd764',
        ]);
        $this->app->instance(ResolveTechnicalAlertRecipients::class, $recipients);
        $consumer = $this->app->make(ConsumeTechnicalAlertHandler::class);
        $this->assertTrue($consumer->handle($event));
        $this->assertFalse($consumer->handle($event));
        $this->assertDatabaseHas('notification_inbox', [
            'event_id' => $event['id'],
            'recipient_capability' => 'platform_operations.alerts.manage',
        ]);
        $this->assertSame(1, $this->app['db']->table('notification_inbox')->count());
        $this->assertDatabaseCount('notifications', 2);
        $this->assertArrayNotHasKey('recipient_user_id', $event['data']);
        $this->assertArrayNotHasKey('user_id', $event['data']);
    }

    public function test_bounded_commands_relay_fan_out_and_dead_letter_an_exhausted_invalid_event(): void
    {
        $this->app->make(PublishTechnicalAlert::class)->publish(
            'database-latency',
            'critical',
            'platform_operations.alerts.manage',
            new DateTimeImmutable('2026-07-23T10:15:00+03:00'),
            '019f8e3b-3368-7192-85a6-3da3949fd75a',
        );
        $transport = new InMemoryTechnicalAlertTransport;
        $this->app->instance(RedisStreamTransport::class, $transport);
        $this->app->instance(ResolveTechnicalAlertRecipients::class, new FixedTechnicalAlertRecipients([
            '019f8e3b-3368-7192-85a6-3da3949fd761',
            '019f8e3b-3368-7192-85a6-3da3949fd762',
        ]));

        $this->assertSame(0, Artisan::call('platform-settings:relay-technical-alerts', ['--once' => true]));
        $this->assertSame(0, Artisan::call('notifications:consume-technical-alert', [
            '--once' => true,
            '--consumer' => 'task7-worker',
        ]));
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => '019f8e3b-3368-7192-85a6-3da3949fd761']);
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => '019f8e3b-3368-7192-85a6-3da3949fd762']);

        $transport->enqueueRaw('{"not":"a technical alert"}', 3);
        $this->assertSame(0, Artisan::call('notifications:consume-technical-alert', [
            '--once' => true,
            '--consumer' => 'task7-worker',
        ]));
        $this->assertDatabaseHas('notification_dead_letters', [
            'failure_code' => 'INVALID_EVENT',
            'attempts' => 3,
            'consumer' => 'task7-worker',
        ]);
        $this->assertNotEmpty($transport->acks);
        $this->assertNotEmpty($transport->deadLetters);
    }

    public function test_worker_leaves_a_retryable_failure_unacknowledged_before_dead_letter_threshold(): void
    {
        $transport = new InMemoryTechnicalAlertTransport;
        $transport->enqueueRaw('{"not":"a technical alert"}', 1);
        $this->app->instance(RedisStreamTransport::class, $transport);

        try {
            $this->app->make(NotificationsTechnicalAlertWorker::class)->consumeOnce('task7-retry-worker');
            $this->fail('Expected retryable invalid event failure.');
        } catch (\InvalidArgumentException) {
            $this->assertSame([], $transport->acks);
            $this->assertSame([], $transport->deadLetters);
        }
    }
}

final class FixedTechnicalAlertRecipients implements ResolveTechnicalAlertRecipients
{
    /** @param list<string> $recipients */
    public function __construct(private readonly array $recipients) {}

    public function resolve(string $recipientCapability): array
    {
        return $this->recipients;
    }
}

final class InMemoryTechnicalAlertTransport implements RedisStreamTransport
{
    public string $event = '';

    /** @var list<array{id: string, fields: array<string, string>, deliveries: int}> */
    private array $messages = [];

    /** @var list<string> */
    public array $acks = [];

    /** @var list<array<string, mixed>> */
    public array $deadLetters = [];

    private int $nextId = 1;

    public function xadd(string $stream, array $fields): string
    {
        $this->event = $fields['event'];
        $id = $this->nextId++.'-0';
        $this->messages[] = ['id' => $id, 'fields' => $fields, 'deliveries' => 1];

        return $id;
    }

    public function createGroup(string $stream, string $group): void {}

    public function readGroup(string $stream, string $group, string $consumer, int $limit): array
    {
        return array_splice($this->messages, 0, $limit);
    }

    public function pending(string $stream, string $group, int $limit): array
    {
        return [];
    }

    public function reclaim(string $stream, string $group, string $consumer, int $minimumIdleMilliseconds, array $messageIds): array
    {
        return [];
    }

    public function ack(string $stream, string $group, string $messageId): void
    {
        $this->acks[] = $messageId;
    }

    public function publishDlq(string $stream, string $sourceMessageId, array $deadLetter): string
    {
        $this->deadLetters[] = $deadLetter;

        return '1-0';
    }

    public function purgeDlq(string $stream): void {}

    public function enqueueRaw(string $event, int $deliveries): void
    {
        $this->messages[] = ['id' => $this->nextId++.'-0', 'fields' => ['event' => $event], 'deliveries' => $deliveries];
    }
}
