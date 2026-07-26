<?php

namespace Modules\PlatformSettings\Features\Alerts\Handler;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\PlatformSettings\Contracts\PublishTechnicalAlert;
use Modules\PlatformSettings\Contracts\ValidateTechnicalAlertRecipientCapability;
use Modules\PlatformSettings\Domain\AlertPolicy;
use Modules\PlatformSettings\Infrastructure\Outbox\PlatformSettingsOutbox;

final class AlertPolicyHandler implements PublishTechnicalAlert
{
    public const TECHNICAL_ALERT = 'com.cluster.platform.technical-alert.v1';

    public function __construct(
        private readonly ValidateTechnicalAlertRecipientCapability $capabilities,
        private readonly PlatformSettingsOutbox $outbox,
    ) {}

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

        return DB::transaction(function () use ($policy): array {
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
        });
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

        DB::transaction(function () use ($alertCode, $severity, $recipientCapability, $occurredAt, $correlationId): void {
            $this->outbox->appendTechnicalAlert(
                alertId: Str::uuid7()->toString(),
                alertCode: $alertCode,
                severity: $severity,
                recipientCapability: $recipientCapability,
                occurredAt: $occurredAt,
                correlationId: $correlationId,
            );
        });
    }
}
