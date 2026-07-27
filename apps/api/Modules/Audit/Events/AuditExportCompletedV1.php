<?php

declare(strict_types=1);

namespace Modules\Audit\Events;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Audit\Contracts\AuditEventInput;

final readonly class AuditExportCompletedV1
{
    public const EVENT_TYPE = 'com.cluster.audit.auditexportcompleted.v1';

    public const STREAM_KEY = 'audit.exports.completed';

    public function __construct(
        public string $eventId,
        public string $exportId,
        public string $principalId,
        public ?string $facilityId,
        public string $format,
        public int $eventCount,
        public string $correlationId,
        public DateTimeImmutable $completedAt,
    ) {
        AuditEventInput::assertUuidV7($eventId, 'eventId');
        AuditEventInput::assertUuidV7($exportId, 'exportId');
        AuditEventInput::assertUuidV7($principalId, 'principalId');
        AuditEventInput::assertNullableUuidV7($facilityId, 'facilityId');
        if (! in_array($format, ['csv', 'ndjson'], true)) {
            throw new InvalidArgumentException('audit_export_format_invalid');
        }
        if ($eventCount < 0) {
            throw new InvalidArgumentException('audit_export_event_count_invalid');
        }
        AuditEventInput::assertUuidV7($correlationId, 'correlationId');
        AuditEventInput::assertUtcMilliseconds($completedAt, 'completedAt');
    }

    public function eventType(): string
    {
        return self::EVENT_TYPE;
    }

    /**
     * @return array{
     *     event_id: string,
     *     export_id: string,
     *     principal_id: string,
     *     facility_id: ?string,
     *     format: string,
     *     event_count: int,
     *     correlation_id: string,
     *     completed_at: string
     * }
     */
    public function payload(): array
    {
        return [
            'event_id' => $this->eventId,
            'export_id' => $this->exportId,
            'principal_id' => $this->principalId,
            'facility_id' => $this->facilityId,
            'format' => $this->format,
            'event_count' => $this->eventCount,
            'correlation_id' => $this->correlationId,
            'completed_at' => $this->completedAt->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
