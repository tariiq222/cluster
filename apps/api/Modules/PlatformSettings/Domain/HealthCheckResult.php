<?php

namespace Modules\PlatformSettings\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class HealthCheckResult
{
    public function __construct(
        public string $code,
        public string $status,
        public DateTimeImmutable $checkedAt,
        public int $latencyMs,
        public string $messageCode,
    ) {
        if (! in_array($status, ['healthy', 'degraded', 'unhealthy'], true)) {
            throw new InvalidArgumentException('Unsupported health check status.');
        }
        if ($latencyMs < 0) {
            throw new InvalidArgumentException('Health check latency cannot be negative.');
        }
    }

    public static function isSafeMessageCode(string $messageCode): bool
    {
        return $messageCode !== ''
            && strlen($messageCode) <= 96
            && preg_match('/^[a-z][a-z0-9_]*$/', $messageCode) === 1
            && ! preg_match('/(?:dsn|username|password|secret|token|:\/\/|@)/i', $messageCode);
    }
}
