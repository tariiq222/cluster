<?php

namespace Modules\PlatformSettings\Contracts;

interface GetEffectivePlatformSettings
{
    /** @return array{default_locale: 'ar'|'en', timezone: 'Asia/Riyadh', security: array<string, int>} */
    public function current(): array;

    /**
     * Returns true when the effective settings were sourced from a persisted published version,
     * even if the published values happen to equal the bootstrap defaults.
     */
    public function hasPublishedVersion(): bool;
}
