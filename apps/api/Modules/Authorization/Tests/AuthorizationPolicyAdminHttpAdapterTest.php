<?php

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

    private const FACILITY_ADMIN_ID = '018f6f7d-0c00-7000-8000-00000000fa01';

    private const FACILITY_ADMIN_USERNAME = 'facility-only-admin';

    private const FACILITY_ADMIN_PASSWORD = 'Cedar!Orbit8Harbor2026';

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
            ->give(fn () => $engine);
        $this->app->when([
            AuthorizationAdminController::class,
            DecideAccessController::class,
            ExplainAccessDecisionController::class,
        ])->needs(ResolveDevelopmentFixturePrincipal::class)
            ->give(fn ($app) => $app->make(SessionPrincipalResolver::class));
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        config()->set('identity.session_only', true);
        DB::table('authorization_bootstrap')->update([
            'state' => 'complete',
            'completed_by_user_id' => self::ADMIN_ID,
            'completed_at' => now(),
            'lock_version' => 2,
            'updated_at' => now(),
        ]);
        $authorizationRoleId = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::AUTHORIZATION_ROLE_CODE)->value('id');
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');
        if ($clusterId === '') {
            $clusterId = self::CLUSTER;
        }
        DB::table('role_assignments')->insertOrIgnore([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::ADMIN_ID,
            'role_id' => $authorizationRoleId,
            'scope_type' => 'cluster',
            'scope_id' => $clusterId,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::ADMIN_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedFacilityAdmin();
        $this->seedOrganizationTree();
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
        [$cookie, $csrf] = $this->loginFacilityAdminSession();
        $foreignRole = Str::uuid7()->toString();
        DB::table('roles')->insert([
            'id' => $foreignRole,
            'code' => 'foreign-only-role',
            'name_ar' => 'دور خارجي',
            'role_type' => 'operational',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assignRole(DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_ID, $foreignRole, 'facility', DevelopmentJourneyAuthorizationSeeder::FACILITY_B_ID, null);
        $foreignCapability = (string) DB::table('capabilities')->where('capability_code', 'work_record.read')->value('id');
        DB::table('role_capabilities')->updateOrInsert([
            'role_id' => $foreignRole,
            'capability_id' => $foreignCapability,
        ], [
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

    public function test_role_capability_attach_requires_cluster_covering_authority(): void
    {
        [$cookie, $csrf] = $this->loginFacilityAdminSession();
        $roleId = $this->createRole($cookie, $csrf, 'facility_attach', self::FACILITY_ADMIN_ID, 'facility', self::FACILITY);

        // A facility-only administrator holds authorization.assignment.manage at
        // facility scope, which does not cover the cluster-scoped grant-authority
        // proof required to arm a role with a capability.
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-capabilities', [
            'resource_type' => 'role_capability',
            'role_id' => $roleId,
            'capability_code' => 'audit.event.export',
            'effect' => 'allow',
        ], $this->writeHeaders('rc-attach-no-authority', $csrf))->assertUnprocessable();
        $this->assertDatabaseMissing('role_capabilities', ['role_id' => $roleId]);
    }

    public function test_explicit_deny_revoke_and_expire_require_revocable(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $created = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/explicit-denies', [
            'resource_type' => 'explicit_deny',
            'user_id' => self::ADMIN_ID,
            'capability_code' => 'work_record.read',
            'reason' => 'منع غير قابل للإلغاء',
            'revocable' => false,
        ], $this->writeHeaders('deny-locked', $csrf))->assertCreated();
        $id = (string) $created->json('data.id');

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/explicit-denies/'.$id.'/revoke', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'deny-revoke-locked',
            'X-CSRF-Token' => $csrf,
        ])->assertUnprocessable();
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/explicit-denies/'.$id.'/expire', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'deny-expire-locked',
            'X-CSRF-Token' => $csrf,
        ])->assertUnprocessable();
        $this->assertNull(DB::table('explicit_denies')->where('id', $id)->value('expires_at'));

        $revocable = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/explicit-denies', [
            'resource_type' => 'explicit_deny',
            'user_id' => self::ADMIN_ID,
            'capability_code' => 'work_record.list',
            'reason' => 'منع قابل للإلغاء',
        ], $this->writeHeaders('deny-revocable', $csrf))->assertCreated();
        $revocableId = (string) $revocable->json('data.id');
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/explicit-denies/'.$revocableId.'/revoke', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'deny-revoke-allowed',
            'X-CSRF-Token' => $csrf,
        ])->assertOk();
        $this->assertNotNull(DB::table('explicit_denies')->where('id', $revocableId)->value('expires_at'));
    }

    public function test_classification_policy_validates_transfer_capability_and_classification(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/classification-policies', [
            'resource_type' => 'classification_policy',
            'classification_code' => 'confidential',
            'minimum_capability' => 'work_record.read',
            'export_policy' => 'auditx',
            'download_policy' => 'deny',
        ], $this->writeHeaders('policy-bad-export', $csrf))->assertUnprocessable();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/classification-policies', [
            'resource_type' => 'classification_policy',
            'classification_code' => 'confidential',
            'minimum_capability' => 'work_record.destroy',
            'export_policy' => 'audit',
            'download_policy' => 'deny',
        ], $this->writeHeaders('policy-bad-capability', $csrf))->assertUnprocessable();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/classification-policies', [
            'resource_type' => 'classification_policy',
            'classification_code' => 'mega_secret',
            'minimum_capability' => 'work_record.read',
            'export_policy' => 'audit',
            'download_policy' => 'deny',
        ], $this->writeHeaders('policy-bad-classification', $csrf))->assertUnprocessable();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/classification-policies', [
            'resource_type' => 'classification_policy',
            'classification_code' => 'confidential',
            'minimum_capability' => 'work_record.read',
            'export_policy' => 'audit',
            'download_policy' => 'deny',
        ], $this->writeHeaders('policy-valid', $csrf))->assertCreated();
    }

    public function test_field_access_template_validates_key_and_policy_rules(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/field-access-templates', [
            'resource_type' => 'field_access_template',
            'field_policy_key' => '',
            'module_code' => 'work_record',
            'policy_document' => ['fields' => ['national_id' => 'mask']],
        ], $this->writeHeaders('template-empty-key', $csrf))->assertUnprocessable();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/field-access-templates', [
            'resource_type' => 'field_access_template',
            'field_policy_key' => 'wr_confidential_v1',
            'module_code' => 'work_record',
            'policy_document' => ['fields' => ['national_id' => 'bogus']],
        ], $this->writeHeaders('template-bad-rule', $csrf))->assertUnprocessable();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/field-access-templates', [
            'resource_type' => 'field_access_template',
            'field_policy_key' => 'wr_confidential_v1',
            'module_code' => 'work_record',
            'policy_document' => ['fields' => ['national_id' => 'mask']],
        ], $this->writeHeaders('template-valid', $csrf))->assertCreated();
    }

    public function test_role_patch_rejects_unknown_status(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $roleId = $this->createRole($cookie, $csrf, 'status_guard');

        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/roles/'.$roleId, ['status' => 'garbage'], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ])->assertUnprocessable();
        $this->assertSame('active', DB::table('roles')->where('id', $roleId)->value('status'));
    }

    public function test_role_assignment_requires_an_active_role_with_capabilities(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments', [
            'resource_type' => 'role_assignment',
            'user_id' => '018f6f7d-0c00-7000-8000-00000000ee01',
            'role_id' => Str::uuid7()->toString(),
            'scope_type' => 'facility',
            'scope_id' => self::FACILITY,
            'start_at' => '2026-07-01T00:00:00.000Z',
        ], $this->writeHeaders('assignment-missing-role', $csrf))->assertUnprocessable();

        $archivedRoleId = $this->createRole($cookie, $csrf, 'archived_role');
        $this->attach($cookie, $csrf, $archivedRoleId, 'work_record.read');
        DB::table('roles')->where('id', $archivedRoleId)->update(['status' => 'archived', 'updated_at' => now()]);
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments', [
            'resource_type' => 'role_assignment',
            'user_id' => '018f6f7d-0c00-7000-8000-00000000ee01',
            'role_id' => $archivedRoleId,
            'scope_type' => 'facility',
            'scope_id' => self::FACILITY,
            'start_at' => '2026-07-01T00:00:00.000Z',
        ], $this->writeHeaders('assignment-archived-role', $csrf))->assertUnprocessable();

        $emptyRoleId = $this->createRole($cookie, $csrf, 'empty_role');
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments', [
            'resource_type' => 'role_assignment',
            'user_id' => '018f6f7d-0c00-7000-8000-00000000ee01',
            'role_id' => $emptyRoleId,
            'scope_type' => 'facility',
            'scope_id' => self::FACILITY,
            'start_at' => '2026-07-01T00:00:00.000Z',
        ], $this->writeHeaders('assignment-empty-role', $csrf))->assertUnprocessable();
    }

    public function test_collection_link_header_is_rfc8288_compliant(): void
    {
        [$cookie] = $this->loginAdminSession();

        $page = $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/roles?limit=1', [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertOk();
        $this->assertNotNull($page->json('next_cursor'));
        $link = (string) $page->headers->get('Link');
        $this->assertMatchesRegularExpression('/^\<.+\>; rel="next"$/', $link);
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
        $roleId = $this->createRole($cookie, $csrf, 'facility_delegator', assignVisible: false);
        $this->assignRole($delegator, $roleId, 'facility', self::FACILITY, null);
        $this->attach($cookie, $csrf, $roleId, 'work_record.read');

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

        $windowedRoleId = $this->createRole($cookie, $csrf, 'windowed_delegator', assignVisible: false);
        $this->assignRole($delegator, $windowedRoleId, 'unit', self::UNIT_B, '2026-07-20 00:00:00.000', '2026-07-10 00:00:00.000');
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

    public function test_delegation_lifecycle_immediately_updates_effective_capabilities_through_http(): void
    {
        [$cookie, $csrf] = $this->loginAdminSession();
        $this->seedOrgTree();
        $delegate = '018f6f7d-0c00-7000-8000-00000000dd03';
        $expiredDelegate = '018f6f7d-0c00-7000-8000-00000000dd04';
        $roleId = $this->createRole($cookie, $csrf, 'lifecycle_delegator');
        $this->attach($cookie, $csrf, $roleId, 'work_record.read');
        $this->assignRole(self::ADMIN_ID, $roleId, 'facility', self::FACILITY, null);

        $created = $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations', [
            'resource_type' => 'delegation',
            'delegator_user_id' => self::ADMIN_ID,
            'delegate_user_id' => $delegate,
            'module_code' => 'work_record',
            'capability_codes' => ['work_record.read'],
            'scope_type' => 'unit',
            'scope_id' => self::UNIT_A,
            'start_at' => now()->subMinute()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'end_at' => now()->addHour()->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ], $this->writeHeaders('delegation-lifecycle-create', $csrf))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');
        $id = (string) $created->json('data.id');
        $this->assertDatabaseHas('delegations', ['id' => $id, 'status' => 'pending']);
        $this->assertSame([], $this->app->make(\Modules\Authorization\Infrastructure\Persistence\ListEffectiveCapabilitiesForUser::class)->forUser($delegate));

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations/'.$id.'/activate', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'delegation-lifecycle-activate',
            'X-CSRF-Token' => $csrf,
        ])->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('delegations', ['id' => $id, 'status' => 'active', 'lock_version' => 2]);
        $this->assertSame(['work_record.read'], $this->app->make(\Modules\Authorization\Infrastructure\Persistence\ListEffectiveCapabilitiesForUser::class)->forUser($delegate));

        $revokeHeaders = [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"2"',
            'Idempotency-Key' => 'delegation-lifecycle-revoke',
            'X-CSRF-Token' => $csrf,
        ];
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations/'.$id.'/revoke', [], $revokeHeaders)
            ->assertOk()->assertJsonPath('data.status', 'revoked');
        $revokedEndAt = DB::table('delegations')->where('id', $id)->value('end_at');
        $this->assertNotNull($revokedEndAt);
        $this->assertDatabaseHas('delegations', ['id' => $id, 'status' => 'revoked', 'lock_version' => 3]);
        $this->assertSame([], $this->app->make(\Modules\Authorization\Infrastructure\Persistence\ListEffectiveCapabilitiesForUser::class)->forUser($delegate));

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations/'.$id.'/revoke', [], $revokeHeaders)
            ->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertDatabaseHas('delegations', [
            'id' => $id,
            'status' => 'revoked',
            'end_at' => $revokedEndAt,
            'lock_version' => 3,
        ]);
        $this->assertSame([], $this->app->make(\Modules\Authorization\Infrastructure\Persistence\ListEffectiveCapabilitiesForUser::class)->forUser($delegate));

        $expiredId = '018f6f7d-0c00-7000-8000-00000000dd05';
        DB::table('delegations')->insert([
            'id' => $expiredId,
            'delegator_user_id' => self::ADMIN_ID,
            'delegate_user_id' => $expiredDelegate,
            'module_code' => 'work_record',
            'scope_type' => 'unit',
            'scope_id' => self::UNIT_B,
            'start_at' => now()->subMinute(),
            'end_at' => now()->addHour(),
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('delegation_capabilities')->insert([
            'delegation_id' => $expiredId,
            'capability_code' => 'work_record.read',
        ]);
        $this->assertSame(['work_record.read'], $this->app->make(\Modules\Authorization\Infrastructure\Persistence\ListEffectiveCapabilitiesForUser::class)->forUser($expiredDelegate));

        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/delegations/'.$expiredId.'/expire', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'delegation-lifecycle-expire',
            'X-CSRF-Token' => $csrf,
        ])->assertOk()->assertJsonPath('data.status', 'expired');
        $this->assertDatabaseHas('delegations', [
            'id' => $expiredId,
            'status' => 'expired',
            'lock_version' => 2,
        ]);
        $this->assertSame([], $this->app->make(\Modules\Authorization\Infrastructure\Persistence\ListEffectiveCapabilitiesForUser::class)->forUser($expiredDelegate));
    }

    public function test_admin_rows_are_scoped_before_pagination_and_direct_mutations(): void
    {
        [$cookie, $csrf] = $this->loginFacilityAdminSession();
        $foreignRole = DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::ROLE_CODE)->value('id');
        $foreignAssignment = DB::table('role_assignments')
            ->where('user_id', DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_ID)
            ->where('role_id', $foreignRole)->value('id');

        $list = $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/role-assignments?limit=1', [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertOk();
        $this->assertSame(1, count($list->json('items')));
        $this->assertNotSame($foreignAssignment, $list->json('items.0.id'));
        $this->withIdentitySession($cookie)->getJson('/api/v1/authorization/role-assignments/'.$foreignAssignment, [
            'X-Correlation-ID' => self::CORRELATION_ID,
        ])->assertNotFound();
        $this->withIdentitySession($cookie)->patchJson('/api/v1/authorization/role-assignments/'.$foreignAssignment, ['status' => 'revoked'], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Content-Type' => 'application/merge-patch+json',
            'X-CSRF-Token' => $csrf,
        ])->assertForbidden()->assertJsonPath('type', 'https://cluster.example/problems/access-denied');
        $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/role-assignments/'.$foreignAssignment.'/revoke', [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"1"',
            'Idempotency-Key' => 'foreign-assignment-revoke',
            'X-CSRF-Token' => $csrf,
        ])->assertForbidden()->assertJsonPath('type', 'https://cluster.example/problems/access-denied');
        $this->assertDatabaseHas('role_assignments', ['id' => $foreignAssignment, 'status' => 'active']);
    }

    public function test_facility_actor_cannot_grant_cluster_authority_and_etags_are_enforced(): void
    {
        [$adminCookie, $adminCsrf] = $this->loginAdminSession();
        [$cookie, $csrf] = $this->loginFacilityAdminSession();
        $this->seedOrgTree();
        $roleId = $this->createRole($adminCookie, $adminCsrf, 'contained_assignment');
        $this->attach($adminCookie, $adminCsrf, $roleId, 'work_record.read');

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

    private function createRole(string $cookie, string $csrf, string $code, string $actorId = self::ADMIN_ID, string $scopeType = 'cluster', ?string $scopeId = null, bool $assignVisible = true): string
    {
        $roleId = (string) $this->withIdentitySession($cookie)->postJson('/api/v1/authorization/roles', [
            'resource_type' => 'role',
            'code' => $code,
            'name' => 'دور '.$code,
            'role_type' => 'operational',
        ], $this->writeHeaders('role-'.$code, $csrf))->assertCreated()->json('data.id');
        if ($assignVisible) {
            $this->assignRole(
                $actorId,
                $roleId,
                $scopeType,
                $scopeId ?? (string) DB::table('clusters')->where('singleton_key', 1)->value('id'),
                null,
                '2026-01-01 00:00:00.000',
            );
        }
        return $roleId;
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

    private function seedOrganizationTree(): void
    {
        $this->assertDatabaseHas('clusters', ['id' => self::CLUSTER, 'status' => 'active']);
        $this->assertDatabaseHas('facilities', ['id' => self::FACILITY, 'cluster_id' => self::CLUSTER]);
        $this->assertDatabaseHas('organization_units', [
            'cluster_id' => self::CLUSTER,
            'parent_id' => self::FACILITY,
            'parent_type' => 'facility',
            'status' => 'active',
        ]);
    }

    private function seedFacilityAdmin(): void
    {
        $now = now();
        if (! DB::table('users')->where('id', self::FACILITY_ADMIN_ID)->exists()) {
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
        }
        $authorizationRoleId = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::AUTHORIZATION_ROLE_CODE)->value('id');
        $exists = DB::table('role_assignments')
            ->where('user_id', self::FACILITY_ADMIN_ID)
            ->where('role_id', $authorizationRoleId)
            ->where('scope_type', 'facility')
            ->where('scope_id', self::FACILITY)
            ->where('status', 'active')
            ->exists();
        if (! $exists) {
            DB::table('role_assignments')->insert([
                'id' => Str::uuid7()->toString(),
                'user_id' => self::FACILITY_ADMIN_ID,
                'role_id' => $authorizationRoleId,
                'scope_type' => 'facility',
                'scope_id' => self::FACILITY,
                'start_at' => '2026-01-01 00:00:00.000',
                'end_at' => null,
                'status' => 'active',
                'granted_by_user_id' => self::ADMIN_ID,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function loginFacilityAdminSession(): array
    {
        return $this->loginSession(self::FACILITY_ADMIN_USERNAME, self::FACILITY_ADMIN_PASSWORD);
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
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'W1.2 E2E test browser']);
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
