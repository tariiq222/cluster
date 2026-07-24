<?php

namespace Modules\PlatformSettings\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\PlatformSettings\Contracts\GetEffectivePlatformSettings;
use Modules\PlatformSettings\Domain\SettingsVersion;

final class DatabasePlatformSettings implements GetEffectivePlatformSettings
{
    private ?bool $hasPublishedVersion = null;

    /** @return array{default_locale: 'ar'|'en', timezone: 'Asia/Riyadh', security: array<string, int>} */
    public function current(): array
    {
        $row = DB::table('platform_setting_versions')
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->first();
        if ($row === null) {
            $this->hasPublishedVersion = false;
            $defaults = SettingsVersion::defaults('defaults');

            return ['default_locale' => $defaults->defaultLocale, 'timezone' => SettingsVersion::TIMEZONE, 'security' => $defaults->security];
        }
        $this->hasPublishedVersion = true;
        /** @var array<string, mixed> $document */
        $document = json_decode((string) $row->settings_document, true, 512, JSON_THROW_ON_ERROR);
        $version = SettingsVersion::fromDocument((string) $row->id, (string) $row->status, (int) $row->lock_version, $document);

        return ['default_locale' => $version->defaultLocale, 'timezone' => SettingsVersion::TIMEZONE, 'security' => $version->security];
    }

    public function hasPublishedVersion(): bool
    {
        if ($this->hasPublishedVersion === null) {
            $this->current();
        }

        return (bool) $this->hasPublishedVersion;
    }
}
