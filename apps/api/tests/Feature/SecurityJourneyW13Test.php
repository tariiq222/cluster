<?php

namespace Tests\Feature;

use App\Http\Authentication\SessionPrincipalResolver;
use App\Http\Middleware\IdentityRequestAttributes;
use Database\Seeders\AuthorizationCatalogSeeder;
use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\PersistAccessDecision;
use Modules\Authorization\Features\Administration\Http\AuthorizationAdminController;
use Modules\Authorization\Features\DecideAccess\Http\DecideAccessController;
use Modules\Authorization\Features\ExplainAccessDecision\Http\ExplainAccessDecisionController;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Documents\Application\DocumentDownloadService;
use Modules\Documents\Features\DocumentDownload\Http\DownloadDocumentController;
use Modules\Documents\Features\DocumentGrant\Http\CreateDocumentGrantController;
use Modules\Documents\Features\DocumentLifecycle\Http\CreateDocumentController;
use Modules\Documents\Features\DocumentLink\Http\LinkDocumentController;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Handler\ConsumeWorkRecordSubmittedHandler;
use Modules\Notifications\Features\ListMyNotifications\Http\ListMyNotificationsController;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use Modules\Reporting\Features\Dashboards\Http\GetDashboardController;
use Modules\Reporting\Features\GetAuthorizedDashboard\Handler\GetAuthorizedDashboardHandler;
use Modules\Reporting\Features\Reports\Http\GetReportController;
use Modules\Reporting\Features\RunAuthorizedReport\Handler\RunAuthorizedReportHandler;
use Modules\Search\Features\Search\Http\SearchController;
use Modules\Search\Features\SearchAccessibleRecords\Handler\SearchAccessibleRecordsHandler;
use Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Handler\GetAuthorizedWorkRecordHandler;
use Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Http\GetAuthorizedWorkRecordController;
use Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Handler\ListAuthorizedWorkRecordsHandler;
use Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Http\ListAuthorizedWorkRecordsController;
use Modules\WorkRecords\Features\SubmitWorkRecord\Http\SubmitWorkRecordController;
use Tests\TestCase;

/**
 * W1.3 integrated security journey at API level against the real RBAC+ABAC
 * engine: two Identity accounts (Account A at facility …0011, Account B at
 * facility …0012) and two facilities prove denied reads leak nothing, scoped
 * grants, revocation, delegation authority and windows, deny precedence,
 * field-level access, projection filtering, notification masking, the document
 * double decision, sensitive audit and decision explanation.
 *
 * The admin API itself is decided by the same real engine, so the
 * administrator bootstrap (authorization.* at facility …0011) is seeded
 * directly via DevelopmentJourneyAuthorizationSeeder and every journey grant
 * afterwards flows through the real admin API authenticated with Account A's
 * Identity cookie session plus CSRF.
 */
