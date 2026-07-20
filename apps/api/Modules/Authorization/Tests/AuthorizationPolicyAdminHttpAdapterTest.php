<?php

namespace Modules\Authorization\Tests;

use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wave-3 policy administration: role-capability matrix, explicit denies,
 * canonical capability enforcement, scope_type persistence and delegation
 * authority validation through the real admin API.
 */
final class AuthorizationPolicyAdminHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000a01';

    private const SESSION_COOKIE = 'cluster_identity_session';

    private const ADMIN_ID = '018f6f7d-0c00-7000-8000-000000000021';

    private const UNIT_A = '018f6f7d-0c00-7000-8000-00000000aa01';

    private const UNIT_B = '018f6f7d-0c00-7000-8000-00000000aa02';

    private const FACILITY = DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID;

    private const CLUSTER = '018f6f7d-0c00-7000-8000-00000000c113';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->when([
            \App\Http\Controllers\Authorization\AuthorizationAdminController::class,
            \App\Http\Controllers\Authorization\DecideAccessController::class,
            \App\Http\Controllers\Authorization\ExplainAccessDecisionController::class,
        ])->needs(\Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal::class)
            ->give(fn ($app) => $app->make(\App\Http\Authentication\SessionPrincipalResolver::class));
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        DB::table('authorization_bootstrap')->update([
            'state' => 'complete',
            'completed_by_user_id' => self::ADMIN_ID,
            'completed_at' => now(),
            'lock_version' => 2,
            'updated_at' => now(),
        ]);
    }

    public function test_role_capability_is_attached_updated_listed_and_revoked(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $roleId = $this->createRole($cookie, $csrf, 'records_officer');

        $created = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-capabilities', [
            'resource_type' => 'role_capability',
            'role_id' => $roleId,
            'capability_code' => 'work_record.read',
            'effect' => 'allow',
        ], $this->writeHeaders('rc-attach', $csrf))->assertCreated()->assertJsonPath('data.resource_type', 'role_capability');
        $composite = (string) $created->json('data.id');
        $this->assertDatabaseHas('role_capabilities', ['role_id' => $roleId, 'effect' => 'allow']);

        $replay = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-capabilities', [
            'resource_type' => 'role_capability',
            'role_id' => $roleId,
            'capability_code' => 'work_record.read',
            'effect' => 'allow',
        ], $this->writeHeaders('rc-attach', $csrf))->assertCreated()->assertJsonPath('data.id', $composite);

        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/role-capabilities/'.$composite, ['effect' => 'deny'], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ])->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.effect', 'deny');
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/role-capabilities/'.$composite, ['effect' => 'allow'], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ])->assertStatus(412);
        $this->assertDatabaseHas('role_capabilities', ['role_id' => $roleId, 'effect' => 'deny']);

        $list = $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/role-capabilities?limit=100', ['X-Correlation-ID' => self::CORRELATION_ID])->assertOk();
        $this->assertTrue(DB::table('role_capabilities')->where('role_id', $roleId)->where('effect', 'deny')->exists());

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-capabilities/'.$composite.'/revoke', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"2"',
            'Idempotency-Key' => 'rc-revoke',
            'X-CSRF-Token' => $csrf,
        ])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertDatabaseMissing('role_capabilities', ['role_id' => $roleId]);
    }

    public function test_role_capability_direct_access_is_scoped_and_cursor_is_reusable(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $foreignRole = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::ROLE_CODE)->value('id');
        $foreignCapability = (string) DB::table('capabilities')->where('capability_code', 'work_record.read')->value('id');
        DB::table('role_capabilities')->insert([
            'role_id' => $foreignRole,
            'capability_id' => $foreignCapability,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
        $foreignId = $foreignRole.':'.$foreignCapability;

        $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/role-capabilities/'.$foreignId, [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertNotFound();
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/role-capabilities/'.$foreignId, ['effect' => 'deny'], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ])->assertNotFound();
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-capabilities/'.$foreignId.'/revoke', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'foreign-role-capability-revoke',
            'X-CSRF-Token' => $csrf,
        ])->assertNotFound();

        $roleId = $this->createRole($cookie, $csrf, 'cursor_role');
        $this->attach($cookie, $csrf, $roleId, 'work_record.read');
        $this->attach($cookie, $csrf, $roleId, 'work_record.list');
        $first = $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/role-capabilities?limit=1', [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertOk();
        $cursor = (string) $first->json('next_cursor');
        $this->assertNotSame('', $cursor);
        $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/role-capabilities?limit=1&cursor='.urlencode($cursor), [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertOk();
    }

    public function test_role_capability_rejects_unknown_catalog_code(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $roleId = $this->createRole($cookie, $csrf, 'catalog_guard');
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-capabilities', [
            'resource_type' => 'role_capability',
            'role_id' => $roleId,
            'capability_code' => 'work_record.destroy',
        ], $this->writeHeaders('rc-bad-code', $csrf))->assertUnprocessable();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/capabilities', [
            'resource_type' => 'capability',
            'capability_code' => 'work_record.destroy',
        ], $this->writeHeaders('cap-bad-code', $csrf))->assertUnprocessable();
    }

    public function test_explicit_deny_lifecycle(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $created = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/explicit-denies', [
            'resource_type' => 'explicit_deny',
            'user_id' => self::ADMIN_ID,
            'capability_code' => 'work_record.read',
            'resource_pattern' => 'work_record',
            'reason' => 'تعليق وصول مؤقت للمراجعة',
        ], $this->writeHeaders('deny-create', $csrf))->assertCreated()->assertJsonPath('data.resource_type', 'explicit_deny');
        $id = (string) $created->json('data.id');

        $replay = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/explicit-denies', [
            'resource_type' => 'explicit_deny',
            'user_id' => self::ADMIN_ID,
            'capability_code' => 'work_record.read',
            'resource_pattern' => 'work_record',
            'reason' => 'تعليق وصول مؤقت للمراجعة',
        ], $this->writeHeaders('deny-create', $csrf))->assertCreated()->assertJsonPath('data.id', $id);

        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/explicit-denies/'.$id, ['classification' => 'confidential'], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ])->assertOk()->assertHeader('ETag', '"2"');

        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/explicit-denies/'.$id, ['reason' => 'x'], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ])->assertStatus(412);

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/explicit-denies/'.$id.'/revoke', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"2"',
            'Idempotency-Key' => 'deny-revoke',
            'X-CSRF-Token' => $csrf,
        ])->assertOk();
        $deny = DB::table('explicit_denies')->where('id', $id)->first();
        $this->assertNotNull($deny->expires_at);
    }

    public function test_explicit_deny_validation(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/explicit-denies', [
            'resource_type' => 'explicit_deny',
            'capability_code' => 'work_record.read',
            'reason' => 'بدون موضوع',
        ], $this->writeHeaders('deny-no-subject', $csrf))->assertUnprocessable();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/explicit-denies', [
            'resource_type' => 'explicit_deny',
            'user_id' => self::ADMIN_ID,
            'capability_code' => 'work_record.read',
            'classification' => 'mega_secret',
            'reason' => 'تصنيف غير صالح',
        ], $this->writeHeaders('deny-bad-classification', $csrf))->assertUnprocessable();
    }

    public function test_role_assignment_persists_scope_type_and_rejects_global_scope(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $roleId = $this->createRole($cookie, $csrf, 'scoped_reader');
        $this->attach($cookie, $csrf, $roleId, 'work_record.read');

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments', [
            'resource_type' => 'role_assignment',
            'user_id' => self::ADMIN_ID,
            'role_id' => $roleId,
            'start_at' => '2026-07-01T00:00:00.000Z',
        ], $this->writeHeaders('assignment-global', $csrf))->assertUnprocessable();

        $created = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments', [
            'resource_type' => 'role_assignment',
            'user_id' => self::ADMIN_ID,
            'role_id' => $roleId,
            'scope_type' => 'facility',
            'scope_id' => self::FACILITY,
            'start_at' => '2026-07-01T00:00:00.000Z',
        ], $this->writeHeaders('assignment-scoped', $csrf))->assertCreated()->assertJsonPath('data.scope_type', 'facility');
        $this->assertDatabaseHas('role_assignments', ['id' => (string) $created->json('data.id'), 'scope_type' => 'facility']);

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments', [
            'resource_type' => 'role_assignment',
            'user_id' => self::ADMIN_ID,
            'role_id' => $roleId,
            'scope_type' => 'galaxy',
            'scope_id' => self::FACILITY,
            'start_at' => '2026-07-01T00:00:00.000Z',
        ], $this->writeHeaders('assignment-bad-scope', $csrf))->assertUnprocessable();
    }

    public function test_delegation_requires_delegator_authority(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $this->seedOrgTree();
        $delegator = self::ADMIN_ID;
        $delegate = '018f6f7d-0c00-7000-8000-00000000dd02';
        $roleId = $this->createRole($cookie, $csrf, 'facility_delegator');
        $this->attach($cookie, $csrf, $roleId, 'work_record.read');
        $this->assignRole($delegator, $roleId, 'facility', self::FACILITY, null);

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations', [
            'resource_type' => 'delegation',
            'delegator_user_id' => '018f6f7d-0c00-7000-8000-00000000dd01',
            'delegate_user_id' => $delegate,
            'module_code' => 'work_record',
            'capability_codes' => ['work_record.read'],
            'scope_type' => 'facility',
            'scope_id' => self::FACILITY,
            'start_at' => '2026-07-01T00:00:00.000Z',
            'end_at' => '2026-08-01T00:00:00.000Z',
        ], $this->writeHeaders('delegation-spoofed', $csrf))->assertUnprocessable();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations', [
            'resource_type' => 'delegation',
            'delegator_user_id' => $delegator,
            'delegate_user_id' => $delegate,
            'module_code' => 'work_record',
            'capability_codes' => ['strategy.plan.read'],
            'scope_type' => 'facility',
            'scope_id' => self::FACILITY,
            'start_at' => '2025-07-01T00:00:00.000Z',
            'end_at' => '2025-08-01T00:00:00.000Z',
        ], $this->writeHeaders('delegation-not-owned', $csrf))->assertUnprocessable();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations', [
            'resource_type' => 'delegation',
            'delegator_user_id' => $delegator,
            'delegate_user_id' => $delegate,
            'module_code' => 'work_record',
            'capability_codes' => ['work_record.read'],
            'scope_type' => 'unit',
            'scope_id' => self::UNIT_A,
            'start_at' => '2026-07-01T00:00:00.000Z',
            'end_at' => '2026-08-01T00:00:00.000Z',
        ], $this->writeHeaders('delegation-narrower', $csrf))->assertCreated()->assertJsonPath('data.scope_type', 'unit');

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations', [
            'resource_type' => 'delegation',
            'delegator_user_id' => $delegator,
            'delegate_user_id' => $delegate,
            'module_code' => 'work_record',
            'capability_codes' => ['work_record.read'],
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER,
            'start_at' => '2026-07-01T00:00:00.000Z',
            'end_at' => '2026-08-01T00:00:00.000Z',
        ], $this->writeHeaders('delegation-wider', $csrf))->assertUnprocessable();

        $windowedRoleId = $this->createRole($cookie, $csrf, 'windowed_delegator');
        $this->attach($cookie, $csrf, $windowedRoleId, 'strategy.plan.read');
        DB::table('role_assignments')->where('user_id', $delegator)->where('role_id', $windowedRoleId)->delete();
        $this->assignRole($delegator, $windowedRoleId, 'unit', self::UNIT_B, '2026-07-20 00:00:00.000', '2026-07-10 00:00:00.000');
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations', [
            'resource_type' => 'delegation',
            'delegator_user_id' => $delegator,
            'delegate_user_id' => $delegate,
            'module_code' => 'work_record',
            'capability_codes' => ['strategy.plan.read'],
            'scope_type' => 'unit',
            'scope_id' => self::UNIT_B,
            'start_at' => '2026-07-01T00:00:00.000Z',
            'end_at' => '2026-08-01T00:00:00.000Z',
        ], $this->writeHeaders('delegation-beyond-window', $csrf))->assertUnprocessable();
    }


    public function test_admin_rows_are_scoped_before_pagination_and_direct_mutations(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $foreignRole = DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::ROLE_CODE)->value('id');
        $foreignAssignment = DB::table('role_assignments')
            ->where('user_id', DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_ID)
            ->where('role_id', $foreignRole)->value('id');
        $localAssignment = DB::table('role_assignments')
            ->where('user_id', self::ADMIN_ID)->where('scope_id', self::FACILITY)->value('id');

        $list = $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/role-assignments?limit=1', [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertOk();
        $this->assertSame(1, count($list->json('items')));
        $this->assertSame($localAssignment, $list->json('items.0.id'));
        $this->assertNotSame($foreignAssignment, $list->json('items.0.id'));

        $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/role-assignments/'.$foreignAssignment, [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertNotFound();
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/role-assignments/'.$foreignAssignment, ['status' => 'revoked'], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ])->assertNotFound();
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments/'.$foreignAssignment.'/revoke', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'foreign-assignment-revoke',
            'X-CSRF-Token' => $csrf,
        ])->assertNotFound();
        $this->assertDatabaseHas('role_assignments', ['id' => $foreignAssignment, 'status' => 'active']);
    }

    public function test_facility_actor_cannot_grant_cluster_authority_and_etags_are_enforced(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $this->seedOrgTree();
        $roleId = $this->createRole($cookie, $csrf, 'contained_assignment');
        $this->attach($cookie, $csrf, $roleId, 'work_record.read');

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments', [
            'resource_type' => 'role_assignment',
            'user_id' => '018f6f7d-0c00-7000-8000-00000000ee01',
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER,
            'start_at' => '2026-07-01T00:00:00.000Z',
        ], $this->writeHeaders('cluster-assignment-denied', $csrf))->assertUnprocessable();

        $created = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments', [
            'resource_type' => 'role_assignment',
            'user_id' => '018f6f7d-0c00-7000-8000-00000000ee01',
            'role_id' => $roleId,
            'scope_type' => 'facility',
            'scope_id' => self::FACILITY,
            'start_at' => '2026-07-01T00:00:00.000Z',
        ], $this->writeHeaders('facility-assignment-contained', $csrf))->assertCreated();
        $id = (string) $created->json('data.id');
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/role-assignments/'.$id, ['status' => 'active'], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ])->assertOk()->assertHeader('ETag', '"2"');
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/role-assignments/'.$id, ['status' => 'revoked'], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ])->assertStatus(412);
    }

    private function createRole(string $cookie, string $csrf, string $code): string
    {
        return (string) $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', [
            'resource_type' => 'role',
            'code' => $code,
            'name' => 'دور '.$code,
            'role_type' => 'operational',
        ], $this->writeHeaders('role-'.$code, $csrf))->assertCreated()->json('data.id');
    }

    private function attach(string $cookie, string $csrf, string $roleId, string $capabilityCode): void
    {
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-capabilities', [
            'resource_type' => 'role_capability',
            'role_id' => $roleId,
            'capability_code' => $capabilityCode,
            'effect' => 'allow',
        ], $this->writeHeaders('attach-'.$roleId.'-'.$capabilityCode, $csrf))->assertCreated();
    }

    private function assignRole(string $userId, string $roleId, string $scopeType, string $scopeId, ?string $endAt, string $startAt = '2026-07-01 00:00:00.000'): void
    {
        DB::table('role_assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'status' => 'active',
            'granted_by_user_id' => self::ADMIN_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedOrgTree(): void
    {
        DB::table('organization_units')->insert(['id' => self::UNIT_A, 'cluster_id' => self::CLUSTER, 'parent_id' => self::FACILITY, 'parent_type' => 'facility', 'unit_type_id' => '0197f0e0-0000-7000-8000-000000000202', 'code' => 'UA', 'name_ar' => 'وحدة أ', 'status' => 'active', 'path_cache' => '/'.self::CLUSTER.'/'.self::FACILITY.'/'.self::UNIT_A, 'depth' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organization_units')->insert(['id' => self::UNIT_B, 'cluster_id' => self::CLUSTER, 'parent_id' => self::FACILITY, 'parent_type' => 'facility', 'unit_type_id' => '0197f0e0-0000-7000-8000-000000000202', 'code' => 'UB', 'name_ar' => 'وحدة ب', 'status' => 'active', 'path_cache' => '/'.self::CLUSTER.'/'.self::FACILITY.'/'.self::UNIT_B, 'depth' => 2, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function loginAdminSession(): array
    {
        return $this->loginSession(
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_USERNAME,
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_PASSWORD,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function loginSession(string $username, string $password): array
    {
        $this->withServerVariables(["REMOTE_ADDR" => "127.0.0.1", "HTTP_USER_AGENT" => "W1.2 E2E test browser"]);
        $response = $this->postJson('/api/v1/identity/login', [
            'username' => $username,
            'password' => $password,
        ], ['X-Correlation-ID' => self::CORRELATION_ID]);
        $response->assertOk();
        $this->assertCount(1, $response->headers->getCookies());
        $this->assertSame(self::SESSION_COOKIE, $response->headers->getCookies()[0]->getName());

        return [
            (string) $response->headers->getCookies()[0]->getValue(),
            (string) $response->json('data.csrf_token'),
        ];
    }

    private function withIdentitySession(string $cookie): self
    {
        return $this->withUnencryptedCookie(self::SESSION_COOKIE, $cookie)->withCredentials();
    }

    /** @return array<string, string> */
    private function writeHeaders(string $key, ?string $csrf = null): array
    {
        $headers = ['X-Correlation-ID' => self::CORRELATION_ID, 'Idempotency-Key' => $key];
        if ($csrf !== null) {
            $headers['X-CSRF-Token'] = $csrf;
        }

        return $headers;
    }
}
