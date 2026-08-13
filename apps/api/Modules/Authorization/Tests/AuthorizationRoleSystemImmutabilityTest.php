<?php

namespace Modules\Authorization\Tests;

use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Authorization\Infrastructure\Persistence\AuthorizationHttpGateway;
use Tests\TestCase;

final class AuthorizationRoleSystemImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private const PRINCIPAL_ID = '018f6f7d-0c00-7000-8000-000000000901';

    private const OUT_OF_SCOPE_ACTOR_ID = '018f6f7d-0c00-7000-8000-00000000090a';

    private const SCOPED_USER_ID = '018f6f7d-0c00-7000-8000-00000000090b';

    private const SYSTEM_ROLE_ID = '018f6f7d-0c00-7000-8000-000000000902';

    private const CUSTOM_ROLE_ID = '018f6f7d-0c00-7000-8000-000000000903';

    private const NEAR_LIMIT_SYSTEM_ROLE_ID = '018f6f7d-0c00-7000-8000-000000000904';

    /** Total 87 lowercase chars: source + '_clone-' (7) + '-' (1) + 8-char uuid slice (8) = 103 → truncated to 96 with suffix preserved. */
    private const NEAR_LIMIT_SOURCE_CODE = 'abbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const FOREIGN_SCOPE_ID = '018f6f7d-0c00-7000-8000-000000000a03';

    private const OWNED_SCOPE_ID = '018f6f7d-0c00-7000-8000-000000000b01';

    private function seedUser(string $userId, string $username, string $nameAr, string $nameEn): void
    {
        if (DB::table('users')->where('id', $userId)->exists()) {
            return;
        }
        DB::table('users')->insert([
            'id' => $userId,
            'username' => $username,
            'person_id' => null,
            'person_version' => null,
            'display_name_ar' => $nameAr,
            'display_name_en' => $nameEn,
            'status' => 'active',
            'must_change_password' => false,
            'password_version' => 1,
            'failed_login_count' => 0,
            'lockout_level' => 0,
            'locked_until' => null,
            'lock_version' => 1,
            'is_admin' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedCluster(string $clusterId, int $singletonKey, string $code): void
    {
        if (DB::table('clusters')->where('id', $clusterId)->exists()) {
            return;
        }
        DB::table('clusters')->insert([
            'id' => $clusterId,
            'singleton_key' => $singletonKey,
            'code' => $code,
            'name_ar' => 'تجمع '.$clusterId,
            'name_en' => 'Cluster '.$clusterId,
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->artisan('migrate', [
            '--path' => 'Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityCredentialCoreTables.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationCoreTables.php',
            '--force' => true,
        ]);
        $this->artisan('migrate', [
            '--path' => 'Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php',
            '--force' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationCatalogSeeder::class);
    }

    public function test_system_role_patch_is_rejected_without_changing_source(): void
    {
        $this->seedSystemRole();
        $this->seedSystemRoleAssignment(self::PRINCIPAL_ID);
        $gateway = $this->gateway();
        $before = DB::table('roles')->where('id', self::SYSTEM_ROLE_ID)->first();

        try {
            $gateway->update('roles', self::SYSTEM_ROLE_ID, ['name_ar' => 'New'], 1, self::PRINCIPAL_ID);
            $this->fail('Expected system-role name patch to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('authorization_system_role_immutable', $exception->getMessage());
        }

        try {
            $gateway->update('roles', self::SYSTEM_ROLE_ID, [
                'name_ar' => 'New',
                'capability_codes' => ['tasks.read'],
            ], 1, self::PRINCIPAL_ID);
            $this->fail('Expected system-role capability patch to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('authorization_system_role_immutable', $exception->getMessage());
        }

        $this->assertEquals($before, DB::table('roles')->where('id', self::SYSTEM_ROLE_ID)->first());
    }

    public function test_system_role_capability_create_and_effect_update_are_rejected_without_mutation(): void
    {
        $this->seedSystemRole();
        $this->seedSystemRoleAssignment(self::PRINCIPAL_ID);
        $capabilityId = (string) DB::table('capabilities')->where('capability_code', 'tasks.read')->value('id');
        $this->seedRoleCapability(self::SYSTEM_ROLE_ID, $capabilityId);
        $gateway = $this->gateway();

        foreach ([
            fn (): array => $gateway->create('role-capabilities', ['role_id' => self::SYSTEM_ROLE_ID, 'capability_code' => 'identity.account.read'], self::PRINCIPAL_ID),
            fn (): ?array => $gateway->update('role-capabilities', self::SYSTEM_ROLE_ID.':'.$capabilityId, ['effect' => 'deny'], 1, self::PRINCIPAL_ID),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Expected a system-role capability mutation to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('authorization_system_role_immutable', $exception->getMessage());
            }
        }

        $this->assertDatabaseMissing('role_capabilities', ['role_id' => self::SYSTEM_ROLE_ID, 'capability_id' => DB::table('capabilities')->where('capability_code', 'identity.account.read')->value('id')]);
        $this->assertDatabaseHas('role_capabilities', ['role_id' => self::SYSTEM_ROLE_ID, 'capability_id' => $capabilityId, 'effect' => 'allow', 'lock_version' => 1]);
    }

    public function test_new_roles_are_always_custom_and_non_system(): void
    {
        $created = $this->gateway()->create('roles', [
            'code' => 'requested-system-role',
            'name_ar' => 'دور مطلوب كنظامي',
            'name_en' => 'Requested system role',
            'role_type' => 'system',
            'is_system_role' => true,
        ], self::PRINCIPAL_ID);

        $this->assertSame('custom', $created['role_type']);
        $this->assertFalse((bool) $created['is_system_role']);
    }

    public function test_system_role_capability_revoke_is_rejected_without_changing_source(): void
    {
        $this->seedSystemRole(['tasks.read']);
        $this->seedSystemRoleAssignment(self::PRINCIPAL_ID);
        $capabilityId = (string) DB::table('capabilities')->where('capability_code', 'tasks.read')->value('id');
        $this->seedRoleCapability(self::SYSTEM_ROLE_ID, $capabilityId);
        $gateway = $this->gateway();

        try {
            $gateway->transition('role-capabilities', self::SYSTEM_ROLE_ID.':'.$capabilityId, 'revoke', 1, self::PRINCIPAL_ID);
            $this->fail('Expected system-role capability revoke to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('authorization_system_role_immutable', $exception->getMessage());
        }

        $this->assertDatabaseHas('role_capabilities', [
            'role_id' => self::SYSTEM_ROLE_ID,
            'capability_id' => $capabilityId,
            'effect' => 'allow',
            'lock_version' => 1,
        ]);
    }

    public function test_clone_copies_allow_capabilities_into_fresh_custom_active_role_without_touching_source(): void
    {
        $sourceCapabilities = ['tasks.read', 'identity.account.read'];
        $this->seedSystemRole($sourceCapabilities);
        $sourceBefore = DB::table('roles')->where('id', self::SYSTEM_ROLE_ID)->first();
        $this->seedSystemRoleAssignment(self::PRINCIPAL_ID);
        foreach ($sourceCapabilities as $code) {
            $this->seedRoleCapability(self::SYSTEM_ROLE_ID, (string) DB::table('capabilities')->where('capability_code', $code)->value('id'));
        }
        $gateway = $this->gateway();
        $savepoints = [];
        DB::listen(function (object $query) use (&$savepoints): void {
            if (preg_match('/\A(?:SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT)\b/i', trim((string) $query->sql)) === 1) {
                $savepoints[] = $query->sql;
            }
        });

        $cloned = DB::transaction(fn (): array => $gateway->transition('roles', self::SYSTEM_ROLE_ID, 'clone', 1, self::PRINCIPAL_ID) ?? throw new InvalidArgumentException('Expected clone row.'));

        $this->assertSame([], $savepoints);
        $this->assertNotSame(self::SYSTEM_ROLE_ID, $cloned['id']);
        $this->assertSame('custom', $cloned['role_type']);
        $this->assertFalse((bool) $cloned['is_system_role']);
        $this->assertSame('active', $cloned['status']);
        $this->assertSame(1, (int) $cloned['lock_version']);
        $this->assertStringStartsWith('_clone-', $cloned['code']);
        $this->assertLessThanOrEqual(96, strlen((string) $cloned['code']));
        $this->assertEquals($sourceBefore, DB::table('roles')->where('id', self::SYSTEM_ROLE_ID)->first());

        $actualCapabilities = DB::table('role_capabilities as role_capabilities')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_capabilities.role_id', $cloned['id'])
            ->where('role_capabilities.effect', 'allow')
            ->orderBy('capabilities.capability_code')
            ->pluck('capabilities.capability_code')
            ->all();
        sort($sourceCapabilities);
        $this->assertSame($sourceCapabilities, $actualCapabilities);
    }

    public function test_clone_handles_a_near_limit_source_code_deterministically(): void
    {
        DB::table('roles')->insert([
            'id' => self::NEAR_LIMIT_SYSTEM_ROLE_ID,
            'code' => self::NEAR_LIMIT_SOURCE_CODE,
            'name_ar' => 'دور حدودي',
            'name_en' => 'Boundary role',
            'role_type' => 'system',
            'status' => 'active',
            'is_system_role' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
        $this->seedSystemRoleAssignment(self::PRINCIPAL_ID);
        $this->seedScopedActor(self::PRINCIPAL_ID, self::NEAR_LIMIT_SYSTEM_ROLE_ID);
        $gateway = $this->gateway();

        $cloned = $gateway->transition('roles', self::NEAR_LIMIT_SYSTEM_ROLE_ID, 'clone', 1, self::PRINCIPAL_ID);

        $this->assertIsArray($cloned);
        $this->assertSame('custom', $cloned['role_type']);
        $this->assertSame(96, strlen((string) $cloned['code']));
        $this->assertStringEndsWith(substr(self::NEAR_LIMIT_SOURCE_CODE, 0, 81), (string) $cloned['code']);
    }

    public function test_clone_applies_validated_overrides_without_allowing_system_fields(): void
    {
        $this->seedSystemRole();
        $this->seedSystemRoleAssignment(self::PRINCIPAL_ID);
        $this->seedRoleCapability(self::SYSTEM_ROLE_ID, (string) DB::table('capabilities')->where('capability_code', 'tasks.read')->value('id'));

        $cloned = $this->gateway()->transition('roles', self::SYSTEM_ROLE_ID, 'clone', 1, self::PRINCIPAL_ID, [
            'code' => 'custom-clone-override',
            'name_ar' => 'نسخة مخصصة',
            'name_en' => 'Custom clone',
            'description_ar' => 'وصف النسخة',
            'description_en' => 'Clone description',
            'capability_codes' => ['tasks.read'],
        ]);

        $this->assertIsArray($cloned);
        $this->assertSame('custom-clone-override', $cloned['code']);
        $this->assertSame('نسخة مخصصة', $cloned['name_ar']);
        $this->assertSame('Custom clone', $cloned['name_en']);
        $this->assertSame('وصف النسخة', $cloned['description_ar']);
        $this->assertSame('Clone description', $cloned['description_en']);
        $this->assertSame('custom', $cloned['role_type']);
        $this->assertFalse((bool) $cloned['is_system_role']);
    }

    public function test_unauthorized_clone_capability_override_creates_no_role_or_capabilities(): void
    {
        $this->seedSystemRole();
        $this->seedSystemRoleAssignment(self::PRINCIPAL_ID);
        $this->seedScopedActor(self::OUT_OF_SCOPE_ACTOR_ID, self::SYSTEM_ROLE_ID);
        $beforeRoles = DB::table('roles')->count();
        $beforeCapabilities = DB::table('role_capabilities')->count();

        try {
            $this->gateway()->transition('roles', self::SYSTEM_ROLE_ID, 'clone', 1, self::OUT_OF_SCOPE_ACTOR_ID, [
                'capability_codes' => ['identity.account.read'],
            ]);
            $this->fail('Expected an unauthorized clone capability override to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('authorization_grant_exceeds_actor_authority', $exception->getMessage());
        }

        $this->assertSame($beforeRoles, DB::table('roles')->count());
        $this->assertSame($beforeCapabilities, DB::table('role_capabilities')->count());
    }

    public function test_clone_rejects_invalid_literal_override_without_creating_a_role(): void
    {
        $this->seedSystemRole();
        $this->seedSystemRoleAssignment(self::PRINCIPAL_ID);
        $before = DB::table('roles')->count();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Role data is invalid.');
        try {
            $this->gateway()->transition('roles', self::SYSTEM_ROLE_ID, 'clone', 1, self::PRINCIPAL_ID, ['code' => '_clone-invalid']);
        } finally {
            $this->assertSame($before, DB::table('roles')->count());
        }
    }

    public function test_repeated_clones_receive_distinct_generated_codes(): void
    {
        $this->seedSystemRole();
        $this->seedSystemRoleAssignment(self::PRINCIPAL_ID);
        $gateway = $this->gateway();

        $first = $gateway->transition('roles', self::SYSTEM_ROLE_ID, 'clone', 1, self::PRINCIPAL_ID);
        $second = $gateway->transition('roles', self::SYSTEM_ROLE_ID, 'clone', 1, self::PRINCIPAL_ID);

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertNotSame($first['code'], $second['code']);
        $this->assertLessThanOrEqual(96, strlen((string) $first['code']));
        $this->assertLessThanOrEqual(96, strlen((string) $second['code']));
    }

    public function test_clone_rejects_non_system_source_and_non_role_resource(): void
    {
        $this->seedCustomRole();
        $this->seedSystemRoleAssignment(self::PRINCIPAL_ID);
        $this->seedScopedActor(self::PRINCIPAL_ID, self::CUSTOM_ROLE_ID);
        $gateway = $this->gateway();

        try {
            $gateway->transition('roles', self::CUSTOM_ROLE_ID, 'clone', 1, self::PRINCIPAL_ID);
            $this->fail('Expected cloning a custom role to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('authorization_clone_source_not_system_or_immutable', $exception->getMessage());
        }

        try {
            $gateway->transition('capabilities', self::CUSTOM_ROLE_ID, 'clone', 1, self::PRINCIPAL_ID);
            $this->fail('Expected cloning a non-role resource to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('authorization_action_invalid', $exception->getMessage());
        }
    }

    public function test_clone_rejects_an_actor_that_cannot_see_the_source_role(): void
    {
        $this->seedSystemRole();
        $this->seedActorWithoutScope(self::OUT_OF_SCOPE_ACTOR_ID, self::CUSTOM_ROLE_ID);
        $gateway = $this->gateway();
        $roleCount = DB::table('roles')->count();

        $this->assertNull($gateway->transition('roles', self::SYSTEM_ROLE_ID, 'clone', 1, self::OUT_OF_SCOPE_ACTOR_ID));
        $this->assertSame($roleCount, DB::table('roles')->count());
    }

    public function test_clone_inserts_a_custom_role_within_the_actor_scope(): void
    {
        $this->seedSystemRole(['tasks.read']);
        $this->seedScopedActor(self::SCOPED_USER_ID, self::SYSTEM_ROLE_ID);
        $gateway = $this->gateway();

        $cloned = $gateway->transition('roles', self::SYSTEM_ROLE_ID, 'clone', 1, self::SCOPED_USER_ID);

        $this->assertIsArray($cloned);
        $this->assertNotSame(self::SYSTEM_ROLE_ID, $cloned['id']);
    }

    public function test_custom_role_update_visibility_enforced_for_out_of_scope_actor(): void
    {
        $this->seedCustomRole();
        $this->seedSystemRoleAssignment(self::PRINCIPAL_ID);
        $this->seedActorWithoutScope(self::OUT_OF_SCOPE_ACTOR_ID);
        $gateway = $this->gateway();

        $this->assertNull($gateway->update('roles', self::CUSTOM_ROLE_ID, ['name_ar' => 'edited'], 1, self::OUT_OF_SCOPE_ACTOR_ID));
        $this->assertSame('دور مخصص', (string) DB::table('roles')->where('id', self::CUSTOM_ROLE_ID)->value('name_ar'));
    }

    public function test_role_update_is_transaction_neutral(): void
    {
        $this->seedCustomRole();
        $this->seedSystemRoleAssignment(self::PRINCIPAL_ID);
        $this->seedScopedActor(self::PRINCIPAL_ID, self::CUSTOM_ROLE_ID);
        $gateway = $this->gateway();
        $savepoints = [];
        DB::listen(function (object $query) use (&$savepoints): void {
            if (preg_match('/\A(?:SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT)\b/i', trim((string) $query->sql)) === 1) {
                $savepoints[] = $query->sql;
            }
        });

        DB::transaction(function () use ($gateway): void {
            $gateway->update('roles', self::CUSTOM_ROLE_ID, [
                'name_ar' => 'دور مخصص محدّث',
            ], 1, self::PRINCIPAL_ID);
        });

        $this->assertSame([], $savepoints);
        $this->assertSame('دور مخصص محدّث', DB::table('roles')->where('id', self::CUSTOM_ROLE_ID)->value('name_ar'));
    }

    private function gateway(): AuthorizationHttpGateway
    {
        return $this->app->make(AuthorizationHttpGateway::class);
    }

    /** @param list<string> $capabilityCodes */
    private function seedSystemRole(array $capabilityCodes = ['tasks.read']): void
    {
        if (DB::table('roles')->where('id', self::SYSTEM_ROLE_ID)->exists()) {
            return;
        }
        DB::table('roles')->insert([
            'id' => self::SYSTEM_ROLE_ID,
            'code' => 'system-role-immutable',
            'name_ar' => 'دور نظامي',
            'name_en' => 'System role',
            'role_type' => 'system',
            'status' => 'active',
            'is_system_role' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
    }

    private function seedCustomRole(): void
    {
        if (DB::table('roles')->where('id', self::CUSTOM_ROLE_ID)->exists()) {
            return;
        }
        DB::table('roles')->insert([
            'id' => self::CUSTOM_ROLE_ID,
            'code' => 'custom-role',
            'name_ar' => 'دور مخصص',
            'name_en' => 'Custom role',
            'role_type' => 'custom',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
    }

    private function seedRoleCapability(string $roleId, string $capabilityId): void
    {
        DB::table('role_capabilities')->insert([
            'role_id' => $roleId,
            'capability_id' => $capabilityId,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
    }

    private function seedActorWithoutScope(string $userId, string $roleId = self::SYSTEM_ROLE_ID): void
    {
        if ($roleId === self::CUSTOM_ROLE_ID) {
            $this->seedCustomRole();
        } else {
            $this->seedSystemRole();
        }
        $this->seedCluster(self::FOREIGN_SCOPE_ID, 2, 'foreign-cluster-'.Str::uuid7()->toString());
        $this->seedUser($userId, 'actor-'.Str::uuid7()->toString(), 'خارج النطاق', 'Out of scope actor');
        DB::table('role_assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => self::FOREIGN_SCOPE_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedScopedActor(string $userId, string $roleId): void
    {
        $this->seedUser($userId, 'scoped-'.Str::uuid7()->toString(), 'داخل النطاق', 'Scoped actor');
        DB::table('role_assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => self::OWNED_SCOPE_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedSystemRoleAssignment(string $userId): void
    {
        $this->seedSystemRole();
        $this->seedCluster(self::OWNED_SCOPE_ID, 1, 'admin-cluster-'.Str::uuid7()->toString());
        $this->seedUser($userId, 'admin-'.Str::uuid7()->toString(), 'مسؤول', 'Admin');
        if (DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('role_id', self::SYSTEM_ROLE_ID)
            ->where('scope_id', self::OWNED_SCOPE_ID)
            ->where('status', 'active')
            ->exists()) {
            return;
        }
        DB::table('role_assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => $userId,
            'role_id' => self::SYSTEM_ROLE_ID,
            'scope_type' => 'cluster',
            'scope_id' => self::OWNED_SCOPE_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
