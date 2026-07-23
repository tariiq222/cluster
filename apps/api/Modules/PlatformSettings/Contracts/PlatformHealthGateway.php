<?php

namespace Modules\PlatformSettings\Contracts;

use Modules\PlatformSettings\Domain\HealthCheckResult;

interface PlatformHealthGateway
{
    /** @return list<HealthCheckResult> */
    public function snapshot(): array;
}
