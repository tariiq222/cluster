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

/**
 * Grant-integrity invariants over the authorization admin HTTP surface:
 *
 *  1. Grant authority is re-validated when a patch widens `end_at` on a
 *     role-assignment or delegation — an actor whose own grant has lapsed
 *     cannot extend grants they gave earlier beyond their own window.
 *  2. The status state machine is the only status path: `revoked` is
 *     terminal, `expired` requires the window to have closed, and status is
 *     not a mutable patch field.
 */
final class AuthorizationGrantIntegrityHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000c71';

    private const SESSION_COOKIE = 'cluster_identity_session';

    private const FACILITY_ADMIN_ID = '018f6f7d-0c00-7000-8000-00000000cc51';

    private const FACILITY_ADMIN_USERNAME = 'bounded-grant-integrity-admin';

    private const FACILITY_ADMIN_PASSWORD = 'Cedar!Orbit8Harbor2026';

    private const UNIT_A = '018f6f7d-0c00-7000-8000-00000000cc61';

    private const GRANT_ROLE_CODE = 'grant-integrity-reader';

    private const ACTOR_GRANT_END = '2026-12-31 23:59:59.999';

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
        $this->assignUnboundedClusterAdmin();
        $this->seedFacilityAdmin();
        $this->seedUnit();
    }

    public function test_expired_status_with_future_end_at_is_rejected_for_assignments_and_delegations(): void
    {
        [$cookie, $csrf] = $this->loginFacilityAdminSession();

        $assignmentId = $this->createAssignment($cookie, $csrf, now()->addDay()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'expire-future-assignment');
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments/'.$assignmentId.'/expire', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'expire-future-assignment',
            'X-CSRF-Token' => $csrf,
        ])->assertStatus(409)->assertJsonPath('type', 'urn:cluster:problem:invalid-grant-status');
        $this->assertDatabaseHas('role_assignments', ['id' => $assignmentId, 'status' => 'pending', 'lock_version' => 1]);

        $delegationId = $this->createDelegation($cookie, $csrf, now()->addDay()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'expire-future-delegation');
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations/'.$delegationId.'/expire', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'expire-future-delegation',
            'X-CSRF-Token' => $csrf,
        ])->assertStatus(409)->assertJsonPath('type', 'urn:cluster:problem:invalid-grant-status');
        $this->assertDatabaseHas('delegations', ['id' => $delegationId, 'status' => 'pending', 'lock_version' => 1]);
    }

    public function test_revoked_grant_cannot_become_active_again(): void
    {
        [$cookie, $csrf] = $this->loginFacilityAdminSession();

        $assignmentId = $this->createAssignment($cookie, $csrf, '2026-10-01T00:00:00.000Z', 'revoke-terminal-assignment');
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments/'.$assignmentId.'/revoke', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'revoke-terminal-assignment',
            'X-CSRF-Token' => $csrf,
        ])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments/'.$assignmentId.'/activate', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"2"',
            'Idempotency-Key' => 'activate-revoked-assignment',
            'X-CSRF-Token' => $csrf,
        ])->assertStatus(409)->assertJsonPath('type', 'urn:cluster:problem:invalid-grant-status');
        $this->assertDatabaseHas('role_assignments', ['id' => $assignmentId, 'status' => 'revoked', 'lock_version' => 2]);

        $delegationId = $this->createDelegation($cookie, $csrf, '2026-10-01T00:00:00.000Z', 'revoke-terminal-delegation');
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations/'.$delegationId.'/revoke', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'revoke-terminal-delegation',
            'X-CSRF-Token' => $csrf,
        ])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations/'.$delegationId.'/activate', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"2"',
            'Idempotency-Key' => 'activate-revoked-delegation',
            'X-CSRF-Token' => $csrf,
        ])->assertStatus(409)->assertJsonPath('type', 'urn:cluster:problem:invalid-grant-status');
        $this->assertDatabaseHas('delegations', ['id' => $delegationId, 'status' => 'revoked', 'lock_version' => 2]);
    }

    public function test_status_is_not_a_patchable_field_anymore(): void
    {
        [$cookie, $csrf] = $this->loginFacilityAdminSession();

        $assignmentId = $this->createAssignment($cookie, $csrf, '2026-10-01T00:00:00.000Z', 'patch-status-assignment');
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/role-assignments/'.$assignmentId, ['status' => 'active'], $this->patchHeaders(1, $csrf))
            ->assertUnprocessable()->assertJsonPath('type', 'https://cluster.example/problems/invalid-authorization-resource');
        $this->assertDatabaseHas('role_assignments', ['id' => $assignmentId, 'status' => 'pending', 'lock_version' => 1]);

        $delegationId = $this->createDelegation($cookie, $csrf, '2026-10-01T00:00:00.000Z', 'patch-status-delegation');
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/delegations/'.$delegationId, ['status' => 'active'], $this->patchHeaders(1, $csrf))
            ->assertUnprocessable()->assertJsonPath('type', 'https://cluster.example/problems/invalid-authorization-resource');
        $this->assertDatabaseHas('delegations', ['id' => $delegationId, 'status' => 'pending', 'lock_version' => 1]);
    }

    public function test_end_at_extension_beyond_actor_window_is_rejected_for_assignments_and_delegations(): void
    {
        [$cookie, $csrf] = $this->loginFacilityAdminSession();

        $assignmentId = $this->createAssignment($cookie, $csrf, '2026-10-01T00:00:00.000Z', 'extend-beyond-assignment-create');
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/role-assignments/'.$assignmentId, ['end_at' => '2027-06-01T00:00:00.000Z'], $this->patchHeaders(1, $csrf))
            ->assertStatus(409)->assertJsonPath('type', 'urn:cluster:problem:grant-authority-invalid');
        $this->assertDatabaseHas('role_assignments', [
            'id' => $assignmentId,
            'end_at' => '2026-10-01 00:00:00.000',
            'lock_version' => 1,
        ]);

        $delegationId = $this->createDelegation($cookie, $csrf, '2026-10-01T00:00:00.000Z', 'extend-beyond-delegation-create');
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/delegations/'.$delegationId, ['end_at' => '2027-06-01T00:00:00.000Z'], $this->patchHeaders(1, $csrf))
            ->assertStatus(409)->assertJsonPath('type', 'urn:cluster:problem:grant-authority-invalid');
        $this->assertDatabaseHas('delegations', [
            'id' => $delegationId,
            'end_at' => '2026-10-01 00:00:00.000',
            'lock_version' => 1,
        ]);
    }

    public function test_end_at_extension_while_actor_still_covers_it_is_accepted(): void
    {
        [$cookie, $csrf] = $this->loginFacilityAdminSession();

        $assignmentId = $this->createAssignment($cookie, $csrf, '2026-10-01T00:00:00.000Z', 'extend-within-assignment-create');
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/role-assignments/'.$assignmentId, ['end_at' => '2026-11-01T00:00:00.000Z'], $this->patchHeaders(1, $csrf))
            ->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.end_at', '2026-11-01 00:00:00.000');
        $this->assertDatabaseHas('role_assignments', ['id' => $assignmentId, 'end_at' => '2026-11-01 00:00:00.000', 'lock_version' => 2]);

        $delegationId = $this->createDelegation($cookie, $csrf, '2026-10-01T00:00:00.000Z', 'extend-within-delegation-create');
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/delegations/'.$delegationId, ['end_at' => '2026-11-01T00:00:00.000Z'], $this->patchHeaders(1, $csrf))
            ->assertOk()->assertHeader('ETag', '"2"')->assertJsonPath('data.end_at', '2026-11-01 00:00:00.000');
        $this->assertDatabaseHas('delegations', ['id' => $delegationId, 'end_at' => '2026-11-01 00:00:00.000', 'lock_version' => 2]);
    }

    private function assignUnboundedClusterAdmin(): void
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

    /**
     * A facility-scoped administrator whose own grants are BOUNDED to
     * 2026-12-31: it can grant inside its own window but must not extend
     * grants beyond it. The authorization role opens the admin surface; the
     * custom reader role proves direct capability coverage for delegations.
     */
    private function seedFacilityAdmin(): void
    {
        $now = now();
        if (! DB::table('users')->where('id', self::FACILITY_ADMIN_ID)->exists()) {
            DB::table('users')->insert([
                'id' => self::FACILITY_ADMIN_ID,
                'username' => self::FACILITY_ADMIN_USERNAME,
                'person_id' => null,
                'person_version' => null,
                'display_name_ar' => 'مسؤول صلاحيات محدود النطاق',
                'display_name_en' => 'Bounded grant-integrity admin',
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
        }
        $authorizationRoleId = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::AUTHORIZATION_ROLE_CODE)->value('id');
        DB::table('role_assignments')->insertOrIgnore([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::FACILITY_ADMIN_ID,
            'role_id' => $authorizationRoleId,
            'scope_type' => 'facility',
            'scope_id' => DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => self::ACTOR_GRANT_END,
            'status' => 'active',
            'granted_by_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $readerRoleId = (string) DB::table('roles')->where('code', self::GRANT_ROLE_CODE)->value('id');
        if ($readerRoleId === '') {
            [$adminCookie, $adminCsrf] = $this->loginAdminSession();
            $readerRoleId = (string) $this->withIdentitySession($adminCookie)
                ->postJson('/api/v1/authorization/roles', [
                    'resource_type' => 'role',
                    'code' => self::GRANT_ROLE_CODE,
                    'name' => 'قارئ سجلات',
                    'role_type' => 'operational',
                    'capability_codes' => ['tasks.read'],
                ], [
                    'X-Correlation-ID' => self::CORRELATION_ID,
                    'Idempotency-Key' => 'grant-integrity-reader-role',
                    'X-CSRF-Token' => $adminCsrf,
                ])->assertCreated()->json('data.id');
        }
        DB::table('role_assignments')->insertOrIgnore([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::FACILITY_ADMIN_ID,
            'role_id' => $readerRoleId,
            'scope_type' => 'facility',
            'scope_id' => DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => self::ACTOR_GRANT_END,
            'status' => 'active',
            'granted_by_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedUnit(): void
    {
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');
        DB::table('organization_units')->insertOrIgnore([
            'id' => self::UNIT_A,
            'cluster_id' => $clusterId,
            'parent_id' => DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID,
            'parent_type' => 'facility',
            'unit_type_id' => '0197f0e0-0000-7000-8000-000000000202',
            'code' => 'GIUA',
            'name_ar' => 'وحدة نزاهة الصلاحيات',
            'status' => 'active',
            'path_cache' => '/'.$clusterId.'/'.DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID.'/'.self::UNIT_A,
            'depth' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAssignment(string $cookie, string $csrf, string $endAt, string $key): string
    {
        $roleId = (string) DB::table('roles')->where('code', self::GRANT_ROLE_CODE)->value('id');

        return (string) $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments', [
            'resource_type' => 'role_assignment',
            'user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_ID,
            'role_id' => $roleId,
            'scope_type' => 'unit',
            'scope_id' => self::UNIT_A,
            'start_at' => '2026-07-01T00:00:00.000Z',
            'end_at' => $endAt,
        ], $this->writeHeaders($key, $csrf))->assertCreated()->json('data.id');
    }

    private function createDelegation(string $cookie, string $csrf, string $endAt, string $key): string
    {
        return (string) $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations', [
            'resource_type' => 'delegation',
            'delegator_user_id' => self::FACILITY_ADMIN_ID,
            'delegate_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_ID,
            'module_code' => 'tasks',
            'capability_codes' => ['tasks.read'],
            'scope_type' => 'unit',
            'scope_id' => self::UNIT_A,
            'start_at' => '2026-07-01T00:00:00.000Z',
            'end_at' => $endAt,
        ], $this->writeHeaders($key, $csrf))->assertCreated()->json('data.id');
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
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'grant-integrity HTTP adapter test']);
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
}
