<?php

namespace Modules\PlatformSettings\Features\Alerts\Handler;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\PlatformSettings\Contracts\PublishTechnicalAlert;
use Modules\PlatformSettings\Contracts\ValidateTechnicalAlertRecipientCapability;
use Modules\PlatformSettings\Domain\AlertPolicy;

final class AlertPolicyHandler implements PublishTechnicalAlert
{
    public const TECHNICAL_ALERT = 'com.cluster.platform.technical-alert.v1';

    public function __construct(private readonly ValidateTechnicalAlertRecipientCapability $capabilities) {}

    /** @return array{id: string, code: string, severity: string, channel: string, recipient_capability: string, escalation_minutes: int} */
    public function create(
        string $code,
        string $severity,
        string $channel,
        string $recipientCapability,
        int $escalationMinutes,
    ): array {
        $policy = new AlertPolicy($code, $severity, $channel, $recipientCapability, $escalationMinutes);
        $this->capabilities->assertSupported($policy->recipientCapability);
        $id = Str::uuid7()->toString();
        $now = now();
        DB::table('platform_alert_policies')->insert([
            'id' => $id,
            'code' => $policy->code,
            'status' => 'active',
            'severity' => $policy->severity,
            'channel' => $policy->channel,
            'routing_policy' => json_encode(['recipient_capability' => $policy->recipientCapability], JSON_THROW_ON_ERROR),
            'escalation_policy' => json_encode(['after_minutes' => $policy->escalationMinutes], JSON_THROW_ON_ERROR),
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => $id,
            'code' => $policy->code,
            'severity' => $policy->severity,
            'channel' => $policy->channel,
            'recipient_capability' => $policy->recipientCapability,
            'escalation_minutes' => $policy->escalationMinutes,
        ];
    }

    public function publish(
        string $alertCode,
        string $severity,
        string $recipientCapability,
        DateTimeImmutable $occurredAt,
        string $correlationId,
    ): void {
        $policy = new AlertPolicy($alertCode, $severity, 'in_app', $recipientCapability, 1);
        $this->capabilities->assertSupported($policy->recipientCapability);
        if (! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $correlationId)) {
            throw new \InvalidArgumentException('alert_correlation_id_invalid');
        }

        $now = now();
        DB::table('platform_settings_outbox')->insert([
            'id' => Str::uuid7()->toString(),
            'event_type' => self::TECHNICAL_ALERT,
            'aggregate_type' => 'technical_alert',
            'aggregate_id' => Str::uuid7()->toString(),
            'payload' => json_encode([
                'alert_code' => $alertCode,
                'severity' => $severity,
                'recipient_capability' => $recipientCapability,
                'occurred_at' => $occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:sP'),
                'correlation_id' => $correlationId,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt,
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
