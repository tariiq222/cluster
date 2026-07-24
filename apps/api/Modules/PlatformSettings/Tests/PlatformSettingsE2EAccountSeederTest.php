<?php

namespace Modules\PlatformSettings\Tests;

use Database\Seeders\PlatformSettingsE2EAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the dedicated PlatformSettings E2E personas carry exactly the
 * capability set the live E2E suite expects and nothing more. The tests
 * read the persisted role-capability and role-assignment rows directly,
 * which is the same data the RBAC+ABAC engine evaluates.
 */
final class PlatformSettingsE2EAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_refuses_to_run_in_production(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        $this->expectException(\LogicException::class);
        $this->getSeed()->run();
    }

    public function test_seeder_creates_exact_capability_set_per_persona(): void
    {
        $this->getSeed()->run();
        $expected = [
            PlatformSettingsE2EAccountSeeder::FULL_OWNER_USERNAME => PlatformSettingsE2EAccountSeeder::FULL_OWNER_CAPABILITIES,
            PlatformSettingsE2EAccountSeeder::OPERATOR_USERNAME => PlatformSettingsE2EAccountSeeder::OPERATOR_CAPABILITIES,
            PlatformSettingsE2EAccountSeeder::UNAUTHORIZED_USERNAME => [],
            PlatformSettingsE2EAccountSeeder::DEFERRED_LOGS_USERNAME => PlatformSettingsE2EAccountSeeder::DEFERRED_LOGS_CAPABILITIES,
        ];
        foreach ($expected as $username => $capabilities) {
            $actual = $this->actualCapabilitiesFor($username);
            sort($actual);
            $sorted = $capabilities;
            sort($sorted);
            $this->assertSame($sorted, $actual, "Persona {$username} capability set drifted.");
        }
    }

    public function test_full_owner_holds_manage_and_publish_but_not_deferred_log_caps(): void
    {
        $this->getSeed()->run();
        $caps = $this->actualCapabilitiesFor(PlatformSettingsE2EAccountSeeder::FULL_OWNER_USERNAME);
        $this->assertContains('platform_settings.publish', $caps);
        $this->assertContains('platform_operations.maintenance.cancel', $caps);
        $this->assertNotContains('platform_operations.logs.read', $caps);
        $this->assertNotContains('platform_operations.logs.restore', $caps);
    }

    public function test_operator_holds_only_read_or_run_caps_and_nothing_sensitive(): void
    {
        $this->getSeed()->run();
        $caps = $this->actualCapabilitiesFor(PlatformSettingsE2EAccountSeeder::OPERATOR_USERNAME);
        $this->assertContains('platform_settings.read', $caps);
        $this->assertContains('platform_operations.backup.run', $caps);
        $this->assertNotContains('platform_settings.publish', $caps);
        $this->assertNotContains('platform_operations.maintenance.cancel', $caps);
        $this->assertNotContains('platform_operations.logs.read', $caps);
    }

    public function test_unauthorized_persona_holds_no_platform_capability(): void
    {
        $this->getSeed()->run();
        $this->assertSame([], $this->actualCapabilitiesFor(PlatformSettingsE2EAccountSeeder::UNAUTHORIZED_USERNAME));
    }

    public function test_deferred_logs_persona_holds_only_the_deferred_caps(): void
    {
        $this->getSeed()->run();
        $caps = $this->actualCapabilitiesFor(PlatformSettingsE2EAccountSeeder::DEFERRED_LOGS_USERNAME);
        $this->assertSame(
            ['platform_operations.logs.read', 'platform_operations.logs.restore'],
            $caps,
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        $seed = $this->getSeed();
        $seed->run();
        $first = $this->userIdFor(PlatformSettingsE2EAccountSeeder::FULL_OWNER_USERNAME);
        $seed->run();
        $second = $this->userIdFor(PlatformSettingsE2EAccountSeeder::FULL_OWNER_USERNAME);
        $this->assertSame($first, $second);
    }

    public function test_seeder_creates_a_role_assignment_at_the_e2e_facility(): void
    {
        $this->getSeed()->run();
        $userId = $this->userIdFor(PlatformSettingsE2EAccountSeeder::FULL_OWNER_USERNAME);
        $count = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('scope_id', PlatformSettingsE2EAccountSeeder::FACILITY_ID)
            ->where('status', 'active')
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_seeder_provisions_a_test_owned_alert_policy(): void
    {
        $this->getSeed()->run();
        $row = DB::table('platform_alert_policies')->where('id', PlatformSettingsE2EAccountSeeder::ALERT_POLICY_ID)->first();
        $this->assertNotNull($row, 'Test-owned alert policy must be seeded for workflow E2E.');
        $this->assertSame(PlatformSettingsE2EAccountSeeder::ALERT_POLICY_CODE, $row->code);
        $this->assertSame('active', $row->status);
        $this->assertSame(1, (int) $row->lock_version);
    }

    public function test_seeder_resets_alert_policy_lock_version_for_repeatable_workflow_runs(): void
    {
        $this->getSeed()->run();
        DB::table('platform_alert_policies')->where('id', PlatformSettingsE2EAccountSeeder::ALERT_POLICY_ID)->update(['lock_version' => 7]);
        $this->getSeed()->run();
        $lockVersion = (int) DB::table('platform_alert_policies')->where('id', PlatformSettingsE2EAccountSeeder::ALERT_POLICY_ID)->value('lock_version');
        $this->assertSame(1, $lockVersion);
    }

    /**
     * @return list<string>
     */
    private function actualCapabilitiesFor(string $username): array
    {
        $userId = $this->userIdFor($username);
        $rows = DB::table('role_capabilities as rc')
            ->join('capabilities as c', 'c.id', '=', 'rc.capability_id')
            ->join('role_assignments as ra', 'ra.role_id', '=', 'rc.role_id')
            ->where('ra.user_id', $userId)
            ->where('ra.status', 'active')
            ->where('rc.effect', 'allow')
            ->pluck('c.capability_code')
            ->all();
        $codes = array_values(array_unique(array_map(static fn ($code): string => (string) $code, $rows)));
        sort($codes);

        return $codes;
    }

    private function userIdFor(string $username): string
    {
        $id = DB::table('users')->where('username', $username)->value('id');
        $this->assertIsString($id, "Persona {$username} was not seeded.");

        return (string) $id;
    }

    private function getSeed(): PlatformSettingsE2EAccountSeeder
    {
        return $this->app->call(static fn (PlatformSettingsE2EAccountSeeder $seeder): PlatformSettingsE2EAccountSeeder => $seeder);
    }
}
