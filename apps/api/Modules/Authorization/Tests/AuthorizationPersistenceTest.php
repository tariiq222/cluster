<?php

namespace Modules\Authorization\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthorizationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const ROLE_ID = '018f6f7d-0c00-7000-8000-000000000811';

    private const CAPABILITY_ID = '018f6f7d-0c00-7000-8000-000000000812';

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000813';

    private const OTHER_USER_ID = '018f6f7d-0c00-7000-8000-000000000814';

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->artisan('migrate', [
            '--path' => 'Modules/Authorization/Infrastructure/Persistence/Migrations/CreateAuthorizationRbacDataTables.php',
            '--force' => true,
        ]);
    }

    public function test_authorization_migration_creates_only_owned_rbac_tables_with_internal_foreign_keys(): void
    {
        foreach (['roles', 'capabilities', 'role_capabilities', 'role_assignments', 'delegations', 'delegation_capabilities'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected {$table} table.");
        }

        $this->assertTrue(Schema::hasColumns('role_assignments', [
            'id', 'user_id', 'role_id', 'scope_id', 'start_at', 'end_at', 'status', 'granted_by_user_id',
        ]));
        $this->assertTrue(Schema::hasColumns('delegations', [
            'id', 'delegator_user_id', 'delegate_user_id', 'module_code', 'scope_id', 'start_at', 'end_at', 'status',
        ]));

        $foreignTables = array_map(
            static fn (object $foreignKey): string => $foreignKey->table,
            DB::select("PRAGMA foreign_key_list('role_assignments')"),
        );
        $this->assertSame(['roles'], $foreignTables);
    }

    public function test_database_rejects_invalid_role_assignment_and_delegation_windows_and_self_delegation(): void
    {
        $this->seedRole();

        $this->assertQueryRejected(fn () => DB::table('role_assignments')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000815',
            'user_id' => self::USER_ID,
            'role_id' => self::ROLE_ID,
            'scope_id' => null,
            'start_at' => '2026-07-21 10:00:00.000',
            'end_at' => '2026-07-20 10:00:00.000',
            'status' => 'pending',
            'granted_by_user_id' => self::OTHER_USER_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->assertQueryRejected(fn () => DB::table('delegations')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000816',
            'delegator_user_id' => self::USER_ID,
            'delegate_user_id' => self::USER_ID,
            'module_code' => 'organization',
            'scope_id' => null,
            'start_at' => '2026-07-20 10:00:00.000',
            'end_at' => '2026-07-21 10:00:00.000',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->assertQueryRejected(fn () => DB::table('delegations')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000817',
            'delegator_user_id' => self::USER_ID,
            'delegate_user_id' => self::OTHER_USER_ID,
            'module_code' => 'organization',
            'scope_id' => null,
            'start_at' => '2026-07-21 10:00:00.000',
            'end_at' => '2026-07-20 10:00:00.000',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertDatabaseCount('role_assignments', 0);
        $this->assertDatabaseCount('delegations', 0);
    }

    private function seedRole(): void
    {
        DB::table('roles')->insert([
            'id' => self::ROLE_ID,
            'code' => 'facility_manager',
            'name_ar' => 'مدير المنشأة',
            'name_en' => 'Facility manager',
            'role_type' => 'administrative',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected the database constraint to reject the write.');
        } catch (QueryException) {
            // The database invariant is the assertion target.
            $this->addToAssertionCount(1);
        }
    }
}
