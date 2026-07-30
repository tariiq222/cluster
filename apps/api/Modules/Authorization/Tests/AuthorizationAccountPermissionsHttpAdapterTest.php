<?php

declare(strict_types=1);

namespace Modules\Authorization\Tests;

use App\Http\Authentication\SessionPrincipalResolver;
use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\PersistAccessDecision;
use Modules\Authorization\Features\Administration\Http\AuthorizationAdminController;
use Modules\Authorization\Features\DecideAccess\Http\DecideAccessController;
use Modules\Authorization\Features\ExplainAccessDecision\Http\ExplainAccessDecisionController;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use Tests\TestCase;

final class AuthorizationAccountPermissionsHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000a51';

    private const SESSION_COOKIE = 'cluster_identity_session';

    private const FACILITY_ADMIN_ID = '018f6f7d-0c00-7000-8000-00000000fa51';

    private const FACILITY_ADMIN_USERNAME = 'facility-only-account-permissions-admin';

    private const FACILITY_ADMIN_PASSWORD = 'Cedar!Orbit8Harbor2026';

    private const UNIT_A = '018f6f7d-0c00-7000-8000-00000000aa51';

    private const UNIT_B = '018f6f7d-0c00-7000-8000-00000000aa52';

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindRealAccessDecision();
        $engine = new RbacAbacDecideAccess(
            $this->app->make(GetActiveSupervisoryRelationships::class),
            $this->app->make(PersistAccessDecision::class),
        );
        $this->app->instance(DecideAccess::class, $engine);
        $this->app->when(AuthorizationAdminController::class)
            ->needs(DecideAccess::class)
            ->give(fn (): RbacAbacDecideAccess => $engine);
        $this->app->when([
            AuthorizationAdminController::class,
            DecideAccessController::class,
            ExplainAccessDecisionController::class,
        ])->needs(ResolveDevelopmentFixturePrincipal::class)
            ->give(fn ($app): SessionPrincipalResolver => $app->make(SessionPrincipalResolver::class));

        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        config()->set('identity.session_only', true);
        DB::table('authorization_bootstrap')->update([
            'state' => 'complete',
            'completed_by_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'completed_at' => now(),
            'lock_version' => 2,
            'updated_at' => now(),
        ]);
        $this->assignClusterAuthorizationAdmin();
        $this->seedFacilityOnlyAdmin();
        $this->seedUnits();
    }

    public function test_role_creation_replay_capabilities_archive_and_audit_are_atomic(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $payload = [
            'resource_type' => 'role',
            'code' => 'account_permissions_operator',
            'name' => 'مشغل الحسابات والصلاحيات',
            'role_type' => 'custom',
            'capability_codes' => ['work_record.read', 'work_record.list'],
        ];

        $created = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', $payload, $this->writeHeaders('account-permissions-role-create', $csrf))
            ->assertCreated()
            ->assertHeader('ETag', '"1"');
        $roleId = (string) $created->json('data.id');

        $this->assertSame(2, DB::table('role_capabilities')->where('role_id', $roleId)->count());
        $this->assertAuditCount('authorization.role.created', $roleId, DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 1);

        $replayed = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', $payload, $this->writeHeaders('account-permissions-role-create', $csrf))
            ->assertCreated();
        $this->assertSame($roleId, $replayed->json('data.id'));
        $this->assertSame(2, DB::table('role_capabilities')->where('role_id', $roleId)->count());
        $this->assertAuditCount('authorization.role.created', $roleId, DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 1);
        DB::table('role_assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => (string) DB::table('clusters')->where('singleton_key', 1)->value('id'),
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/roles/'.$roleId, ['status' => 'archived'], $this->patchHeaders(1, $csrf))
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.status', 'archived');
        $this->assertDatabaseHas('roles', ['id' => $roleId, 'status' => 'archived', 'lock_version' => 2]);
        $this->assertAuditCount('authorization.role.archived', $roleId, DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 1);
    }

    public function test_clone_requires_source_version_preserves_source_and_audits_the_new_role(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $sourceId = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::ROLE_CODE)->value('id');
        $this->assertNotSame('', $sourceId);
        $sourceCapabilities = DB::table('role_capabilities')->where('role_id', $sourceId)->orderBy('capability_id')->pluck('capability_id')->all();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles/'.$sourceId.'/clone', [
            'code' => 'clone_target',
            'name_ar' => 'نسخة الصلاحيات',
            'description_ar' => 'وصف النسخة',
            'description_en' => 'Cloned description',
        ], $this->writeHeaders('clone-target-missing-version', $csrf))->assertBadRequest();

        $cloned = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles/'.$sourceId.'/clone', [
            'code' => 'clone_target',
            'name_ar' => 'نسخة الصلاحيات',
            'description_ar' => 'وصف النسخة',
            'description_en' => 'Cloned description',
        ], [...$this->writeHeaders('clone-target', $csrf), 'If-Match' => '"1"'])->assertOk();
        $cloneId = (string) $cloned->json('data.id');

        $this->assertNotSame($sourceId, $cloneId);
        $this->assertSame('custom', $cloned->json('data.role_type'));
        $this->assertFalse((bool) $cloned->json('data.is_system_role'));
        $this->assertSame($sourceCapabilities, DB::table('role_capabilities')->where('role_id', $cloneId)->orderBy('capability_id')->pluck('capability_id')->all());
        $this->assertDatabaseHas('roles', ['id' => $sourceId, 'code' => DevelopmentJourneyAuthorizationSeeder::ROLE_CODE, 'lock_version' => 1]);
        $this->assertSame($sourceCapabilities, DB::table('role_capabilities')->where('role_id', $sourceId)->orderBy('capability_id')->pluck('capability_id')->all());
        $this->assertAuditCount('authorization.role.cloned', $cloneId, DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 1);

        $systemRoleId = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::ROLE_CODE)->value('id');
        $beforeAuditCount = DB::table('audit_events')->count();
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/roles/'.$systemRoleId, ['status' => 'archived'], $this->patchHeaders(1, $csrf))
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'urn:cluster:problem:system-role-immutable');
        $this->assertSame($beforeAuditCount, DB::table('audit_events')->count());
    }

    public function test_assignment_create_revoke_expire_and_update_emit_one_audit_each(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $roleId = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::ROLE_CODE)->value('id');

        $revokedAssignmentId = $this->createAssignment($cookie, $csrf, $roleId, self::UNIT_A, 'assignment-revoke-create');
        $this->assertAuditCount('authorization.assignment.created', $revokedAssignmentId, DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 1);
        $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/role-assignments/'.$revokedAssignmentId, ['X-Correlation-ID' => self::CORRELATION_ID])
            ->assertOk()
            ->assertJsonPath('data.allowed_actions', ['edit', 'revoke', 'expire']);
        $revoked = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments/'.$revokedAssignmentId.'/revoke', [], [...$this->writeHeaders('assignment-revoke', $csrf), 'If-Match' => '"1"'])
            ->assertOk();
        $this->assertArrayNotHasKey('reason', $revoked->json('data'));
        $this->assertAuditCount('authorization.assignment.revoked', $revokedAssignmentId, DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 1);

        $expiredAssignmentId = $this->createAssignment($cookie, $csrf, $roleId, self::UNIT_A, 'assignment-expire-create');
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments/'.$expiredAssignmentId.'/expire', [], [...$this->writeHeaders('assignment-expire', $csrf), 'If-Match' => '"1"'])
            ->assertOk();
        $this->assertAuditCount('authorization.assignment.expired', $expiredAssignmentId, DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 1);

        $updatedAssignmentId = $this->createAssignment($cookie, $csrf, $roleId, self::UNIT_A, 'assignment-update-create');
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/role-assignments/'.$updatedAssignmentId, ['scope_id' => self::UNIT_B], $this->patchHeaders(1, $csrf))
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.scope_id', self::UNIT_B);
        $this->assertAuditCount('authorization.assignment.updated', $updatedAssignmentId, DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 1);
    }

    public function test_facility_only_admin_receives_forbidden_without_changing_an_out_of_scope_assignment(): void
    {
        [$adminCookie, $adminCsrf] = $this->loginAdminSession();
        [$facilityCookie, $facilityCsrf] = $this->loginFacilityAdminSession();
        $roleId = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::ROLE_CODE)->value('id');
        $assignmentId = $this->createAssignment($adminCookie, $adminCsrf, $roleId, self::UNIT_B, 'foreign-assignment-create');

        $this->withIdentitySession($facilityCookie)->postJson('/api/v1/authorization/role-assignments/'.$assignmentId.'/revoke', [], [...$this->writeHeaders('foreign-assignment-revoke', $facilityCsrf), 'If-Match' => '"1"'])
            ->assertForbidden()
            ->assertJsonPath('type', 'https://cluster.example/problems/access-denied');
        $this->assertDatabaseHas('role_assignments', ['id' => $assignmentId, 'status' => 'pending', 'lock_version' => 1]);
        $this->withIdentitySession($facilityCookie)->postJson('/api/v1/authorization/role-assignments/018f6f7d-0c00-7000-8000-00000000fa52/revoke', [], [...$this->writeHeaders('random-assignment-revoke', $facilityCsrf), 'If-Match' => '"1"'])
            ->assertForbidden()
            ->assertJsonPath('type', 'https://cluster.example/problems/access-denied');
        $this->withIdentitySession($facilityCookie)->patchJson('/api/v1/authorization/role-assignments/'.$assignmentId, ['scope_id' => ''], $this->patchHeaders(1, $facilityCsrf))
            ->assertForbidden()
            ->assertJsonPath('type', 'https://cluster.example/problems/access-denied');
        $this->withIdentitySession($facilityCookie)->patchJson('/api/v1/authorization/role-assignments/018f6f7d-0c00-7000-8000-00000000fa52', ['scope_id' => ''], $this->patchHeaders(1, $facilityCsrf))
            ->assertForbidden()
            ->assertJsonPath('type', 'https://cluster.example/problems/access-denied');
    }

    public function test_facility_admin_cannot_widen_assignment_scope_and_missing_role_patch_is_not_found(): void
    {
        [$adminCookie, $adminCsrf] = $this->loginAdminSession();
        [$facilityCookie, $facilityCsrf] = $this->loginFacilityAdminSession();
        $roleId = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::ROLE_CODE)->value('id');
        $assignmentId = $this->createAssignment($adminCookie, $adminCsrf, $roleId, self::UNIT_A, 'facility-scope-widen-create');

        $this->withIdentitySession($facilityCookie)->patchJson('/api/v1/authorization/role-assignments/'.$assignmentId, ['scope_id' => self::UNIT_B], $this->patchHeaders(1, $facilityCsrf))
            ->assertForbidden()
            ->assertJsonPath('type', 'https://cluster.example/problems/access-denied');
        $this->assertDatabaseHas('role_assignments', ['id' => $assignmentId, 'scope_id' => self::UNIT_A, 'lock_version' => 1]);
        $this->assertAuditCount('authorization.assignment.updated', $assignmentId, DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, 0);

        $this->withIdentitySession($adminCookie)->patchJson('/api/v1/authorization/roles/018f6f7d-0c00-7000-8000-00000000fa53', ['status' => 'archived'], $this->patchHeaders(1, $adminCsrf))
            ->assertNotFound()
            ->assertJsonPath('type', 'https://cluster.example/problems/resource-not-found');
    }

    private function createAssignment(string $cookie, string $csrf, string $roleId, string $scopeId, string $key): string
    {
        return (string) $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments', [
            'resource_type' => 'role_assignment',
            'user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_ID,
            'role_id' => $roleId,
            'scope_type' => 'unit',
            'scope_id' => $scopeId,
            'start_at' => '2026-07-01T00:00:00.000Z',
        ], $this->writeHeaders($key, $csrf))->assertCreated()->json('data.id');
    }

    private function assignClusterAuthorizationAdmin(): void
    {
        $roleId = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::AUTHORIZATION_ROLE_CODE)->value('id');
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');
        DB::table('role_assignments')->insertOrIgnore([
            'id' => Str::uuid7()->toString(),
            'user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => $clusterId,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedFacilityOnlyAdmin(): void
    {
        $now = now();
        DB::table('users')->insert([
            'id' => self::FACILITY_ADMIN_ID,
            'username' => self::FACILITY_ADMIN_USERNAME,
            'person_id' => null,
            'person_version' => null,
            'display_name_ar' => 'مسؤول منشأة فقط',
            'display_name_en' => 'Facility-only admin',
            'status' => 'active',
            'must_change_password' => false,
            'password_version' => 1,
            'last_login_at' => null,
            'failed_login_count' => 0,
            'lockout_level' => 0,
            'locked_until' => null,
            'lock_version' => 1,
            'is_admin' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $hasher = app(\Modules\Identity\Infrastructure\Security\PasswordHasher::class);
        DB::table('credentials')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::FACILITY_ADMIN_ID,
            'password_hash' => $hasher->hash(self::FACILITY_ADMIN_PASSWORD),
            'hash_algorithm' => $hasher->algorithm(),
            'password_changed_at' => $now,
            'policy_version' => 'identity-password-v1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $authorizationRoleId = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::AUTHORIZATION_ROLE_CODE)->value('id');
        DB::table('role_assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::FACILITY_ADMIN_ID,
            'role_id' => $authorizationRoleId,
            'scope_type' => 'facility',
            'scope_id' => DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedUnits(): void
    {
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');
        foreach ([
            [self::UNIT_A, DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID, 'UA51', 'وحدة أ'],
            [self::UNIT_B, DevelopmentJourneyAuthorizationSeeder::FACILITY_B_ID, 'UB52', 'وحدة ب'],
        ] as [$id, $facilityId, $code, $name]) {
            DB::table('organization_units')->insert([
                'id' => $id,
                'cluster_id' => $clusterId,
                'parent_id' => $facilityId,
                'parent_type' => 'facility',
                'unit_type_id' => '0197f0e0-0000-7000-8000-000000000202',
                'code' => $code,
                'name_ar' => $name,
                'status' => 'active',
                'path_cache' => '/'.$clusterId.'/'.$facilityId.'/'.$id,
                'depth' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array{0: string, 1: string} */
    private function loginAdminSession(): array
    {
        return $this->loginSession(DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_USERNAME, DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_PASSWORD);
    }

    /** @return array{0: string, 1: string} */
    private function loginFacilityAdminSession(): array
    {
        return $this->loginSession(self::FACILITY_ADMIN_USERNAME, self::FACILITY_ADMIN_PASSWORD);
    }

    /** @return array{0: string, 1: string} */
    private function loginSession(string $username, string $password): array
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'accounts-permissions HTTP adapter test']);
        $response = $this->postJson('/api/v1/identity/login', [
            'username' => $username,
            'password' => $password,
        ], ['X-Correlation-ID' => self::CORRELATION_ID]);
        $response->assertOk();
        $this->assertCount(1, $response->headers->getCookies());

        return [(string) $response->headers->getCookies()[0]->getValue(), (string) $response->json('data.csrf_token')];
    }

    private function withIdentitySession(string $cookie): self
    {
        return $this->withUnencryptedCookie(self::SESSION_COOKIE, $cookie)->withCredentials();
    }

    /** @return array<string, string> */
    private function writeHeaders(string $key, string $csrf): array
    {
        return [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'Idempotency-Key' => $key,
            'X-CSRF-Token' => $csrf,
        ];
    }

    /** @return array<string, string> */
    private function patchHeaders(int $version, string $csrf): array
    {
        return [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"'.$version.'"',
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ];
    }

    private function assertAuditCount(string $action, string $subjectId, string $actorId, int $expected): void
    {
        $this->assertSame($expected, DB::table('audit_events')->where([
            'action' => $action,
            'subject_id' => $subjectId,
            'actor_id' => $actorId,
            'outcome' => 'succeeded',
        ])->count());
    }
}
