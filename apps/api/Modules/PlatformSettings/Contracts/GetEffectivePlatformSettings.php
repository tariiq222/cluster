<?php

namespace Modules\PlatformSettings\Contracts;

interface GetEffectivePlatformSettings
{
    /** @return array{default_locale: 'ar'|'en', timezone: 'Asia/Riyadh', security: array<string, int>} */
    public function current(): array;
}