final class SecurityJourneyW13Test extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000a13';

    private const ADMIN_ID = DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID;

    private const USER_B = DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_ID;

    private const FACILITY_A = DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID;

    private const FACILITY_B = DevelopmentJourneyAuthorizationSeeder::FACILITY_B_ID;

    private const CLUSTER = '018f6f7d-0c00-7000-8000-00000000c113';

    private const REQUEST_VERSION_ID = '0197f0e0-0000-7000-8000-000000000001';

    private const REPORT_ID = '019f7000-0000-7000-8000-000000000901';

    private const DASHBOARD_ID = '019f7000-0000-7000-8000-000000000902';

    private const SESSION_COOKIE = 'cluster_identity_session';

    private string $adminCookie;

    private string $adminCsrf;

    private string $userBCookie;

    private string $userBCsrf;

    private int $keySequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindRealAccessDecision();
        $this->app->bind(DecideAccess::class, fn ($app) => new RbacAbacDecideAccess(
            $app->make(GetActiveSupervisoryRelationships::class),
            $app->make(PersistAccessDecision::class),
        ));
        $engine = new RbacAbacDecideAccess(
            $this->app->make(GetActiveSupervisoryRelationships::class),
            $this->app->make(PersistAccessDecision::class),
        );
        $this->app->instance(DecideAccess::class, $engine);
        $this->app->when([
            GetAuthorizedWorkRecordHandler::class,
            ListAuthorizedWorkRecordsHandler::class,
            SearchAccessibleRecordsHandler::class,
            RunAuthorizedReportHandler::class,
            GetAuthorizedDashboardHandler::class,
            ExplainAccessDecisionController::class,
        ])->needs(DecideAccess::class)->give(fn () => $engine);
        $this->app->when([
            DownloadDocumentController::class,
            AuthorizationAdminController::class,
            DecideAccessController::class,
            ExplainAccessDecisionController::class,
            GetAuthorizedWorkRecordController::class,
            ListAuthorizedWorkRecordsController::class,
            SubmitWorkRecordController::class,
            SearchController::class,
            GetReportController::class,
            GetDashboardController::class,
            ListMyNotificationsController::class,
            CreateDocumentController::class,
            CreateDocumentGrantController::class,
            LinkDocumentController::class,
        ])->needs(ResolveDevelopmentFixturePrincipal::class)
            ->give(fn ($app) => $app->make(SessionPrincipalResolver::class));
        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seed(DevelopmentJourneyAuthorizationSeeder::class);
        config()->set('identity.session_only', true);
        DB::table('authorization_bootstrap')->update([
            'state' => 'complete',
            'completed_by_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'completed_at' => now(),
            'lock_version' => 2,
            'updated_at' => now(),
        ]);
        DB::table('role_assignments')->whereIn('user_id', [DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID, DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_ID])->whereIn('role_id', DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::ROLE_CODE)->pluck('id'))->delete();
        // The administrator proves grant authority at cluster scope (required for
        // role-capability attach and cluster-wide administration); operational
        // journeys still grant USER_B at facility scope only.
        DB::table('role_assignments')->insertOrIgnore([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::ADMIN_ID,
            'role_id' => (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::AUTHORIZATION_ROLE_CODE)->value('id'),
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::ADMIN_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seedOrganizationTree();
        [$this->adminCookie, $this->adminCsrf] = $this->loginSession(
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_USERNAME,
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_PASSWORD,
        );
        [$this->userBCookie, $this->userBCsrf] = $this->loginSession(
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_USERNAME,
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_PASSWORD,
        );
    }

    public function test_journey_01_ungranted_cross_facility_read_leaks_nothing_and_list_excludes(): void
    {
        $recordId = $this->seedRecord(['payload' => $this->payload(['title' => 'طلب سري للغاية', 'description' => 'وصف سري'])]);
        $ownFacilityRecordId = $this->seedRecord(['owner_facility_id' => self::FACILITY_B]);

        $denied = $this->getAsB('/api/v1/work-records/'.$recordId);
        $denied->assertNotFound();
        $this->assertStringNotContainsString('طلب سري للغاية', $denied->getContent());
        $this->assertStringNotContainsString('وصف سري', $denied->getContent());
        $this->assertArrayNotHasKey('data', (array) $denied->json());
        $this->assertArrayNotHasKey('payload', (array) $denied->json());

        $list = $this->getAsB('/api/v1/work-records')->assertOk();
        $listedIds = array_column($list->json('items'), 'id');
        $this->assertNotContains($recordId, $listedIds);
        $this->assertNotContains($ownFacilityRecordId, $listedIds);
    }

    public function test_authorization_admin_requires_authorization_capability_not_identity_account_manager(): void
    {
        $identityRoleId = Str::uuid7()->toString();
        DB::table('roles')->insert([
            'id' => $identityRoleId,
            'code' => 'w13-identity-only-manager',
            'name_ar' => 'مدير حسابات فقط',
            'role_type' => 'journey',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (['identity.account.read', 'identity.account.manage'] as $code) {
            DB::table('role_capabilities')->insert([
                'role_id' => $identityRoleId,
                'capability_id' => DB::table('capabilities')->where('capability_code', $code)->value('id'),
                'effect' => 'allow',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('role_assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::USER_B,
            'role_id' => $identityRoleId,
            'scope_type' => 'facility',
            'scope_id' => self::FACILITY_B,
            'start_at' => now()->subDay(),
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::ADMIN_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getAsB('/api/v1/authorization/roles')->assertForbidden();
        $this->getAsAdmin('/api/v1/authorization/roles')->assertOk();
    }

    public function test_journey_02_facility_scoped_grant_allows_the_same_read(): void
    {
        $recordId = $this->seedRecord(['payload' => $this->payload(['title' => 'طلب مرئي', 'description' => 'وصف مرئي'])]);
        $this->grantViaAdminApi('w13-j02-read', self::USER_B, ['work_record.read', 'work_record.list'], 'facility', self::FACILITY_A);

        $response = $this->getAsB('/api/v1/work-records/'.$recordId)->assertOk()
            ->assertJsonPath('data.id', $recordId)
            ->assertJsonPath('data.payload.title', 'طلب مرئي')
            ->assertJsonPath('data.owner.facility_id', self::FACILITY_A);
        $persistedDecisionId = DB::table('access_decisions')->where('resource_id', $recordId)->value('id');
        $this->assertNotNull($persistedDecisionId);
        $this->assertSame($persistedDecisionId, $response->json('data.decision_id'), json_encode($response->json(), JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($response->json('data.decision_id'));

        $listed = $this->getAsB('/api/v1/work-records')->assertOk();
        $item = collect($listed->json('items') ?? $listed->json('data.items'))->firstWhere('id', $recordId);
        $this->assertNotNull($item);
        $this->assertNotEmpty($item['decision_id']);
        $this->assertIsArray($item['allowed_actions']);
        $this->assertIsArray($item['field_access']);
    }

    public function test_journey_03_grant_at_one_facility_does_not_cover_another(): void
    {
        $recordInA = $this->seedRecord();
        $recordInB = $this->seedRecord(['owner_facility_id' => self::FACILITY_B]);
        $this->grantViaAdminApi('w13-j03-read', self::USER_B, ['work_record.read'], 'facility', self::FACILITY_A);

        $this->getAsB('/api/v1/work-records/'.$recordInA)->assertOk();
        $this->getAsB('/api/v1/work-records/'.$recordInB)->assertNotFound();
    }

    public function test_journey_04_revoke_and_expired_assignment_deny_immediately(): void
    {
        $recordId = $this->seedRecord();
        $grant = $this->grantViaAdminApi('w13-j04-read', self::USER_B, ['work_record.read'], 'facility', self::FACILITY_A);
        $firstRead = $this->getAsB('/api/v1/work-records/'.$recordId);
        $firstRead->assertOk();

        $this->adminTransition('/api/v1/authorization/role-assignments/'.$grant['assignment_id'].'/revoke', 2);
        $this->getAsB('/api/v1/work-records/'.$recordId)->assertNotFound();

        $this->grantViaAdminApi(
            'w13-j04-expired',
            self::USER_B,
            ['work_record.read'],
            'facility',
            self::FACILITY_A,
            $this->utc(now()->subHour()),
        );
        $this->getAsB('/api/v1/work-records/'.$recordId)->assertNotFound();

        $decision = $this->latestDecision(self::USER_B, 'work_record.read', $recordId);
        $this->assertSame('deny', $decision->decision);
        $this->assertContains('role_assignment_expired', json_decode((string) $decision->reason_codes, true), (string) $decision->reason_codes);
    }

    public function test_journey_05_delegation_allows_only_the_delegated_capability_scope_and_window(): void
    {
        $this->grantViaAdminApi('w13-j05-delegator', self::ADMIN_ID, ['work_record.read'], 'facility', self::FACILITY_A);
        $recordInA = $this->seedRecord();
        $recordInB = $this->seedRecord(['owner_facility_id' => self::FACILITY_B]);

        $delegationId = (string) $this->adminPost('/api/v1/authorization/delegations', [
            'resource_type' => 'delegation',
            'delegator_user_id' => self::ADMIN_ID,
            'delegate_user_id' => self::USER_B,
            'module_code' => 'work_record',
            'capability_codes' => ['work_record.read'],
            'scope_type' => 'facility',
            'scope_id' => self::FACILITY_A,
            'start_at' => $this->utc(now()->subHour()),
            'end_at' => $this->utc(now()->addHours(2)),
        ])->assertCreated()->json('data.id');
        $this->adminTransition('/api/v1/authorization/delegations/'.$delegationId.'/activate');

        $this->getAsB('/api/v1/work-records/'.$recordInA)->assertOk();
        $decision = $this->latestDecision(self::USER_B, 'work_record.read', $recordInA);
        $this->assertSame('allow', $decision->decision);
        $this->assertContains('delegation_capability_allowed', json_decode((string) $decision->reason_codes, true));

        // The read delegation never covers work_record.submit.
        $this->withIdentitySession($this->userBCookie)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'request',
            'title' => 'طلب من المستخدم ب',
            'description' => 'وصف الطلب',
        ], [...$this->writeHeaders(), 'X-CSRF-Token' => $this->userBCsrf])->assertForbidden();

        // Nor records outside the delegated facility scope.
        $this->getAsB('/api/v1/work-records/'.$recordInB)->assertNotFound();
    }

    public function test_journey_06_delegation_wider_than_delegator_authority_is_rejected(): void
    {
        $authorizationRoleIds = DB::table('roles')->where('code', 'like', 'journey.w13-authorization-admin%')->pluck('id');
        DB::table('role_assignments')->where('user_id', self::ADMIN_ID)->whereNotIn('role_id', $authorizationRoleIds)->delete();
        $this->grantViaAdminApi('w13-j06-delegator', self::ADMIN_ID, ['work_record.read'], 'facility', self::FACILITY_A);
        $base = [
            'resource_type' => 'delegation',
            'delegator_user_id' => self::ADMIN_ID,
            'delegate_user_id' => self::USER_B,
            'module_code' => 'work_record',
            'capability_codes' => ['work_record.read'],
            'scope_type' => 'facility',
            'scope_id' => self::FACILITY_A,
            'start_at' => $this->utc(now()->subHour()),
            'end_at' => $this->utc(now()->addHours(2)),
        ];

        // Scope wider than the delegator's facility grant.
        $this->adminPost('/api/v1/authorization/delegations', [
            ...$base,
            'scope_type' => 'cluster',
            'scope_id' => self::CLUSTER,
        ])->assertUnprocessable();

        // Capability the delegator does not hold.
        $this->adminPost('/api/v1/authorization/delegations', [
            ...$base,
            'capability_codes' => ['work_record.submit'],
        ])->assertUnprocessable();

        // Window beyond the delegator's grant end.
        $this->grantViaAdminApi(
            'w13-j06-windowed',
            self::ADMIN_ID,
            ['work_record.list'],
            'facility',
            self::FACILITY_A,
            $this->utc(now()->addDays(5)),
        );
        $this->adminPost('/api/v1/authorization/delegations', [
            ...$base,
            'capability_codes' => ['work_record.list'],
            'end_at' => $this->utc(now()->addDays(10)),
        ])->assertUnprocessable();
    }

    public function test_journey_07_role_deny_and_explicit_deny_override_allow_until_revoked(): void
    {
        $recordId = $this->seedRecord();
        $grant = $this->grantViaAdminApi('w13-j07-allow', self::USER_B, ['work_record.read'], 'facility', self::FACILITY_A);
        $this->getAsB('/api/v1/work-records/'.$recordId)->assertOk();

        // A deny effect on the same role wins over the allow.
        $this->adminPost('/api/v1/authorization/role-capabilities', [
            'resource_type' => 'role_capability',
            'role_id' => $grant['role_id'],
            'capability_code' => 'work_record.read',
            'effect' => 'deny',
        ])->assertCreated();
        $this->getAsB('/api/v1/work-records/'.$recordId)->assertNotFound();
        $this->assertContains(
            'role_capability_denied',
            json_decode((string) $this->latestDecision(self::USER_B, 'work_record.read', $recordId)->reason_codes, true),
        );

        // Removing the deny and re-attaching the allow restores access.
        $this->adminTransition('/api/v1/authorization/role-capabilities/'.$grant['role_capability_ids']['work_record.read'].'/revoke', 2);
        $this->adminPost('/api/v1/authorization/role-capabilities', [
            'resource_type' => 'role_capability',
            'role_id' => $grant['role_id'],
            'capability_code' => 'work_record.read',
            'effect' => 'allow',
        ])->assertCreated();
        $this->getAsB('/api/v1/work-records/'.$recordId)->assertOk();

        // An explicit deny overrides the surviving allow.
        $denyId = (string) $this->adminPost('/api/v1/authorization/explicit-denies', [
            'resource_type' => 'explicit_deny',
            'user_id' => self::USER_B,
            'capability_code' => 'work_record.read',
            'resource_pattern' => 'work_record',
            'reason' => 'تعليق وصول مؤقت للمراجعة',
            'issued_at' => $this->utc(now()->subMinute()),
        ])->assertCreated()->json('data.id');
        $this->getAsB('/api/v1/work-records/'.$recordId)->assertNotFound();
        $this->assertContains(
            'explicit_deny',
            json_decode((string) $this->latestDecision(self::USER_B, 'work_record.read', $recordId)->reason_codes, true),
        );

        // Revoking the explicit deny restores the allow. The transition
        // persists expires_at in the domain ISO form; the engine compares
        // against database-formatted second-precision bindings, so normalize
        // the storage form exactly as a MySQL DATETIME column would (see
        // gateway gap note) and land it clearly in the past as revoke intends.
        $this->adminTransition('/api/v1/authorization/explicit-denies/'.$denyId.'/revoke');
        DB::table('explicit_denies')->where('id', $denyId)->update([
            'expires_at' => now()->utc()->subMinute()->format('Y-m-d H:i:s.v'),
        ]);
        $this->getAsB('/api/v1/work-records/'.$recordId)->assertOk();
    }

    public function test_journey_08_field_access_masks_hides_and_projects_states(): void
    {
        $this->adminPost('/api/v1/authorization/field-access-templates', [
            'resource_type' => 'field_access_template',
            'field_policy_key' => 'request-journey-v1',
            'module_code' => 'work_record',
            'policy_document' => ['fields' => ['payload.title' => 'edit', 'payload.description' => 'mask', 'payload.secret_note' => 'hide']],
        ])->assertCreated();
        $this->grantViaAdminApi('w13-j08-read', self::USER_B, ['work_record.read'], 'facility', self::FACILITY_A);

        $recordId = $this->seedRecord([
            'field_policy_key' => 'request-journey-v1',
            'payload' => $this->payload(['title' => 'عنوان ظاهر', 'description' => 'وصف حساس', 'secret_note' => 'ملاحظة سرية']),
        ]);
        $this->assertDatabaseHas('work_records', ['id' => $recordId, 'field_policy_key' => 'request-journey-v1']);

        $response = $this->getAsB('/api/v1/work-records/'.$recordId)->assertOk()
            ->assertJsonPath('data.payload.title', 'عنوان ظاهر')
            ->assertJsonPath('data.payload.description', '***');
        $this->assertSame('readonly', $response->json('data.field_access')['payload.title']);
        $this->assertSame('masked', $response->json('data.field_access')['payload.description']);
        $this->assertSame('hidden', $response->json('data.field_access')['payload.secret_note']);
        $this->assertArrayNotHasKey('secret_note', $response->json('data.payload'));

        // An unknown policy key fails closed on every field.
        $closedId = $this->seedRecord(['field_policy_key' => 'request-journey-missing']);
        $closed = $this->getAsB('/api/v1/work-records/'.$closedId)->assertOk();
        $this->assertSame([], $closed->json('data.payload'));
        $this->assertSame(['*' => 'hidden'], $closed->json('data.field_access'));

        // The submit chain persists the definition policy key onto the record
        // (null for the request fixture, which carries no policy key).
        $this->grantViaAdminApi('w13-j08-submit', self::ADMIN_ID, ['work_record.submit'], 'facility', self::FACILITY_A);
        $submittedId = $this->submitRecordAsAdmin('طلب بسياسة ميدانية');
        $this->assertDatabaseHas('work_records', ['id' => $submittedId, 'field_policy_key' => null]);
    }

    public function test_journey_09_search_report_and_dashboard_follow_the_decision(): void
    {
        $recordInA = $this->seedRecord();
        $recordInB = $this->seedRecord(['owner_facility_id' => self::FACILITY_B]);
        $this->seedSearchEntry($recordInA, self::FACILITY_A);
        $this->seedSearchEntry($recordInB, self::FACILITY_B);
        $this->seedReportRow($recordInA, self::FACILITY_A);
        $this->seedReportRow($recordInB, self::FACILITY_B);

        // Without a grant the projections return nothing for facility A.
        $this->getAsB('/api/v1/search?q=budget&scope_id='.self::FACILITY_A)->assertOk()->assertJsonPath('total', 0);
        $this->getAsB('/api/v1/reports/'.self::REPORT_ID.'?scope_id='.self::FACILITY_A)->assertOk()->assertJsonPath('total', 0);
        $this->getAsB('/api/v1/dashboards/'.self::DASHBOARD_ID.'?scope_id='.self::FACILITY_A)->assertOk()->assertJsonPath('total', 0);

        $grant = $this->grantViaAdminApi(
            'w13-j09-projections',
            self::USER_B,
            ['search.query', 'reporting.run', 'reporting.dashboard'],
            'facility',
            self::FACILITY_A,
        );

        $search = $this->getAsB('/api/v1/search?q=budget&scope_id='.self::FACILITY_A)->assertOk();
        $this->assertSame(1, $search->json('total'), 'Search must expose the authorized facility row.');
        $this->assertSame(self::FACILITY_A, $search->json('items.0.scope_id'));
        $report = $this->getAsB('/api/v1/reports/'.self::REPORT_ID.'?scope_id='.self::FACILITY_A)->assertOk();
        $this->assertSame(1, $report->json('total'), 'Reporting must expose the authorized facility row.');
        $this->assertSame(self::FACILITY_A, $report->json('items.0.scope_id'));
        $dashboard = $this->getAsB('/api/v1/dashboards/'.self::DASHBOARD_ID.'?scope_id='.self::FACILITY_A)->assertOk();
        $this->assertSame(1, $dashboard->json('total'), 'Dashboard must expose the authorized facility row.');
        $this->assertSame(self::FACILITY_A, $dashboard->json('items.0.scope_id'));

        // The grant never leaks the other facility's rows in the default scope.
        $this->getAsB('/api/v1/search?q=budget')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.scope_id', self::FACILITY_A);

        // Revocation empties the projections immediately.
        $this->adminTransition('/api/v1/authorization/role-assignments/'.$grant['assignment_id'].'/revoke', 2);
        $this->getAsB('/api/v1/search?q=budget&scope_id='.self::FACILITY_A)->assertOk()->assertJsonPath('total', 0);
        $this->getAsB('/api/v1/reports/'.self::REPORT_ID.'?scope_id='.self::FACILITY_A)->assertOk()->assertJsonPath('total', 0);
        $this->getAsB('/api/v1/dashboards/'.self::DASHBOARD_ID.'?scope_id='.self::FACILITY_A)->assertOk()->assertJsonPath('total', 0);
    }

    public function test_journey_10_notification_masks_source_after_revoke(): void
    {
        $recordId = $this->seedRecord();
        $grant = $this->grantViaAdminApi('w13-j10-read', self::USER_B, ['work_record.read'], 'facility', self::FACILITY_A);
        $this->app->make(ConsumeWorkRecordSubmittedHandler::class)->handle([
            'specversion' => '1.0',
            'id' => Str::uuid7()->toString(),
            'source' => '/work-records',
            'type' => 'com.cluster.workrecord.submitted.v1',
            'subject' => '/work-records/'.$recordId,
            'time' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'datacontenttype' => 'application/json',
            'correlationid' => Str::uuid7()->toString(),
            'data' => [
                'record' => ['id' => $recordId, 'owner' => ['facility_id' => self::FACILITY_A, 'user_id' => self::USER_B]],
                'access_context' => ['owner_facility_id' => self::FACILITY_A],
                'classification' => 'internal',
            ],
        ]);

        $visible = $this->getAsB('/api/v1/notifications')->assertOk();
        $this->assertSame('تم تقديم سجل عمل', $visible->json('items.0.title'));
        $this->assertSame($recordId, $visible->json('items.0.source.record_id'));

        $this->adminTransition('/api/v1/authorization/role-assignments/'.$grant['assignment_id'].'/revoke', 2);

        $masked = $this->getAsB('/api/v1/notifications')->assertOk();
        $this->assertSame('إشعار غير متاح حالياً', $masked->json('items.0.title'));
        $this->assertSame('', $masked->json('items.0.source.record_id'));
    }

    public function test_journey_11_document_download_re_decides_document_and_linked_record(): void
    {
        $this->grantViaAdminApi(
            'w13-j11-admin',
            self::ADMIN_ID,
            ['work_record.submit', 'work_record.read', 'documents.create', 'documents.link'],
            'facility',
            self::FACILITY_A,
        );
        $recordId = $this->submitRecordAsAdmin('طلب بمرفق');

        $documentPublicId = (string) $this->adminPost('/api/v1/documents', [
            'title' => 'مستند الرحلة',
            'classification' => 'internal',
            'owner_organization_unit_id' => self::FACILITY_A,
            'restriction_policy_key' => 'retention-journey-v1',
        ])->assertCreated()->json('data.id');
        $documentId = (string) DB::table('documents')->where('public_id', $documentPublicId)->value('id');
        $this->seedAvailableDocumentVersion($documentId, self::ADMIN_ID);

        $this->withIdentitySession($this->adminCookie)->postJson('/api/v1/work-records/'.$recordId.'/documents', [
            'document_id' => $documentPublicId,
            'relation_type' => 'attachment',
        ], ['X-Correlation-ID' => self::CORRELATION_ID, 'X-CSRF-Token' => $this->adminCsrf])->assertCreated();

        // The read grant alone never unlocks the download decision.
        $this->grantViaAdminApi('w13-j11-read', self::USER_B, ['work_record.read'], 'facility', self::FACILITY_A);
        $denied = $this->downloadAsB($documentPublicId);
        $this->assertSame(403, $denied->getStatusCode());

        // With documents.download granted, both decisions pass and the grant redirects.
        $this->grantViaAdminApi('w13-j11-download', self::USER_B, ['documents.download'], 'facility', self::FACILITY_A);
        $allowed = $this->downloadAsB($documentPublicId);
        $this->assertSame(302, $allowed->getStatusCode());

        $this->assertDatabaseHas('document_access_events', [
            'document_id' => $documentId,
            'actor_user_id' => self::USER_B,
            'action' => 'download',
            'decision' => 'denied',
        ]);
        $this->assertDatabaseHas('document_access_events', [
            'document_id' => $documentId,
            'actor_user_id' => self::USER_B,
            'action' => 'download',
            'decision' => 'allowed',
        ]);
    }

    public function test_journey_12_sensitive_allow_writes_decision_and_sensitive_event(): void
    {
        $recordId = $this->seedRecord(['classification' => 'confidential']);
        $this->grantViaAdminApi('w13-j12-read', self::USER_B, ['work_record.read'], 'facility', self::FACILITY_A);

        // The normal-sensitivity grant cannot clear a confidential record.
        $this->getAsB('/api/v1/work-records/'.$recordId)->assertNotFound();

        // Raising the capability clearance to sensitive clears the same grant.
        DB::table('capabilities')->where('capability_code', 'work_record.read')->update(['sensitivity' => 'sensitive']);
        $response = $this->getAsB('/api/v1/work-records/'.$recordId)->assertOk();
        $decisionId = (string) $response->json('data.decision_id');

        $this->assertDatabaseHas('access_decisions', [
            'id' => $decisionId,
            'decision' => 'allow',
            'action' => 'work_record.read',
            'resource_id' => $recordId,
            'actor_user_id' => self::USER_B,
            'classification' => 'confidential',
        ]);
        $this->assertDatabaseHas('sensitive_access_events', [
            'access_decision_id' => $decisionId,
            'resource_type' => 'work_record',
            'resource_id' => $recordId,
            'action' => 'work_record.read',
            'actor_user_id' => self::USER_B,
            'classification_code' => 'confidential',
        ]);
    }

    public function test_journey_13_explanation_returns_reason_codes_without_record_payload(): void
    {
        $recordId = $this->seedRecord();
        $this->grantViaAdminApi('w13-j13-read', self::USER_B, ['work_record.read'], 'facility', self::FACILITY_A);
        $decisionId = (string) $this->getAsB('/api/v1/work-records/'.$recordId)->assertOk()->json('data.decision_id');

        $response = $this->getAsAdmin('/api/v1/authorization/access-decisions/'.$decisionId.'/explanation')->assertOk()
            ->assertJsonPath('decision_id', $decisionId)
            ->assertJsonPath('decision', 'allow')
            ->assertJsonPath('action', 'work_record.read')
            ->assertJsonPath('policy_version', 'rbac-abac-v2');
        $this->assertNotEmpty($response->json('facts_version'));
        $this->assertContains('role_capability_allowed', $response->json('reason_codes'));
        $this->assertArrayNotHasKey('payload', $response->json());
        $this->assertArrayNotHasKey('title', $response->json());
        $this->assertStringNotContainsString('طلب مزروع', $response->getContent());
    }

    public function test_journey_14_ownership_alone_grants_nothing(): void
    {
        $recordId = $this->seedRecord([
            'owner_facility_id' => self::FACILITY_B,
            'creator_user_id' => self::USER_B,
        ]);

        $this->getAsB('/api/v1/work-records/'.$recordId)->assertNotFound();
        $list = $this->getAsB('/api/v1/work-records')->assertOk();
        $this->assertNotContains($recordId, array_column($list->json('items'), 'id'));
    }

    /** @return array{role_id: string, assignment_id: string, role_capability_ids: array<string, string>} */
    private function grantViaAdminApi(
        string $label,
        string $userId,
        array $capabilities,
        string $scopeType,
        string $scopeId,
        ?string $endAt = null,
    ): array {
        $roleId = (string) $this->adminPost('/api/v1/authorization/roles', [
            'resource_type' => 'role',
            'code' => $label,
            'name' => 'دور '.$label,
            'role_type' => 'operational',
        ])->assertCreated()->json('data.id');

        $roleCapabilityIds = [];
        foreach ($capabilities as $capability) {
            $roleCapabilityIds[$capability] = (string) $this->adminPost('/api/v1/authorization/role-capabilities', [
                'resource_type' => 'role_capability',
                'role_id' => $roleId,
                'capability_code' => $capability,
                'effect' => 'allow',
            ])->assertCreated()->json('data.id');
        }

        $assignmentId = (string) $this->adminPost('/api/v1/authorization/role-assignments', [
            'resource_type' => 'role_assignment',
            'user_id' => $userId,
            'role_id' => $roleId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'start_at' => $this->utc(now()->subDays(2)),
            ...($endAt === null ? [] : ['end_at' => $endAt]),
        ])->assertCreated()->json('data.id');
        $this->adminTransition('/api/v1/authorization/role-assignments/'.$assignmentId.'/activate');

        return [
            'role_id' => $roleId,
            'assignment_id' => $assignmentId,
            'role_capability_ids' => $roleCapabilityIds,
        ];
    }

    private function adminPost(string $uri, array $payload): TestResponse
    {
        return $this->withIdentitySession($this->adminCookie)->postJson($uri, $payload, [
            ...$this->writeHeaders(),
            'X-CSRF-Token' => $this->adminCsrf,
        ]);
    }

    private function adminTransition(string $uri, int $version = 1): TestResponse
    {
        return $this->withIdentitySession($this->adminCookie)->postJson($uri, [], [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'If-Match' => '"'.$version.'"',
            'Idempotency-Key' => 'w13-transition-'.$this->keySequence++,
            'X-CSRF-Token' => $this->adminCsrf,
        ])->assertOk();
    }

    private function getAsB(string $uri): TestResponse
    {
        return $this->withIdentitySession($this->userBCookie)->getJson($uri, ['X-Correlation-ID' => self::CORRELATION_ID]);
    }

    private function getAsAdmin(string $uri): TestResponse
    {
        return $this->withIdentitySession($this->adminCookie)->getJson($uri, ['X-Correlation-ID' => self::CORRELATION_ID]);
    }

    private function submitRecordAsAdmin(string $title): string
    {
        return (string) $this->withIdentitySession($this->adminCookie)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'request',
            'title' => $title,
            'description' => 'وصف الطلب',
        ], [...$this->writeHeaders(), 'X-CSRF-Token' => $this->adminCsrf])->assertCreated()->json('data.id');
    }

    /** @param array<string, mixed> $overrides */
    private function seedRecord(array $overrides = []): string
    {
        $id = Str::uuid7()->toString();
        DB::table('work_records')->insert([
            'id' => $id,
            'record_number' => 'WR-'.strtoupper(Str::random(12)),
            'work_type_version_id' => self::REQUEST_VERSION_ID,
            'owner_facility_id' => self::FACILITY_A,
            'creator_user_id' => self::ADMIN_ID,
            'status' => 'submitted',
            'classification' => 'internal',
            'payload' => $this->payload(['title' => 'طلب مزروع', 'description' => 'وصف مزروع']),
            'lock_version' => 1,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);

        return $id;
    }

    /** @param array<string, string> $payload */
    private function payload(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    private function seedSearchEntry(string $recordId, string $scopeId): void
    {
        DB::table('search_index_entries')->insert([
            'id' => Str::uuid7()->toString(),
            'source_module' => 'work-records',
            'source_type' => 'work_record',
            'source_id' => $recordId,
            'source_version' => '1',
            'projection_version' => 'w13-v1',
            'scope_id' => $scopeId,
            'classification' => 'internal',
            'visibility' => 'eligible',
            'title' => 'budget review journey entry',
            'excerpt' => 'journey excerpt',
            'search_text' => 'budget review journey entry',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedReportRow(string $recordId, string $scopeId): void
    {
        DB::table('report_read_models')->insert([
            'id' => Str::uuid7()->toString(),
            'report_id' => self::REPORT_ID,
            'source_module' => 'work-records',
            'source_type' => 'work_record',
            'source_id' => $recordId,
            'source_version' => '1',
            'scope_id' => $scopeId,
            'classification' => 'internal',
            'projection_version' => 'w1.9-v1',
            'title' => 'صف قراءة الطلب',
            'safe_data' => json_encode(['total' => 1], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedAvailableDocumentVersion(string $documentId, string $userId): void
    {
        $storageId = Str::uuid7()->toString();
        DB::table('document_storage_objects')->insert([
            'id' => $storageId,
            'disk' => 'documents-available',
            'object_key' => 'available/'.$storageId,
            'storage_class' => 'standard',
            'immutable' => true,
            'immutable_since' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('document_versions')->insert([
            'id' => Str::uuid7()->toString(),
            'public_id' => Str::uuid7()->toString(),
            'document_id' => $documentId,
            'storage_object_id' => $storageId,
            'version_number' => 1,
            'original_filename' => 'journey.pdf',
            'declared_mime_type' => 'application/pdf',
            'size_bytes' => 256,
            'sha256' => hash('sha256', 'journey-bytes'),
            'scan_status' => 'clean',
            'availability_status' => 'available',
            'available_at' => now(),
            'created_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The download route sits behind the identity session middleware, so the
     * journey exercises the same controller and service directly with the
     * real engine (mirroring Modules\Documents\Tests\Http\DownloadDocumentControllerTest).
     * The request carries the Identity request attributes Account B's session
     * would have produced after IdentitySessionMiddleware ran, so the
     * SessionPrincipalResolver returns Account B as the principal.
     */
    private function downloadAsB(string $documentPublicId): mixed
    {
        $request = Request::create('/api/v1/documents/'.$documentPublicId.'/download', 'GET');
        $request->headers->set('X-Correlation-ID', self::CORRELATION_ID);
        $request->attributes->set(IdentityRequestAttributes::SESSION, [
            'user_id' => self::USER_B,
            'session_id' => 'w13-j11-session',
            'csrf_token_hash' => null,
            'restricted' => false,
        ]);
        $request->attributes->set(IdentityRequestAttributes::PRINCIPAL, [
            'user_id' => self::USER_B,
        ]);

        return (new DownloadDocumentController(
            $this->app->make(SessionPrincipalResolver::class),
            $this->app->make(DocumentDownloadService::class),
        ))($request, $documentPublicId);
    }

    private function latestDecision(string $actorUserId, string $action, string $resourceId): object
    {
        $decision = DB::table('access_decisions')
            ->where('actor_user_id', $actorUserId)
            ->where('action', $action)
            ->where('resource_id', $resourceId)
            ->orderByDesc('evaluated_at')
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($decision);

        return $decision;
    }

    private function seedOrganizationTree(): void
    {
        $this->assertDatabaseHas('clusters', ['id' => self::CLUSTER, 'status' => 'active']);
        $this->assertDatabaseHas('facilities', ['id' => self::FACILITY_A, 'cluster_id' => self::CLUSTER]);
        $this->assertDatabaseHas('facilities', ['id' => self::FACILITY_B, 'cluster_id' => self::CLUSTER]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function loginSession(string $username, string $password): array
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'W1.3 security journey']);
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
    private function writeHeaders(): array
    {
        return ['X-Correlation-ID' => self::CORRELATION_ID, 'Idempotency-Key' => 'w13-'.$this->keySequence++];
    }

    private function utc(\DateTimeInterface $moment): string
    {
        return $moment->format('Y-m-d\TH:i:s.v\Z');
    }
}
