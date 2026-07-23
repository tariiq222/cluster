<?php

namespace Modules\PlatformSettings\Infrastructure\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformSettingsOutbox
{
    public const VERSION_PUBLISHED = 'com.cluster.platform-settings.version-published.v1';

    public function append(string $versionId, string $contentHash): void
    {
        DB::table('platform_settings_outbox')->insert([
            'id' => Str::uuid7()->toString(),
            'event_type' => self::VERSION_PUBLISHED,
            'aggregate_type' => 'platform_setting_version',
            'aggregate_id' => $versionId,
            'payload' => json_encode(['version_id' => $versionId, 'content_hash' => $contentHash], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'published_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
