<?php

namespace Modules\Authorization\Tests;

use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Authorization\Infrastructure\Persistence\AuthorizationHttpGateway;
use Tests\TestCase;

final class AuthorizationRoleAtomicCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private const PRINCIPAL_ID = '018f6f7d-0c00-7000-8000-000000000911';

    private const ROLE_ID = '018f6f7d-0c00-7000-8000-000000000912';

    private const AUTHORITY_ROLE_ID = '018f6f7d-0c00-7000-8000-000000000914';

    private const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000a11';

    private const UNAUTHORIZED_ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000916';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationCatalogSeeder::class);
        DB::table('roles')->insert([
            'id' => self::ROLE_ID,
            'code' => 'custom-capability-role',
            'name_ar' => 'دور الصلاحيات',
            'name_en' => 'Capability role',
            'role_type' => 'custom',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
        $this->seedRoleCapability('tasks.read');
        DB::table('clusters')->insert([
            'id' => self::CLUSTER_ID,
            'singleton_key' => 1,
            'code' => 'atomic-capability-cluster',
            'name_ar' => 'تجمع الصلاحيات',
            'name_en' => 'Capability cluster',
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_assignments')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000913',
            'user_id' => self::PRINCIPAL_ID,
            'role_id' => self::ROLE_ID,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::PRINCIPAL_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedGrantAuthority();
    }

    public function test_custom_role_capability_codes_replace_the_existing_allow_set_exactly(): void
    {
        $gateway = $this->app->make(AuthorizationHttpGateway::class);

        DB::transaction(function () use ($gateway): void {
            $gateway->update('roles', self::ROLE_ID, [
                'capability_codes' => ['tasks.create', 'identity.account.read'],
            ], 1, self::PRINCIPAL_ID);
        });

        $actual = DB::table('role_capabilities as role_capabilities')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_capabilities.role_id', self::ROLE_ID)
            ->where('role_capabilities.effect', 'allow')
            ->orderBy('capabilities.capability_code')
            ->pluck('capabilities.capability_code')
            ->all();
        $expected = ['identity.account.read', 'tasks.create'];
        $this->assertSame($expected, $actual);
        $this->assertDatabaseMissing('role_capabilities', [
            'role_id' => self::ROLE_ID,
            'capability_id' => DB::table('capabilities')->where('capability_code', 'tasks.read')->value('id'),
        ]);
        $this->assertSame(2, (int) DB::table('roles')->where('id', self::ROLE_ID)->value('lock_version'));
    }

    public function test_capability_replacement_converts_a_preserved_deny_to_allow(): void
    {
        DB::table('role_capabilities')->where('role_id', self::ROLE_ID)->update(['effect' => 'deny']);
        $gateway = $this->app->make(AuthorizationHttpGateway::class);

        $gateway->update('roles', self::ROLE_ID, ['capability_codes' => ['tasks.read']], 1, self::PRINCIPAL_ID);

        $this->assertDatabaseHas('role_capabilities', [
            'role_id' => self::ROLE_ID,
            'capability_id' => DB::table('capabilities')->where('capability_code', 'tasks.read')->value('id'),
            'effect' => 'allow',
        ]);
    }

    public function test_invalid_capability_code_is_rejected_before_existing_set_changes(): void
    {
        $gateway = $this->app->make(AuthorizationHttpGateway::class);
        $before = DB::table('role_capabilities')->where('role_id', self::ROLE_ID)->get()->toArray();

        try {
            $gateway->update('roles', self::ROLE_ID, [
                'capability_codes' => ['tasks.read', 'not.in.catalog'],
            ], 1, self::PRINCIPAL_ID);
            $this->fail('Expected invalid capability code to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('capability_code_not_in_catalog', $exception->getMessage());
        }

        $this->assertEquals($before, DB::table('role_capabilities')->where('role_id', self::ROLE_ID)->get()->toArray());
    }

    public function test_unauthorized_capability_replacement_leaves_role_and_capabilities_unchanged(): void
    {
        DB::table('role_assignments')->insert([
            'id' => self::UNAUTHORIZED_ACTOR_ID,
            'user_id' => self::UNAUTHORIZED_ACTOR_ID,
            'role_id' => self::ROLE_ID,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::PRINCIPAL_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $gateway = $this->app->make(AuthorizationHttpGateway::class);
        $beforeRole = DB::table('roles')->where('id', self::ROLE_ID)->first();
        $beforeCapabilities = DB::table('role_capabilities')->where('role_id', self::ROLE_ID)->get()->toArray();

        try {
            $gateway->update('roles', self::ROLE_ID, ['capability_codes' => ['identity.account.read']], 1, self::UNAUTHORIZED_ACTOR_ID);
            $this->fail('Expected unauthorized capability replacement to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('authorization_grant_exceeds_actor_authority', $exception->getMessage());
        }

        $this->assertEquals($beforeRole, DB::table('roles')->where('id', self::ROLE_ID)->first());
        $this->assertEquals($beforeCapabilities, DB::table('role_capabilities')->where('role_id', self::ROLE_ID)->get()->toArray());
    }

    public function test_stale_capability_replacement_leaves_role_and_capabilities_unchanged(): void
    {
        $gateway = $this->app->make(AuthorizationHttpGateway::class);
        $beforeRole = DB::table('roles')->where('id', self::ROLE_ID)->first();
        $beforeCapabilities = DB::table('role_capabilities')->where('role_id', self::ROLE_ID)->get()->toArray();

        try {
            $gateway->update('roles', self::ROLE_ID, ['capability_codes' => ['tasks.read']], 99, self::PRINCIPAL_ID);
            $this->fail('Expected stale capability replacement to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('authorization_precondition_failed', $exception->getMessage());
        }

        $this->assertEquals($beforeRole, DB::table('roles')->where('id', self::ROLE_ID)->first());
        $this->assertEquals($beforeCapabilities, DB::table('role_capabilities')->where('role_id', self::ROLE_ID)->get()->toArray());
    }

    public function test_capability_replacement_is_transaction_neutral_inside_outer_transaction(): void
    {
        $gateway = $this->app->make(AuthorizationHttpGateway::class);
        $savepoints = [];
        DB::listen(function (object $query) use (&$savepoints): void {
            if (preg_match('/\A(?:SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT)\b/i', trim((string) $query->sql)) === 1) {
                $savepoints[] = $query->sql;
            }
        });

        DB::transaction(function () use ($gateway): void {
            $gateway->update('roles', self::ROLE_ID, ['capability_codes' => ['tasks.read']], 1, self::PRINCIPAL_ID);
        });

        $this->assertSame([], $savepoints);
    }

    private function seedGrantAuthority(): void
    {
        DB::table('roles')->insert([
            'id' => self::AUTHORITY_ROLE_ID,
            'code' => 'atomic-capability-authority',
            'name_ar' => 'دور مانح الصلاحيات',
            'name_en' => 'Capability grant authority',
            'role_type' => 'custom',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
        foreach (['tasks.read', 'tasks.create', 'identity.account.read'] as $code) {
            DB::table('role_capabilities')->insert([
                'role_id' => self::AUTHORITY_ROLE_ID,
                'capability_id' => DB::table('capabilities')->where('capability_code', $code)->value('id'),
                'effect' => 'allow',
                'created_at' => now(),
                'updated_at' => now(),
                'lock_version' => 1,
            ]);
        }
        DB::table('role_assignments')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000915',
            'user_id' => self::PRINCIPAL_ID,
            'role_id' => self::AUTHORITY_ROLE_ID,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::PRINCIPAL_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedRoleCapability(string $code): void
    {
        DB::table('role_capabilities')->insert([
            'role_id' => self::ROLE_ID,
            'capability_id' => DB::table('capabilities')->where('capability_code', $code)->value('id'),
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
    }
}
