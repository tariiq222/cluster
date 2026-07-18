<?php

namespace Modules\Identity\Features\Authentication\Contracts;

interface PreAuthThrottle
{
    public function attempt(string $source, string $normalizedUsername): PreAuthThrottleDecision;

    public function clear(string $source, string $normalizedUsername): void;

    public function retryAfterSeconds(string $source, string $normalizedUsername): ?int;
}
