<?php

declare(strict_types=1);

namespace Modules\Audit\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuditEventReceipt
{
    public function __construct(
        public string $eventId,
        public string $streamKey,
        public int $streamSequence,
        public string $eventHash,
        public DateTimeImmutable $recordedAt,
        public bool $replayed,
    ) {
        AuditEventInput::assertUuidV7($eventId, 'eventId');
        self::assertStreamKey($streamKey);
        if ($streamSequence < 1) {
            throw new InvalidArgumentException('audit_stream_sequence_invalid');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $eventHash) !== 1) {
            throw new InvalidArgumentException('audit_event_hash_invalid');
        }
        AuditEventInput::assertUtcMilliseconds($recordedAt, 'recordedAt');
    }

    private static function assertStreamKey(string $streamKey): void
    {
        if (strlen($streamKey) > 160
            || preg_match('/\A[a-z][a-z0-9_-]*:[a-z][a-z0-9_-]*:(?:[0-9a-f-]{36}|global)\z/', $streamKey) !== 1) {
            throw new InvalidArgumentException('audit_stream_key_invalid');
        }
    }
}
