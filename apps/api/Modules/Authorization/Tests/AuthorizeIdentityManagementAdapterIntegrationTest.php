<?php

namespace Modules\Authorization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Adapter\AuthorizeIdentityManagementAdapter;
use Modules\Authorization\Infrastructure\Persistence\ListActiveRoleSummariesForUser;
use Modules\Authorization\Infrastructure\Persistence\ListEffectiveCapabilitiesForUser;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Organization\Contracts\GetDefaultClusterId;
use Tests\TestCase;

/**
 * Integration coverage for AuthorizeIdentityManagementAdapter wiring:
 * exercises the real RbacAbacDecideAccess engine against the test DB
 * (style mirrored from RbacAbacDecideAccessTest) to prove that the
 * cluster-id injection unblocks cluster-scoped role assignments while
 * keeping facility-scoped and unassigned principals fail-closed.
 */
class AuthorizeIdentityManagementAdapterIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000a01';

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000a02';

    private const ROLE_ID = '018f6f7d-0c00-7000-8000-000000000a03';

    private const CAPABILITY_ID = '018f6f7d-0c00-7000-8000-000000000a04';

    private const MANAGE_CAPABILITY_ID = '018f6f7d-0c00-7000-8000-000000000a0a';

    private const USER_ID = '018f6f7d-0c00-7000-8000-000000000a05';

    private const GRANTED_BY_USER_ID = '018f6f7d-0c00-7000-8000-000000000a06';

    private const ASSIGNMENT_ID_PREFIX = '018f6f7d-0c00-7000-8000-000000000a07';

    private const ANOTHER_USER_ID = '018f6f7d-0c00-7000-8000-000000000a08';

    private const ACTOR_FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000a09';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'sensitive_access_events',
            'access_decisions',
            'explicit_denies',
            'classification_policies',
            'field_access_templates',
            'role_assignments',
            'role_capabilities',
            'delegation_capabilities',
            'delegations',
            'roles',
            'capabilities',
        ] as $table) {
            DB::table($table)->delete();
        }
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

    public function test_cluster_scoped_role_assignment_grants_account_read_via_cluster_id_facts(): void
    {
        $this->seedRoleWithIdentityAccountRead(self::ROLE_ID, self::CAPABILITY_ID);
        $this->seedAssignment(
            self::ASSIGNMENT_ID_PREFIX.'1',
            self::USER_ID,
            self::ROLE_ID,
            self::CLUSTER_ID,
            'cluster',
        );

        $adapter = $this->adapterWithClusterResolver(self::CLUSTER_ID);

        $this->assertTrue($adapter->canReadAccounts([
            'user_id' => self::USER_ID,
            'facility_id' => self::ACTOR_FACILITY_ID,
        ]));
    }

    public function test_facility_scoped_role_assignment_does_not_grant_account_read(): void
    {
        $this->seedRoleWithIdentityAccountRead(self::ROLE_ID, self::CAPABILITY_ID);
        $this->seedAssignment(
            self::ASSIGNMENT_ID_PREFIX.'2',
            self::USER_ID,
            self::ROLE_ID,
            self::FACILITY_ID,
            'facility',
        );

        $adapter = $this->adapterWithClusterResolver(self::CLUSTER_ID);

        $this->assertFalse($adapter->canReadAccounts([
            'user_id' => self::USER_ID,
            'facility_id' => self::FACILITY_ID,
        ]));
    }

    public function test_unassigned_user_cannot_read_accounts(): void
    {
        $this->seedRoleWithIdentityAccountRead(self::ROLE_ID, self::CAPABILITY_ID);

        $adapter = $this->adapterWithClusterResolver(self::CLUSTER_ID);

        $this->assertFalse($adapter->canReadAccounts([
            'user_id' => self::ANOTHER_USER_ID,
            'facility_id' => self::ACTOR_FACILITY_ID,
        ]));
    }

    public function test_evaluate_only_path_does_not_persist_access_decisions(): void
    {
        $this->seedRoleWithIdentityAccountRead(self::ROLE_ID, self::CAPABILITY_ID);
        $this->seedCapabilityRow(self::MANAGE_CAPABILITY_ID, 'identity.account.manage', 'sensitive');
        DB::table('role_capabilities')->insert([
            'role_id' => self::ROLE_ID,
            'capability_id' => self::MANAGE_CAPABILITY_ID,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedAssignment(
            self::ASSIGNMENT_ID_PREFIX.'3',
            self::USER_ID,
            self::ROLE_ID,
            self::CLUSTER_ID,
            'cluster',
        );

        $adapter = $this->adapterWithClusterResolver(self::CLUSTER_ID);

        $this->assertTrue($adapter->canReadAccounts([
            'user_id' => self::USER_ID,
            'facility_id' => self::ACTOR_FACILITY_ID,
        ]));
        $this->assertTrue($adapter->canManageAccounts([
            'user_id' => self::USER_ID,
            'facility_id' => self::ACTOR_FACILITY_ID,
        ]));

        $this->assertDatabaseCount('access_decisions', 0);
        $this->assertDatabaseCount('sensitive_access_events', 0);
    }

    private function adapterWithClusterResolver(string $clusterId): AuthorizeIdentityManagementAdapter
    {
        $decider = new RbacAbacDecideAccess(
            $this->app->make(\Modules\Organization\Contracts\GetActiveSupervisoryRelationships::class),
        );
        $resolver = new class($clusterId) implements GetDefaultClusterId
        {
            public function __construct(private readonly string $clusterId) {}

            public function resolve(): string
            {
                return $this->clusterId;
            }
        };

        return new AuthorizeIdentityManagementAdapter(
            $decider,
            new ListActiveRoleSummariesForUser,
            new ListEffectiveCapabilitiesForUser,
            $resolver,
        );
    }

    private function seedRoleWithIdentityAccountRead(string $roleId, string $capabilityId): void
    {
        DB::table('roles')->insert([
            'id' => $roleId,
            'code' => 'identity_account_reader',
            'name_ar' => 'قارئ حسابات الهوية',
            'name_en' => 'Identity account reader',
            'role_type' => 'administrative',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('capabilities')->insert([
            'id' => $capabilityId,
            'module_code' => 'identity',
            'capability_code' => 'identity.account.read',
            'action' => 'read',
            'sensitivity' => 'sensitive',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_capabilities')->insert([
            'role_id' => $roleId,
            'capability_id' => $capabilityId,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedCapabilityRow(string $capabilityId, string $capabilityCode, string $sensitivity): void
    {
        DB::table('capabilities')->insert([
            'id' => $capabilityId,
            'module_code' => explode('.', $capabilityCode, 2)[0],
            'capability_code' => $capabilityCode,
            'action' => substr($capabilityCode, (int) strrpos($capabilityCode, '.') + 1),
            'sensitivity' => $sensitivity,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedAssignment(
        string $assignmentId,
        string $userId,
        string $roleId,
        string $scopeId,
        string $scopeType,
    ): void {
        DB::table('role_assignments')->insert([
            'id' => $assignmentId,
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_id' => $scopeId,
            'scope_type' => $scopeType,
            'start_at' => now()->subMinute(),
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::GRANTED_BY_USER_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
