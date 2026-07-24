<?php

namespace Modules\PlatformSettings\Domain;

use RuntimeException;

final readonly class PlatformHealthSnapshot
{
    /** @param list<HealthCheckResult> $checks */
    public function __construct(public array $checks, public string $status)
    {
        foreach ($checks as $check) {
            if (! HealthCheckResult::isSafeMessageCode($check->messageCode)) {
                throw new RuntimeException('Health check messages must be safe codes.');
            }
        }
    }

    /** @param list<HealthCheckResult> $checks */
    public static function fromChecks(array $checks): self
    {
        $statuses = array_map(static fn (HealthCheckResult $check): string => $check->status, $checks);
        $status = in_array('unhealthy', $statuses, true)
            ? 'unhealthy'
            : (in_array('degraded', $statuses, true) ? 'degraded' : 'healthy');

        return new self($checks, $status);
    }
}
