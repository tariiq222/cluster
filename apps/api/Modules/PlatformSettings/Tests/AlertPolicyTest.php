<?php

namespace Modules\PlatformSettings\Tests;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\PlatformSettings\Contracts\PublishTechnicalAlert;
use Modules\PlatformSettings\Domain\AlertPolicy;
use Modules\PlatformSettings\Features\Alerts\Handler\AlertPolicyHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AlertPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('supportedRoutingValues')]
    public function test_policy_accepts_every_supported_routing_value(string $severity, string $channel): void
    {
        $policy = new AlertPolicy(
            code: 'database-latency',
            severity: $severity,
            channel: $channel,
            recipientCapability: 'platform_operations.alerts.manage',
            escalationMinutes: 15,
        );

        $this->assertSame($severity, $policy->severity);
        $this->assertSame($channel, $policy->channel);
        $this->assertSame('platform_operations.alerts.manage', $policy->recipientCapability);
    }

    /** @return array<string, array{string, string}> */
    public static function supportedRoutingValues(): array
    {
        return [
            'info in-app' => ['info', 'in_app'],
            'warning in-app' => ['warning', 'in_app'],
            'critical email' => ['critical', 'email'],
        ];
    }

    #[DataProvider('invalidRoutingValues')]
    public function test_policy_rejects_invalid_routing_values(string $severity, string $channel, int $escalationMinutes): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AlertPolicy('database-latency', $severity, $channel, 'platform_operations.alerts.manage', $escalationMinutes);
    }

    /** @return array<string, array{string, string, int}> */
    public static function invalidRoutingValues(): array
    {
        return [
            'unsupported severity' => ['urgent', 'in_app', 10],
            'unsupported channel' => ['warning', 'sms', 10],
            'zero escalation' => ['info', 'in_app', 0],
        ];
    }

    public function test_policy_rejects_a_user_id_as_recipient_selector(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AlertPolicy('database-latency', 'warning', 'in_app', '018f6f7d-0c00-7000-8000-000000000021', 10);
    }

    public function test_publishing_a_technical_alert_writes_the_minimal_notifications_outbox_contract(): void
    {
        $handler = $this->app->make(PublishTechnicalAlert::class);
        $this->assertInstanceOf(AlertPolicyHandler::class, $handler);
        $occurredAt = new DateTimeImmutable('2026-07-23T10:15:00+03:00');
        $correlationId = '019f8e3b-3368-7192-85a6-3da3949fd75a';

        $handler->publish(
            alertCode: 'database-latency',
            severity: 'critical',
            recipientCapability: 'platform_operations.alerts.manage',
            occurredAt: $occurredAt,
            correlationId: $correlationId,
        );

        $outbox = $this->app['db']->table('outbox_events')->first();
        $this->assertSame('com.cluster.platform.technical-alert.v1', $outbox->event_type);
        $envelope = json_decode((string) $outbox->cloud_event, true, 512, JSON_THROW_ON_ERROR);
        $payload = $envelope['data'];
        $this->assertSame([
            'alert_code' => 'database-latency',
            'severity' => 'critical',
            'recipient_capability' => 'platform_operations.alerts.manage',
            'occurred_at' => '2026-07-23T07:15:00+00:00',
            'correlation_id' => $correlationId,
        ], $payload);
        $this->assertArrayNotHasKey('secret', $payload);
        $this->assertArrayNotHasKey('stack_trace', $payload);
    }
}
