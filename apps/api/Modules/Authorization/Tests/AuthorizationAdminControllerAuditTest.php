<?php

declare(strict_types=1);

namespace Modules\Authorization\Tests;

use App\Http\Authentication\SessionPrincipalResolver;
use Database\Seeders\AuthorizationCatalogSeeder;
use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Features\Administration\Application\AuthorizationAdminService;
use Modules\Authorization\Features\Administration\Http\AuthorizationAdminController;
use Modules\Authorization\Features\DecideAccess\Http\DecideAccessController;
use Modules\Authorization\Features\ExplainAccessDecision\Http\ExplainAccessDecisionController;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use RuntimeException;
use Shared\Contracts\RecordAuditEvent;
use Tests\TestCase;

/**
 * Task 4 — Controller-level audit/If-Match/bodyless/allowed_actions tests.
 *
 * The controller is the delegation edge: it must inject the new
 * AuthorizationAdminService, never open a DB::transaction, preserve the
 * existing idempotency replay and If-Match problem mappings, accept
 * bodyless transitions for revoke/expire, and attach allowed_actions to
 * role/role_assignment/role_capability responses.
 *
 * The principal is backed by the development journey seeder so the
 * existing HTTP fixtures (cookie + CSRF) boot without re-implementing
 * authorization gating. Each test seeds a role assignment that wires
 * the custom role into the principal's scope so the gateway's actor
 * scope predicate lets the principal see and mutate the fixture rows.
 */
final class AuthorizationAdminControllerAuditTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000c01';

    private const SESSION_COOKIE = 'cluster_identity_session';

    /** Matches DevelopmentJourneyAuthorizationSeeder::$clusterId hardcoded value. */
    private const CLUSTER = '018f6f7d-0c00-7000-8000-00000000c113';

    private const PRINCIPAL_ID = DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID;

    private const ROLE_ID = '018f6f7d-0c00-7000-8000-000000000c02';

    private const ASSIGNMENT_ID = '018f6f7d-0c00-7000-8000-000000000c03';

    private const SUBJECT_USER_ID = '018f6f7d-0c00-7000-8000-000000000c04';

    private const SCOPE_ASSIGNEE_ID = '018f6f7d-0c00-7000-8000-000000000c20';

    private ControllingSharedAuditRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        config()->set('identity.session_only', true);
        $this->app->when([
            AuthorizationAdminController::class,
            DecideAccessController::class,
            ExplainAccessDecisionController::class,
        ])->needs(ResolveDevelopmentFixturePrincipal::class)
            ->give(fn ($app) => $app->make(SessionPrincipalResolver::class));
        $this->bindControllerAuditRecorder();
        $this->markBootstrapComplete();
        $this->grantClusterAuthority();
    }

    private function bindControllerAuditRecorder(): void
    {
        $this->recorder = new ControllingSharedAuditRecorder;
        $this->app->instance(RecordAuditEvent::class, $this->recorder);
        $this->app->forgetInstance(AuthorizationAdminService::class);
    }

    private function markBootstrapComplete(): void
    {
        DB::table('authorization_bootstrap')->update([
            'state' => 'complete',
            'completed_by_user_id' => self::PRINCIPAL_ID,
            'completed_at' => now(),
            'lock_version' => 2,
            'updated_at' => now(),
        ]);
    }

    private function grantClusterAuthority(): void
    {
        $authorizationRoleId = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::AUTHORIZATION_ROLE_CODE)->value('id');
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');
        if ($clusterId === '') {
            $clusterId = self::CLUSTER;
        }
        DB::table('role_assignments')->insertOrIgnore([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::PRINCIPAL_ID,
            'role_id' => $authorizationRoleId,
            'scope_type' => 'cluster',
            'scope_id' => $clusterId,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::PRINCIPAL_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_post_role_creates_role_records_exactly_one_audit_event_and_attaches_allowed_actions(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();

        $response = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', [
            'resource_type' => 'role',
            'code' => 'controller-create-role',
            'name' => 'دور تحكم',
            'role_type' => 'custom',
        ], $this->writeHeaders('controller-create-role', $csrf));

        $response->assertCreated()->assertHeader('ETag', '"1"');
        $id = (string) $response->json('data.id');
        $this->assertSame(1, count($this->recorder->calls));
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization.role.created', $event['action']);
        $this->assertSame(self::PRINCIPAL_ID, $event['actor_id']);
        $this->assertSame($id, $event['subject_id']);
        $this->assertSame($this->correlationId($response), $event['correlation_id']);
        $this->assertIsArray($response->json('data.allowed_actions'));
        $this->assertNotEmpty($response->json('data.allowed_actions'));
    }

    public function test_duplicate_role_code_remains_an_authorization_conflict(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $payload = ['resource_type' => 'role', 'code' => 'controller-duplicate-role', 'name' => 'دور مكرر', 'role_type' => 'custom'];

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', $payload, $this->writeHeaders('duplicate-role-first', $csrf))->assertCreated();
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', $payload, $this->writeHeaders('duplicate-role-second', $csrf))
            ->assertStatus(409)
            ->assertJsonPath('type', 'https://cluster.example/problems/authorization-conflict');
    }

    public function test_post_role_returns_400_when_idempotency_key_is_missing(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', [
            'resource_type' => 'role',
            'code' => 'controller-no-key',
            'name' => 'دور',
            'role_type' => 'custom',
        ], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'X-CSRF-Token' => $csrf,
        ])->assertStatus(400);
    }

    public function test_post_role_returns_500_and_keeps_database_unchanged_when_audit_throws(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $this->recorder->failure = new RuntimeException('audit_failure_simulated');
        $callsBefore = count($this->recorder->calls);
        $code = 'controller-audit-throw';

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', [
            'resource_type' => 'role',
            'code' => $code,
            'name' => 'دور فاشل',
            'role_type' => 'custom',
        ], $this->writeHeaders('controller-audit-throw', $csrf))->assertStatus(500);

        $this->assertSame($callsBefore + 1, count($this->recorder->calls));
        $this->assertSame(0, (int) DB::table('roles')->where('code', $code)->count(), 'Failed audit must leave no role row.');
    }

    public function test_patch_role_uses_merge_patch_content_type_and_if_match(): void
    {
        $this->seedCustomRole();
        [$cookie, $csrf] = $this->loginAdminSession();

        $response = $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/roles/'.self::ROLE_ID, [
            'name_ar' => 'اسم جديد',
        ], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ]);

        $response->assertOk()->assertHeader('ETag', '"2"');
        $this->assertSame(1, count($this->recorder->calls));
        $this->assertSame('authorization.role.updated', $this->recorder->calls[0]['action']);
    }

    public function test_patch_role_returns_400_when_if_match_header_is_missing(): void
    {
        $this->seedCustomRole();
        [$cookie, $csrf] = $this->loginAdminSession();

        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/roles/'.self::ROLE_ID, [
            'name_ar' => 'اسم جديد',
        ], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ])->assertStatus(400);
    }

    public function test_clone_role_returns_400_when_if_match_is_missing(): void
    {
        $this->seedSystemRole();
        $this->seedRoleCapability(self::ROLE_ID, 'work_record.read');
        [$cookie, $csrf] = $this->loginAdminSession();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles/'.self::ROLE_ID.'/clone', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'Idempotency-Key' => 'controller-clone-no-if',
            'X-CSRF-Token' => $csrf,
        ])->assertStatus(400);
    }

    public function test_clone_role_requires_an_idempotency_key(): void
    {
        $this->seedSystemRole();
        [$cookie, $csrf] = $this->loginAdminSession();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles/'.self::ROLE_ID.'/clone', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'X-CSRF-Token' => $csrf,
        ])->assertStatus(400);
    }

    public function test_clone_role_with_if_match_records_one_audit_event_and_attaches_allowed_actions(): void
    {
        $this->seedSystemRole();
        $this->seedRoleCapability(self::ROLE_ID, 'work_record.read');
        [$cookie, $csrf] = $this->loginAdminSession();

        $response = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles/'.self::ROLE_ID.'/clone', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'controller-clone-ok',
            'X-CSRF-Token' => $csrf,
        ]);

        $response->assertOk()->assertHeader('ETag', '"1"');
        $clonedId = (string) $response->json('data.id');
        $this->assertNotSame(self::ROLE_ID, $clonedId);
        $this->assertSame(1, count($this->recorder->calls));
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization.role.cloned', $event['action']);
        $this->assertSame($clonedId, $event['subject_id']);
        $this->assertIsArray($response->json('data.allowed_actions'));
        $this->assertContains('edit', $response->json('data.allowed_actions'));
    }

    public function test_post_role_assignment_create_records_one_audit_event_and_attaches_allowed_actions(): void
    {
        $this->seedCustomRole();
        $this->seedRoleCapability(self::ROLE_ID, 'work_record.read');
        $this->seedUser(self::SUBJECT_USER_ID);
        [$cookie, $csrf] = $this->loginAdminSession();

        $response = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments', [
            'resource_type' => 'role_assignment',
            'user_id' => self::SUBJECT_USER_ID,
            'role_id' => self::ROLE_ID,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER,
            'start_at' => '2026-07-01T00:00:00.000Z',
        ], $this->writeHeaders('controller-assignment-create', $csrf));

        $response->assertCreated();
        $assignmentId = (string) $response->json('data.id');
        $this->assertSame(1, count($this->recorder->calls));
        $this->assertSame('authorization.assignment.created', $this->recorder->calls[0]['action']);
        $this->assertSame($assignmentId, $this->recorder->calls[0]['subject_id']);
        $allowedActions = $response->json('data.allowed_actions');
        $this->assertIsArray($allowedActions);
        $this->assertContains('revoke', $allowedActions);
        $this->assertContains('expire', $allowedActions);
    }

    public function test_post_assignment_revoke_accepts_bodyless_request_and_records_one_audit_event(): void
    {
        $this->seedAssignment();
        [$cookie, $csrf] = $this->loginAdminSession();

        $response = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments/'.self::ASSIGNMENT_ID.'/revoke', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'controller-assignment-revoke',
            'X-CSRF-Token' => $csrf,
        ]);

        $response->assertOk();
        $this->assertSame(1, count($this->recorder->calls));
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization.assignment.revoked', $event['action']);
        $this->assertSame(self::ASSIGNMENT_ID, $event['subject_id']);
        $this->assertSame('revoked', (string) DB::table('role_assignments')->where('id', self::ASSIGNMENT_ID)->value('status'));
    }

    public function test_post_assignment_expire_accepts_bodyless_request_and_records_one_audit_event(): void
    {
        $this->seedAssignment();
        [$cookie, $csrf] = $this->loginAdminSession();

        $response = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments/'.self::ASSIGNMENT_ID.'/expire', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'controller-assignment-expire',
            'X-CSRF-Token' => $csrf,
        ]);

        $response->assertOk();
        $this->assertSame(1, count($this->recorder->calls));
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization.assignment.expired', $event['action']);
        $this->assertSame('expired', (string) DB::table('role_assignments')->where('id', self::ASSIGNMENT_ID)->value('status'));
    }

    public function test_post_role_capability_revoke_records_one_audit_event(): void
    {
        $this->seedCustomRole();
        $this->seedRoleCapability(self::ROLE_ID, 'work_record.read');
        $capabilityId = (string) DB::table('capabilities')->where('capability_code', 'work_record.read')->value('id');
        [$cookie, $csrf] = $this->loginAdminSession();

        $response = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-capabilities/'.self::ROLE_ID.':'.$capabilityId.'/revoke', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'controller-capability-revoke',
            'X-CSRF-Token' => $csrf,
        ]);

        $response->assertOk();
        $this->assertSame(1, count($this->recorder->calls));
        $event = $this->recorder->calls[0];
        $this->assertSame('authorization.role_capability.revoked', $event['action']);
        $this->assertSame(0, (int) DB::table('role_capabilities')->where('role_id', self::ROLE_ID)->where('capability_id', $capabilityId)->count());
    }

    public function test_show_role_includes_allowed_actions(): void
    {
        $this->seedCustomRole();
        [$cookie, $csrf] = $this->loginAdminSession();

        $response = $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/roles/'.self::ROLE_ID, [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ]);

        $response->assertOk();
        $this->assertIsArray($response->json('data.allowed_actions'));
        $this->assertNotEmpty($response->json('data.allowed_actions'));
    }

    public function test_post_role_replays_idempotency_response_without_recording_audit(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $body = [
            'resource_type' => 'role',
            'code' => 'controller-replay-role',
            'name' => 'دور إعادة',
            'role_type' => 'custom',
        ];
        $headers = $this->writeHeaders('controller-replay-role', $csrf);

        $first = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', $body, $headers)->assertCreated();
        $second = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', $body, $headers)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, count($this->recorder->calls), 'Idempotency replay must NOT invoke the audit port.');
    }

    public function test_controller_source_never_opens_a_database_transaction(): void
    {
        $source = file_get_contents((new \ReflectionClass(AuthorizationAdminController::class))->getFileName());

        $this->assertNotFalse($source, 'Controller source must be readable.');
        $this->assertStringNotContainsString('DB::transaction(', $source, 'Controller must NOT open DB::transaction; the service owns the transactional boundary.');
    }

    private function correlationId($response): string
    {
        $header = $response->headers->get('X-Correlation-ID');
        $this->assertIsString($header);

        return $header;
    }

    private function seedCustomRole(): void
    {
        if (DB::table('roles')->where('id', self::ROLE_ID)->exists()) {
            return;
        }
        DB::table('roles')->insert([
            'id' => self::ROLE_ID,
            'code' => 'controller-custom-role',
            'name_ar' => 'دور تحكم مخصص',
            'name_en' => 'Controller custom role',
            'role_type' => 'custom',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
        $this->seedRoleScopeAssignment(self::ROLE_ID);
    }

    private function seedSystemRole(): void
    {
        if (DB::table('roles')->where('id', self::ROLE_ID)->exists()) {
            return;
        }
        DB::table('roles')->insert([
            'id' => self::ROLE_ID,
            'code' => 'controller-system-role',
            'name_ar' => 'دور تحكم نظامي',
            'name_en' => 'Controller system role',
            'role_type' => 'system',
            'status' => 'active',
            'is_system_role' => true,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
        $this->seedRoleScopeAssignment(self::ROLE_ID);
    }

    private function seedRoleScopeAssignment(string $roleId): void
    {
        if (DB::table('role_assignments')->where('role_id', $roleId)->where('scope_id', self::CLUSTER)->exists()) {
            return;
        }
        $this->seedUser(self::SCOPE_ASSIGNEE_ID);
        DB::table('role_assignments')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000009c'.bin2hex(random_bytes(4)),
            'user_id' => self::SCOPE_ASSIGNEE_ID,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::PRINCIPAL_ID,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
    }

    private function seedRoleCapability(string $roleId, string $capabilityCode): void
    {
        $capabilityId = (string) DB::table('capabilities')->where('capability_code', $capabilityCode)->value('id');
        DB::table('role_capabilities')->insert([
            'role_id' => $roleId,
            'capability_id' => $capabilityId,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
    }

    private function seedUser(string $userId): void
    {
        if (DB::table('users')->where('id', $userId)->exists()) {
            return;
        }
        DB::table('users')->insert([
            'id' => $userId,
            'username' => 'controller-'.$userId,
            'person_id' => null,
            'person_version' => null,
            'display_name_ar' => 'مستخدم',
            'display_name_en' => 'User',
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

    private function seedAssignment(): void
    {
        $this->seedCustomRole();
        $this->seedRoleCapability(self::ROLE_ID, 'work_record.read');
        $this->seedUser(self::SUBJECT_USER_ID);
        if (DB::table('role_assignments')->where('id', self::ASSIGNMENT_ID)->exists()) {
            return;
        }
        DB::table('role_assignments')->insert([
            'id' => self::ASSIGNMENT_ID,
            'user_id' => self::SUBJECT_USER_ID,
            'role_id' => self::ROLE_ID,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::PRINCIPAL_ID,
            'created_at' => now(),
            'updated_at' => now(),
            'lock_version' => 1,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function loginAdminSession(): array
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'Controller audit test']);
        $response = $this->postJson('/api/v1/identity/login', [
            'username' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_USERNAME,
            'password' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_PASSWORD,
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
    private function writeHeaders(string $key, string $csrf): array
    {
        return [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'Idempotency-Key' => $key,
            'X-CSRF-Token' => $csrf,
        ];
    }
}

/**
 * Test double for the shared audit port used by the controller test.
 * The controller issues exactly one audit event per mutation, so the
 * recorder's $calls array is the canonical evidence.
 */
final class ControllingSharedAuditRecorder implements RecordAuditEvent
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public ?\Throwable $failure = null;

    public function record(array $event): void
    {
        $this->calls[] = $event;

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
