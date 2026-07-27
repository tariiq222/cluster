<?php

namespace Modules\Authorization\Tests;

use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Tests\TestCase;

class AuthorizationCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->artisan('migrate', [
            '--path' => 'Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationRbacDataTables.php',
            '--force' => true,
        ]);
    }

    public function test_seeder_syncs_the_full_catalog_with_module_action_and_sensitivity_projection(): void
    {
        (new AuthorizationCatalogSeeder)->run();

        $this->assertDatabaseCount('capabilities', count(CapabilityCatalog::all()));
        $this->assertDatabaseHas('capabilities', [
            'module_code' => 'work_record',
            'capability_code' => 'work_record.read',
            'action' => 'read',
            'sensitivity' => 'normal',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('capabilities', [
            'module_code' => 'identity',
            'capability_code' => 'identity.account.manage',
            'action' => 'manage',
            'sensitivity' => 'sensitive',
        ]);
        $this->assertDatabaseHas('capabilities', [
            'module_code' => 'documents',
            'capability_code' => 'documents.hold',
            'action' => 'hold',
            'sensitivity' => 'sensitive',
        ]);
        $this->assertDatabaseHas('capabilities', [
            'module_code' => 'portfolio_projects',
            'capability_code' => 'portfolio_projects.milestone.approve',
            'action' => 'approve',
            'sensitivity' => 'sensitive',
        ]);
        $this->assertDatabaseHas('capabilities', [
            'module_code' => 'audit',
            'capability_code' => 'audit.event.read',
            'sensitivity' => 'normal',
        ]);
        $this->assertDatabaseHas('capabilities', [
            'module_code' => 'audit',
            'capability_code' => 'audit.event.export',
            'sensitivity' => 'sensitive',
        ]);
        $this->assertDatabaseHas('capabilities', [
            'module_code' => 'audit',
            'capability_code' => 'audit.integrity.verify',
            'sensitivity' => 'critical',
        ]);
    }

    public function test_seeder_is_idempotent_and_never_duplicates_rows(): void
    {
        $seeder = new AuthorizationCatalogSeeder;
        $seeder->run();

        $counts = [
            'capabilities' => DB::table('capabilities')->count(),
            'roles' => DB::table('roles')->count(),
            'role_capabilities' => DB::table('role_capabilities')->count(),
        ];

        $seeder->run();

        $this->assertSame($counts['capabilities'], DB::table('capabilities')->count());
        $this->assertSame($counts['roles'], DB::table('roles')->count());
        $this->assertSame($counts['role_capabilities'], DB::table('role_capabilities')->count());
    }

    public function test_seeder_creates_system_roles_with_allow_capabilities(): void
    {
        (new AuthorizationCatalogSeeder)->run();

        $accessAdminRoleId = DB::table('roles')->where('code', 'system.access-admin')->value('id');
        $securityAuditorRoleId = DB::table('roles')->where('code', 'system.security-auditor')->value('id');

        $this->assertNotNull($accessAdminRoleId);
        $this->assertNotNull($securityAuditorRoleId);
        $this->assertDatabaseHas('roles', [
            'code' => 'system.access-admin',
            'role_type' => 'system',
            'is_system_role' => true,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('roles', [
            'code' => 'system.security-auditor',
            'role_type' => 'system',
            'is_system_role' => true,
            'status' => 'active',
        ]);

        $accessAdminCapabilityCount = DB::table('role_capabilities')
            ->where('role_id', $accessAdminRoleId)
            ->where('effect', 'allow')
            ->count();
        $securityAuditorCapabilityCount = DB::table('role_capabilities')
            ->where('role_id', $securityAuditorRoleId)
            ->where('effect', 'allow')
            ->count();

        $this->assertSame(32, $accessAdminCapabilityCount);
        $this->assertSame(11, $securityAuditorCapabilityCount);

        $auditGrantsForSecurityAuditor = [
            'audit.event.read',
            'audit.event.export',
            'audit.integrity.verify',
        ];
        foreach ($auditGrantsForSecurityAuditor as $auditCapabilityCode) {
            $auditCapabilityId = DB::table('capabilities')
                ->where('capability_code', $auditCapabilityCode)
                ->value('id');
            $this->assertNotNull(
                $auditCapabilityId,
                "Expected capability '{$auditCapabilityCode}' to be present in the capabilities table.",
            );
            $this->assertDatabaseHas('role_capabilities', [
                'role_id' => $securityAuditorRoleId,
                'capability_id' => (string) $auditCapabilityId,
                'effect' => 'allow',
            ]);
        }
    }
}
