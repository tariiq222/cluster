<?php

declare(strict_types=1);

namespace Modules\Audit\Events;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\Audit\Contracts\AuditEventInput;

final readonly class AuditIntegrityViolationDetectedV1
{
    public const EVENT_TYPE = 'com.cluster.audit.auditintegrityviolationdetected.v1';

    public const STREAM_KEY = 'audit.integrity.violations';

    public function __construct(
        public string $eventId,
        public string $verificationId,
        public string $streamKey,
        public string $correlationId,
        public int $firstMismatchStreamSequence,
        public int $verifiedEventCount,
        public DateTimeImmutable $detectedAt,
    ) {
        AuditEventInput::assertUuidV7($eventId, 'eventId');
        AuditEventInput::assertUuidV7($verificationId, 'verificationId');
        self::assertStreamKey($streamKey);
        AuditEventInput::assertUuidV7($correlationId, 'correlationId');
        if ($firstMismatchStreamSequence < 1 || $verifiedEventCount < 0) {
            throw new InvalidArgumentException('audit_integrity_count_invalid');
        }
        AuditEventInput::assertUtcMilliseconds($detectedAt, 'detectedAt');
    }

    public function eventType(): string
    {
        return self::EVENT_TYPE;
    }

    /**
     * @return array{
     *     event_id: string,
     *     verification_id: string,
     *     stream_key: string,
     *     correlation_id: string,
     *     first_mismatch_stream_sequence: int,
     *     verified_event_count: int,
     *     integrity_status: 'violated',
     *     detected_at: string
     * }
     */
    public function payload(): array
    {
        return [
            'event_id' => $this->eventId,
            'verification_id' => $this->verificationId,
            'stream_key' => $this->streamKey,
            'correlation_id' => $this->correlationId,
            'first_mismatch_stream_sequence' => $this->firstMismatchStreamSequence,
            'verified_event_count' => $this->verifiedEventCount,
            'integrity_status' => 'violated',
            'detected_at' => $this->detectedAt->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private static function assertStreamKey(string $streamKey): void
    {
        if (strlen($streamKey) > 160
            || preg_match('/\A[a-z][a-z0-9_-]*:[a-z][a-z0-9_-]*:(?:[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|global)\z/', $streamKey) !== 1) {
            throw new InvalidArgumentException('audit_stream_key_invalid');
        }
    }
}
