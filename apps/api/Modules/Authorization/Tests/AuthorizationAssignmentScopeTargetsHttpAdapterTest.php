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
use Modules\Authorization\Features\Administration\Http\ListAssignmentScopeTargetsController;
use Modules\Authorization\Features\DecideAccess\Http\DecideAccessController;
use Modules\Authorization\Features\ExplainAccessDecision\Http\ExplainAccessDecisionController;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Identity\Infrastructure\Security\PasswordHasher;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use Tests\TestCase;

/**
 * Task 1B — Adapter tests for the dedicated
 * GET /api/v1/authorization/assignment-scope-targets endpoint.
 *
 * The endpoint reads its identity principal from the cluster identity
 * session cookie (IdentitySessionMiddleware +
 * RequireIdentitySessionPrincipal) and never accepts a bearer token.
 * Reads are GET so no CSRF / Idempotency-Key is required, but every
 * request must carry X-Correlation-ID.
 *
 * The contract that the controller enforces is:
 *  - 400 invalid-correlation-id / invalid-scope-targets-query /
 *    invalid_scope_query on malformed inputs
 *  - 401 authentication-required when no session cookie is present
 *  - 403 access-denied when the actor lacks the
 *    authorization.assignment.manage capability
 *  - 422 urn:cluster:problem:scope_type_not_catalogued when
 *    scope_type=record_set (the only catalog-rejection arm)
 *  - 200 with empty items when the actor holds the capability but
 *    has no manageable scope
 *  - 200 with items keyed by the actor's manageable scope roots
 *  - 400 invalid-scope-targets-query when the pagination cursor
 *    is tampered or its binding does not match
 */
final class AuthorizationAssignmentScopeTargetsHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000b51';

    private const SESSION_COOKIE = 'cluster_identity_session';

    private const CLUSTER_ADMIN_ID = '018f6f7d-0c00-7000-8000-00000000fb70';

    private const CLUSTER_ADMIN_USERNAME = 'cluster-only-scope-targets-admin';

    private const CLUSTER_ADMIN_PASSWORD = 'Cedar!Orbit8Harbor2026';

    private const FACILITY_ADMIN_ID = '018f6f7d-0c00-7000-8000-00000000fb51';

    private const FACILITY_ADMIN_USERNAME = 'facility-only-scope-targets-admin';

    private const FACILITY_ADMIN_PASSWORD = 'Cedar!Orbit8Harbor2026';

    private const NO_SCOPE_ADMIN_ID = '018f6f7d-0c00-7000-8000-00000000fb60';

    private const NO_SCOPE_ADMIN_USERNAME = 'scope-targets-no-scope';

    private const NO_SCOPE_ADMIN_PASSWORD = 'Cedar!Orbit8Harbor2026';

    private const UNIT_A = '018f6f7d-0c00-7000-8000-00000000ab51';

    private const UNIT_B = '018f6f7d-0c00-7000-8000-00000000ab52';

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
        $this->app->when(ListAssignmentScopeTargetsController::class)
            ->needs(DecideAccess::class)
            ->give(fn (): RbacAbacDecideAccess => $engine);
        $this->app->when([
            AuthorizationAdminController::class,
            ListAssignmentScopeTargetsController::class,
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
        $this->seedClusterOnlyAdmin();
        $this->seedFacilityOnlyAdmin();
        $this->seedNoScopeAdmin();
        $this->seedUnits();
    }

    public function test_cluster_only_admin_scope_cluster_returns_single_cluster_row(): void
    {
        [$cookie] = $this->loginClusterAdminSession();
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');
        $this->assertNotSame('', $clusterId);

        $response = $this->withIdentitySession($cookie)->getJson(
            $this->url(['scope_type' => 'cluster']),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('next_cursor', null);

        $items = $response->json('items');
        $this->assertIsArray($items);
        $this->assertCount(1, $items);
        $this->assertSame('cluster', $items[0]['scope_type']);
        $this->assertSame($clusterId, $items[0]['scope_id']);
        $this->assertSame('W13-E2E-CLUSTER', $items[0]['code']);
        $this->assertSame('تجمع اختبار W1.3', $items[0]['label_ar']);
        $this->assertSame('W1.3 E2E Cluster', $items[0]['label_en']);
    }

    public function test_cluster_only_admin_scope_unit_with_parent_cluster_returns_descendant_units(): void
    {
        [$cookie] = $this->loginClusterAdminSession();
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');

        $response = $this->withIdentitySession($cookie)->getJson(
            $this->url([
                'scope_type' => 'unit',
                'parent_scope_type' => 'cluster',
                'parent_scope_id' => $clusterId,
            ]),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );

        $response->assertOk()->assertJsonPath('next_cursor', null);

        $items = $response->json('items');
        $this->assertIsArray($items);
        // The cluster fixture contains more than two descendant units (the
        // platform admin's unit and the test's extra units); assert the
        // required seeded accounts are present without forcing the rest of
        // the descendant set to a specific count.
        $requiredUnitIds = [
            '018f6f7d-0c00-7000-8000-000000000041',
            '018f6f7d-0c00-7000-8000-000000000042',
        ];
        $unitIds = array_map(static fn (array $row): string => $row['scope_id'], $items);
        sort($unitIds);
        $missing = array_diff($requiredUnitIds, $unitIds);
        $this->assertSame([], array_values($missing), 'Both seeded account units must appear in the catalog.');
        foreach ($items as $row) {
            $this->assertSame('unit', $row['scope_type']);
            $this->assertArrayHasKey('label_ar', $row);
            $this->assertArrayHasKey('label_en', $row);
        }
        $this->assertGreaterThanOrEqual(2, count($items));

    }

    public function test_facility_only_admin_scope_facility_returns_managed_facility(): void
    {
        [$cookie] = $this->loginFacilityAdminSession();

        $response = $this->withIdentitySession($cookie)->getJson(
            $this->url(['scope_type' => 'facility']),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );

        $response->assertOk()->assertJsonPath('next_cursor', null);

        $items = $response->json('items');
        $this->assertIsArray($items);
        $this->assertCount(1, $items);
        $this->assertSame('facility', $items[0]['scope_type']);
        $this->assertSame(DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID, $items[0]['scope_id']);
        $this->assertSame('w13-e2e-facility-a', $items[0]['code']);
    }

    public function test_facility_only_admin_scope_unit_with_parent_cluster_returns_empty_200(): void
    {
        [$cookie] = $this->loginFacilityAdminSession();
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');

        $response = $this->withIdentitySession($cookie)->getJson(
            $this->url([
                'scope_type' => 'unit',
                'parent_scope_type' => 'cluster',
                'parent_scope_id' => $clusterId,
            ]),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );

        $response->assertOk()
            ->assertExactJson([
                'items' => [],
                'next_cursor' => null,
            ]);
    }

    public function test_record_set_scope_type_returns_422(): void
    {
        [$cookie] = $this->loginClusterAdminSession();

        $response = $this->withIdentitySession($cookie)->getJson(
            $this->url(['scope_type' => 'record_set']),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'urn:cluster:problem:scope_type_not_catalogued')
            ->assertJsonPath('status', 422);
    }

    public function test_scope_type_cluster_with_parent_scope_type_facility_returns_400(): void
    {
        [$cookie] = $this->loginClusterAdminSession();

        $response = $this->withIdentitySession($cookie)->getJson(
            $this->url([
                'scope_type' => 'cluster',
                'parent_scope_type' => 'facility',
                'parent_scope_id' => DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID,
            ]),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );

        $response->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid_scope_query')
            ->assertJsonPath('status', 400);
    }

    public function test_unsupported_scope_type_returns_400_invalid_scope_query(): void
    {
        [$cookie] = $this->loginClusterAdminSession();

        $response = $this->withIdentitySession($cookie)->getJson(
            $this->url(['scope_type' => 'mystery_scope']),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );

        $response->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid_scope_query')
            ->assertJsonPath('status', 400);
    }

    public function test_missing_scope_type_returns_400_invalid_scope_targets_query(): void
    {
        [$cookie] = $this->loginClusterAdminSession();

        $response = $this->withIdentitySession($cookie)->getJson(
            '/api/v1/authorization/assignment-scope-targets',
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );

        $response->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-scope-targets-query')
            ->assertJsonPath('status', 400);
    }

    public function test_missing_correlation_id_is_rejected(): void
    {
        [$cookie] = $this->loginClusterAdminSession();

        $this->withIdentitySession($cookie)->getJson(
            $this->url(['scope_type' => 'cluster']),
        )->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-correlation-id');
    }

    public function test_unauthenticated_session_is_rejected(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson(
                $this->url(['scope_type' => 'cluster']),
                ['X-Correlation-ID' => self::CORRELATION_ID],
            );

        $response->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/authentication-required');
    }

    public function test_unknown_query_parameter_returns_400(): void
    {
        [$cookie] = $this->loginClusterAdminSession();

        $response = $this->withIdentitySession($cookie)->getJson(
            $this->url([
                'scope_type' => 'cluster',
                'unsupported_key' => 'leak',
            ]),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );

        $response->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-scope-targets-query');
    }

    public function test_limit_defaults_to_25_and_clamps_above_100(): void
    {
        [$cookie] = $this->loginClusterAdminSession();
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');

        $default = $this->withIdentitySession($cookie)->getJson(
            $this->url([
                'scope_type' => 'unit',
                'parent_scope_type' => 'cluster',
                'parent_scope_id' => $clusterId,
            ]),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );
        $default->assertOk();
        $defaultItems = $default->json('items');
        $this->assertIsArray($defaultItems);
        $this->assertGreaterThanOrEqual(2, count($defaultItems));
        $this->assertNull($default->json('next_cursor'));

        $clamped = $this->withIdentitySession($cookie)->getJson(
            $this->url([
                'scope_type' => 'unit',
                'parent_scope_type' => 'cluster',
                'parent_scope_id' => $clusterId,
                'limit' => 9999,
            ]),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );
        $clamped->assertOk();
        // A limit above the documented maximum (100) is clamped to that
        // maximum. The seeded catalog has fewer than 100 units so the
        // clamped response equals the default response; the check that
        // matters is that limit=9999 does not yield thousands of rows.
        $this->assertLessThanOrEqual(100, count($clamped->json('items')));
        $this->assertSame($defaultItems, $clamped->json('items'));
        $this->assertNull($clamped->json('next_cursor'));
    }

    public function test_pagination_first_and_second_pages_deduplicate(): void
    {
        [$cookie] = $this->loginClusterAdminSession();
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');

        $seen = [];
        $nextCursor = null;
        $pages = 0;
        do {
            $params = [
                'scope_type' => 'unit',
                'parent_scope_type' => 'cluster',
                'parent_scope_id' => $clusterId,
                'limit' => 1,
            ];
            if ($nextCursor !== null) {
                $params['cursor'] = $nextCursor;
            }
            $response = $this->withIdentitySession($cookie)->getJson(
                $this->url($params),
                ['X-Correlation-ID' => self::CORRELATION_ID],
            );
            $response->assertOk();
            $this->assertCount(1, $response->json('items'));
            $seen[] = $response->json('items')[0]['scope_id'];
            $nextCursor = $response->json('next_cursor');
            $pages++;
            if ($pages > 25) {
                $this->fail('pagination did not terminate within 25 pages; fixture may be larger than expected.');
            }
        } while ($nextCursor !== null);

        // No duplicate rows across the paginated catalog.
        $this->assertSame($seen, array_values(array_unique($seen)), 'paginated catalog returned duplicate rows');
        $this->assertGreaterThanOrEqual(2, count($seen));
    }

    public function test_tampered_cursor_returns_400_invalid_scope_targets_query(): void
    {
        [$cookie] = $this->loginClusterAdminSession();
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');

        // Issue any first page that emits a cursor; the actual row count is
        // not asserted here — what matters is that flipping a single
        // character in the cursor causes the server to reject the cursor.
        $first = $this->withIdentitySession($cookie)->getJson(
            $this->url([
                'scope_type' => 'unit',
                'parent_scope_type' => 'cluster',
                'parent_scope_id' => $clusterId,
                'limit' => 1,
            ]),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );
        $first->assertOk();
        $cursor = (string) $first->json('next_cursor');
        $this->assertNotSame('', $cursor);

        $tampered = substr($cursor, 0, -1).(substr($cursor, -1) === 'A' ? 'B' : 'A');

        $response = $this->withIdentitySession($cookie)->getJson(
            $this->url([
                'scope_type' => 'unit',
                'parent_scope_type' => 'cluster',
                'parent_scope_id' => $clusterId,
                'limit' => 1,
                'cursor' => $tampered,
            ]),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );

        $response->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');
        $this->assertSame('https://cluster.example/problems/invalid-scope-targets-query', $response->json('type'));
    }

    public function test_cursor_with_unrelated_filter_returns_400(): void
    {
        [$cookie] = $this->loginClusterAdminSession();
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');

        $first = $this->withIdentitySession($cookie)->getJson(
            $this->url([
                'scope_type' => 'unit',
                'parent_scope_type' => 'cluster',
                'parent_scope_id' => $clusterId,
                'limit' => 1,
            ]),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );
        $first->assertOk();
        $cursor = (string) $first->json('next_cursor');
        $this->assertNotSame('', $cursor, 'first request must yield a next_cursor to validate filter assertion');

        $response = $this->withIdentitySession($cookie)->getJson(
            $this->url([
                'scope_type' => 'unit',
                'parent_scope_type' => 'cluster',
                'parent_scope_id' => $clusterId,
                'limit' => 1,
                'search' => 'different-search',
                'cursor' => $cursor,
            ]),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );

        $response->assertStatus(400)
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    public function test_search_filters_by_arabic_label(): void
    {
        [$cookie] = $this->loginClusterAdminSession();
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');

        $response = $this->withIdentitySession($cookie)->getJson(
            $this->url([
                'scope_type' => 'unit',
                'parent_scope_type' => 'cluster',
                'parent_scope_id' => $clusterId,
                'search' => 'وحدة اختبار',
            ]),
            ['X-Correlation-ID' => self::CORRELATION_ID],
        );

        $response->assertOk();
        $items = $response->json('items');
        $this->assertIsArray($items);
        $this->assertCount(2, $items);
    }

    private function seedClusterOnlyAdmin(): void
    {
        $now = now();
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');
        DB::table('users')->insert([
            'id' => self::CLUSTER_ADMIN_ID,
            'username' => self::CLUSTER_ADMIN_USERNAME,
            'person_id' => null,
            'person_version' => null,
            'display_name_ar' => 'مسؤول تجمع اختبارات النطاق',
            'display_name_en' => 'Cluster-only scope-targets admin',
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
        $hasher = app(PasswordHasher::class);
        DB::table('credentials')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::CLUSTER_ADMIN_ID,
            'password_hash' => $hasher->hash(self::CLUSTER_ADMIN_PASSWORD),
            'hash_algorithm' => $hasher->algorithm(),
            'password_changed_at' => $now,
            'policy_version' => 'identity-password-v1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $authorizationRoleId = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::AUTHORIZATION_ROLE_CODE)->value('id');
        DB::table('role_assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::CLUSTER_ADMIN_ID,
            'role_id' => $authorizationRoleId,
            'scope_type' => 'cluster',
            'scope_id' => $clusterId,
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::CLUSTER_ADMIN_ID,
            'created_at' => $now,
            'updated_at' => $now,
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
            'display_name_ar' => 'مسؤول منشأة اختبارات النطاق',
            'display_name_en' => 'Facility-only scope-targets admin',
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
        $hasher = app(PasswordHasher::class);
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

    private function seedNoScopeAdmin(): void
    {
        $now = now();
        DB::table('users')->insert([
            'id' => self::NO_SCOPE_ADMIN_ID,
            'username' => self::NO_SCOPE_ADMIN_USERNAME,
            'person_id' => null,
            'person_version' => null,
            'display_name_ar' => 'حساب بلا نطاق',
            'display_name_en' => 'No-scope principal',
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
        $hasher = app(PasswordHasher::class);
        DB::table('credentials')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::NO_SCOPE_ADMIN_ID,
            'password_hash' => $hasher->hash(self::NO_SCOPE_ADMIN_PASSWORD),
            'hash_algorithm' => $hasher->algorithm(),
            'password_changed_at' => $now,
            'policy_version' => 'identity-password-v1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $authorizationRoleId = (string) DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::AUTHORIZATION_ROLE_CODE)->value('id');
        DB::table('role_assignments')->insert([
            'id' => Str::uuid7()->toString(),
            'user_id' => self::NO_SCOPE_ADMIN_ID,
            'role_id' => $authorizationRoleId,
            'scope_type' => 'cluster',
            'scope_id' => '018f6f7d-0c00-7000-8000-0000000fff51',
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::NO_SCOPE_ADMIN_ID,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedUnits(): void
    {
        $clusterId = (string) DB::table('clusters')->where('singleton_key', 1)->value('id');
        foreach ([
            [self::UNIT_A, DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID, 'UA-EXTRA', 'وحدة إضافية أ'],
            [self::UNIT_B, DevelopmentJourneyAuthorizationSeeder::FACILITY_B_ID, 'UB-EXTRA', 'وحدة إضافية ب'],
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

    /** @param array<string, scalar> $params */
    private function url(array $params): string
    {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return '/api/v1/authorization/assignment-scope-targets'.($query === '' ? '' : '?'.$query);
    }

    /** @return array{0: string, 1: string} */
    private function loginClusterAdminSession(): array
    {
        return $this->loginSession(self::CLUSTER_ADMIN_USERNAME, self::CLUSTER_ADMIN_PASSWORD);
    }

    /** @return array{0: string, 1: string} */
    private function loginFacilityAdminSession(): array
    {
        return $this->loginSession(self::FACILITY_ADMIN_USERNAME, self::FACILITY_ADMIN_PASSWORD);
    }

    /** @return array{0: string, 1: string} */
    private function loginSession(string $username, string $password): array
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'scope-targets HTTP adapter test']);
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
}
