<?php

namespace Modules\PlatformSettings\Contracts;

use Modules\PlatformSettings\Domain\TechnicalLogFilter;
use Modules\PlatformSettings\Domain\TechnicalLogPage;

interface TechnicalLogSource
{
    public function search(TechnicalLogFilter $filter): TechnicalLogPage;

    public function isAvailable(): bool;
}
