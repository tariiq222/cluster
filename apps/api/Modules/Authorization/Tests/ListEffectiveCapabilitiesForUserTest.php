<?php

namespace Modules\Authorization\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Infrastructure\Persistence\ListEffectiveCapabilitiesForUser;
use Tests\TestCase;

class ListEffectiveCapabilitiesForUserTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000a01';

    private const OTHER_USER_ID = '018f6f7d-0c00-7000-8000-000000000a02';

    private const GRANTED_BY_USER_ID = '018f6f7d-0c00-7000-8000-000000000a03';

    private const ROLE_ID = '018f6f7d-0c00-7000-8000-000000000a04';

    private const INACTIVE_ROLE_ID = '018f6f7d-0c00-7000-8000-000000000a05';

    private const DELEGATION_ID = '018f6f7d-0c00-7000-8000-000000000a06';

    private const ORGANIZATION_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000a07';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        foreach ([
            'CreateAuthorizationRbacDataTables.php',
            'CreateAuthorizationExplicitDenyTables.php',
            'CreateAuthorizationFieldAuditTables.php',
            'ZAddAuthorizationHttpTables.php',
            'W13AddAuthorizationScopeTypes.php',
        ] as $migration) {
            $this->artisan('migrate', [
                '--path' => 'Modules/Authorization/Infrastructure/Persistence/Migrations/'.$migration,
                '--force' => true,
            ]);
        }
    }

    public function test_a_user_without_grants_holds_no_capabilities(): void
    {
        $this->assertSame([], $this->capabilities()->forUser(self::USER_ID));
    }

    public function test_active_role_assignment_grants_its_allowed_capabilities_sorted(): void
    {
        $this->seedCapability('cap-read', 'work_record.read');
        $this->seedCapability('cap-list', 'organization.unit.read');
        $this->seedRole(self::ROLE_ID, 'nav_reader');
        $this->seedRoleCapability(self::ROLE_ID, 'cap-read', 'allow');
        $this->seedRoleCapability(self::ROLE_ID, 'cap-list', 'allow');
        $this->seedAssignment('assign-1', self::USER_ID, self::ROLE_ID);

        $this->assertSame(
            ['organization.unit.read', 'work_record.read'],
            $this->capabilities()->forUser(self::USER_ID),
        );
    }

    public function test_denied_capabilities_are_not_reported_as_held(): void
    {
        $this->seedCapability('cap-read', 'work_record.read');
        $this->seedRole(self::ROLE_ID, 'nav_denier');
        $this->seedRoleCapability(self::ROLE_ID, 'cap-read', 'deny');
        $this->seedAssignment('assign-1', self::USER_ID, self::ROLE_ID);

        $this->assertSame([], $this->capabilities()->forUser(self::USER_ID));
    }

    public function test_expired_and_future_assignments_are_excluded(): void
    {
        $this->seedCapability('cap-read', 'work_record.read');
        $this->seedCapability('cap-write', 'work_record.update');
        $this->seedRole(self::ROLE_ID, 'nav_reader');
        $this->seedRoleCapability(self::ROLE_ID, 'cap-read', 'allow');
        $this->seedRoleCapability(self::ROLE_ID, 'cap-write', 'allow');
        $this->seedAssignment('assign-expired', self::USER_ID, self::ROLE_ID, startAt: now()->subHour(), endAt: now()->subMinute());
        $this->seedAssignment('assign-future', self::USER_ID, self::ROLE_ID, startAt: now()->addHour());

        $this->assertSame([], $this->capabilities()->forUser(self::USER_ID));
    }

    public function test_inactive_role_and_retired_capability_are_excluded(): void
    {
        $this->seedCapability('cap-read', 'work_record.read');
        $this->seedCapability('cap-retired', 'work_record.update', status: 'inactive');
        $this->seedRole(self::INACTIVE_ROLE_ID, 'nav_inactive', status: 'inactive');
        $this->seedRoleCapability(self::INACTIVE_ROLE_ID, 'cap-read', 'allow');
        $this->seedAssignment('assign-inactive-role', self::USER_ID, self::INACTIVE_ROLE_ID);

        $this->seedRole(self::ROLE_ID, 'nav_reader');
        $this->seedRoleCapability(self::ROLE_ID, 'cap-retired', 'allow');
        $this->seedAssignment('assign-retired-cap', self::USER_ID, self::ROLE_ID);

        $this->assertSame([], $this->capabilities()->forUser(self::USER_ID));
    }

    public function test_active_delegation_grants_capabilities_to_the_delegate_only(): void
    {
        $this->seedCapability('cap-decide', 'workflow.decide');
        DB::table('delegations')->insert([
            'id' => self::DELEGATION_ID,
            'delegator_user_id' => self::OTHER_USER_ID,
            'delegate_user_id' => self::USER_ID,
            'module_code' => 'workflow',
            'scope_id' => self::ORGANIZATION_UNIT_ID,
            'scope_type' => 'unit',
            'start_at' => now()->subMinute(),
            'end_at' => now()->addHour(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('delegation_capabilities')->insert([
            'delegation_id' => self::DELEGATION_ID,
            'capability_code' => 'workflow.decide',
        ]);

        $this->assertSame(['workflow.decide'], $this->capabilities()->forUser(self::USER_ID));
        $this->assertSame([], $this->capabilities()->forUser(self::OTHER_USER_ID));
    }

    public function test_a_capability_held_twice_is_reported_once(): void
    {
        $this->seedCapability('cap-read', 'work_record.read');
        $this->seedRole(self::ROLE_ID, 'nav_reader');
        $this->seedRoleCapability(self::ROLE_ID, 'cap-read', 'allow');
        $this->seedAssignment('assign-1', self::USER_ID, self::ROLE_ID);
        $this->seedAssignment('assign-2', self::USER_ID, self::ROLE_ID, scopeId: self::ORGANIZATION_UNIT_ID);

        $this->assertSame(['work_record.read'], $this->capabilities()->forUser(self::USER_ID));
    }

    private function capabilities(): ListEffectiveCapabilitiesForUser
    {
        return $this->app->make(ListEffectiveCapabilitiesForUser::class);
    }

    private function seedCapability(string $id, string $code, string $status = 'active'): void
    {
        DB::table('capabilities')->insert([
            'id' => $id,
            'module_code' => explode('.', $code, 2)[0],
            'capability_code' => $code,
            'action' => substr($code, (int) strrpos($code, '.') + 1),
            'sensitivity' => 'normal',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedRole(string $roleId, string $code, string $status = 'active'): void
    {
        DB::table('roles')->insert([
            'id' => $roleId,
            'code' => $code,
            'name_ar' => 'دور تجريبي',
            'name_en' => 'Test role',
            'role_type' => 'administrative',
            'status' => $status,
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedRoleCapability(string $roleId, string $capabilityId, string $effect): void
    {
        DB::table('role_capabilities')->insert([
            'role_id' => $roleId,
            'capability_id' => $capabilityId,
            'effect' => $effect,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedAssignment(
        string $id,
        string $userId,
        string $roleId,
        ?string $scopeId = null,
        ?Carbon $startAt = null,
        ?Carbon $endAt = null,
    ): void {
        DB::table('role_assignments')->insert([
            'id' => $id,
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_id' => $scopeId,
            'scope_type' => $scopeId === null ? null : 'unit',
            'start_at' => $startAt ?? now()->subMinute(),
            'end_at' => $endAt,
            'status' => 'active',
            'granted_by_user_id' => self::GRANTED_BY_USER_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
