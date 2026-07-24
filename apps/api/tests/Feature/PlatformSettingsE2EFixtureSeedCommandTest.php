<?php

namespace Tests\Feature;

use Database\Seeders\PlatformSettingsE2EAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PlatformSettingsE2EFixtureSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_fixture_provisioning_outside_local_or_testing(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $exitCode = Artisan::call('e2e:platform-settings:seed');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('local/testing', Artisan::output());
    }

    public function test_it_seeds_the_four_dedicated_platform_settings_personas(): void
    {
        $exitCode = Artisan::call('e2e:platform-settings:seed');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('OK', Artisan::output());
        $this->assertDatabaseHas('users', ['username' => PlatformSettingsE2EAccountSeeder::FULL_OWNER_USERNAME, 'status' => 'active']);
        $this->assertDatabaseHas('users', ['username' => PlatformSettingsE2EAccountSeeder::OPERATOR_USERNAME, 'status' => 'active']);
        $this->assertDatabaseHas('users', ['username' => PlatformSettingsE2EAccountSeeder::UNAUTHORIZED_USERNAME, 'status' => 'active']);
        $this->assertDatabaseHas('users', ['username' => PlatformSettingsE2EAccountSeeder::DEFERRED_LOGS_USERNAME, 'status' => 'active']);
        $this->assertDatabaseHas('facilities', ['id' => PlatformSettingsE2EAccountSeeder::FACILITY_ID, 'status' => 'active']);
    }
}
