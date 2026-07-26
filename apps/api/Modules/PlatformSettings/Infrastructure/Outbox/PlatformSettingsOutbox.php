<?php

namespace Modules\PlatformSettings\Infrastructure\Outbox;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Str;
use Shared\Contracts\TransactionalOutboxEnvelope;
use Shared\Infrastructure\Outbox\OutboxEventType;

class PlatformSettingsOutbox
{
    public function __construct(
        private readonly TransactionalOutboxEnvelope $outbox,
    ) {}

    public function append(string $versionId, string $contentHash): void
    {
        $this->appendEvent(
            eventId: Str::uuid7()->toString(),
            aggregateId: $versionId,
            eventType: OutboxEventType::PlatformSettingVersionPublished,
            subject: '/platform-settings/versions/'.$versionId,
            aggregateType: 'platform_setting_version',
            data: ['version_id' => $versionId, 'content_hash' => $contentHash],
        );
    }

    public function appendTechnicalAlert(
        string $alertId,
        string $alertCode,
        string $severity,
        string $recipientCapability,
        DateTimeInterface $occurredAt,
        string $correlationId,
    ): void {
        $this->appendEvent(
            eventId: $alertId,
            aggregateId: $alertId,
            eventType: OutboxEventType::PlatformTechnicalAlert,
            subject: '/technical-alerts/'.rawurlencode($alertCode),
            aggregateType: 'technical_alert',
            data: [
                'alert_code' => $alertCode,
                'severity' => $severity,
                'recipient_capability' => $recipientCapability,
                'occurred_at' => DateTimeImmutable::createFromInterface($occurredAt)
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:sP'),
                'correlation_id' => $correlationId,
            ],
            occurredAt: $occurredAt,
        );
    }

    public function appendOperationRequested(string $operationId, string $operationType): void
    {
        $eventType = self::operationEventType($operationType);

        $this->appendEvent(
            eventId: $operationId,
            aggregateId: $operationId,
            eventType: $eventType,
            subject: '/platform-operations/'.$operationId,
            aggregateType: 'platform_operation_request',
            data: ['operation_id' => $operationId, 'operation_type' => $operationType],
        );
    }

    public static function operationEventType(string $operationType): OutboxEventType
    {
        return match ($operationType) {
            'backup' => OutboxEventType::PlatformOperationsBackupRequested,
            'restore_validation' => OutboxEventType::PlatformOperationsRestoreValidationRequested,
            default => throw new \InvalidArgumentException('platform_operation_type_invalid'),
        };
    }

    /** @param array<string, mixed> $data */
    private function appendEvent(
        string $eventId,
        string $aggregateId,
        OutboxEventType $eventType,
        string $subject,
        string $aggregateType,
        array $data,
        ?DateTimeInterface $occurredAt = null,
    ): void {
        $occurredAt ??= now();
        $time = DateTimeImmutable::createFromInterface($occurredAt)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
        $this->outbox->appendEnvelope(
            $eventId,
            $aggregateId,
            [
                'specversion' => '1.0',
                'id' => $eventId,
                'source' => '/platform-settings',
                'type' => $eventType->value,
                'subject' => $subject,
                'time' => $time,
                'datacontenttype' => 'application/json',
                'aggregatetype' => $aggregateType,
                'data' => $data,
            ],
            $time,
        );
    }
}
