<?php

namespace Modules\PlatformSettings\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PlatformSettingsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_settings_tables_expose_the_required_schema_and_single_published_version_guard(): void
    {
        $this->assertTrue(Schema::hasColumns('platform_setting_versions', [
            'id', 'status', 'content_hash', 'lock_version', 'published_at',
        ]));
        $this->assertTrue(Schema::hasColumns('business_calendars', [
            'id', 'scope_type', 'scope_id', 'parent_calendar_id', 'status', 'lock_version',
        ]));
        $this->assertTrue(Schema::hasColumns('platform_operation_requests', [
            'id', 'operation_type', 'status', 'requested_by', 'confirmed_by', 'reason',
        ]));

        foreach ([
            'platform_settings',
            'business_calendar_weekdays',
            'business_calendar_exceptions',
            'platform_maintenance_windows',
            'platform_alert_policies',
            'platform_operation_snapshots',
            'platform_settings_outbox',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        DB::table('platform_setting_versions')->insert([
            'id' => '0197f0e0-0000-7000-8000-000000000001',
            'status' => 'published',
            'settings_document' => json_encode(['default_locale' => 'ar'], JSON_THROW_ON_ERROR),
            'content_hash' => str_repeat('a', 64),
            'published_at' => now(),
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Throwable::class);
        DB::table('platform_setting_versions')->insert([
            'id' => '0197f0e0-0000-7000-8000-000000000002',
            'status' => 'published',
            'settings_document' => json_encode(['default_locale' => 'ar'], JSON_THROW_ON_ERROR),
            'content_hash' => str_repeat('b', 64),
            'published_at' => now(),
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_schema_rejects_a_published_version_with_a_manually_null_published_slot(): void
    {
        $this->expectException(\Throwable::class);

        DB::table('platform_setting_versions')->insert([
            'id' => '0197f0e0-0000-7000-8000-000000000003',
            'status' => 'published',
            'settings_document' => json_encode(['default_locale' => 'ar'], JSON_THROW_ON_ERROR),
            'content_hash' => str_repeat('c', 64),
            'published_slot' => null,
            'published_at' => now(),
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
