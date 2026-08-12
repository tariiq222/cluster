<?php

namespace Modules\Organization\Tests;

use App\Http\Authentication\SessionPrincipalResolver;
use Database\Seeders\AuthorizationCatalogSeeder;
use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Organization\Contracts\AccessDecision;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\Person\Authorization\PersonAuthorizationFacts;
use Tests\TestCase;

final class PersonAuthorizationScopeTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000d01';

    private const ACCOUNT_A_PERSON_ID = '018f6f7d-0c00-7000-8000-000000000031';

    private const ACCOUNT_B_PERSON_ID = '018f6f7d-0c00-7000-8000-000000000032';

    private const PLATFORM_ADMIN_PERSON_ID = '018f6f7d-0c00-7000-8000-000000000033';

    private const SECOND_CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000d11';

    private const SECOND_FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000d12';

    private const SECOND_UNIT_ID = '018f6f7d-0c00-7000-8000-000000000d13';

    private const SECOND_POSITION_ID = '018f6f7d-0c00-7000-8000-000000000d14';

    private const SECOND_PERSON_ID = '018f6f7d-0c00-7000-8000-000000000d15';

    private const UNASSIGNED_PERSON_ID = '018f6f7d-0c00-7000-8000-000000000d16';

    private const NONEXISTENT_PERSON_ID = '018f6f7d-0c00-7000-8000-000000000d99';

    private string $accountACookie;

    private string $accountACsrf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindRealAccessDecision();
        $this->app->bind(ResolveDevelopmentFixturePrincipal::class, SessionPrincipalResolver::class);
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
        $this->seedSecondClusterPerson();
        $this->seedUnassignedPerson();
        [$this->accountACookie, $this->accountACsrf] = $this->loginSession(
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_USERNAME,
            DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_PASSWORD,
        );
    }

    public function test_person_facts_use_assignment_ancestry_and_identify_the_target_record(): void
    {
        $facts = $this->app->make(PersonAuthorizationFacts::class)->forPerson(
            self::ACCOUNT_B_PERSON_ID,
            'organization_person',
        );

        $this->assertCount(1, $facts);
        $this->assertSame(self::ACCOUNT_B_PERSON_ID, $facts[0]->recordId);
        $this->assertSame('organization', $facts[0]->sourceModule);
        $this->assertSame(DevelopmentJourneyAuthorizationSeeder::FACILITY_B_ID, $facts[0]->ownerFacilityId);
        $this->assertSame(
            (string) DB::table('organization_units')->where('code', 'w13-e2e-unit-b')->value('id'),
            $facts[0]->organizationUnitId,
        );
        $this->assertSame($this->singletonClusterId(), $facts[0]->clusterId);
    }

    public function test_facility_scope_allows_same_scope_and_hides_cross_scope_and_nonexistent_targets(): void
    {
        $this->getPerson(self::ACCOUNT_A_PERSON_ID)->assertOk();
        $this->getReference(self::ACCOUNT_A_PERSON_ID)->assertOk();

        $crossScopePerson = $this->getPerson(self::ACCOUNT_B_PERSON_ID)
            ->assertNotFound()
            ->assertJsonPath('type', 'https://cluster.example/problems/person-not-found');
        $missingPerson = $this->getPerson(self::NONEXISTENT_PERSON_ID)
            ->assertNotFound()
            ->assertJsonPath('type', 'https://cluster.example/problems/person-not-found');
        $this->assertSame($crossScopePerson->status(), $missingPerson->status());
        $this->assertSame($crossScopePerson->json('type'), $missingPerson->json('type'));

        $crossScopeReference = $this->getReference(self::ACCOUNT_B_PERSON_ID)
            ->assertNotFound()
            ->assertJsonPath('type', 'https://cluster.example/problems/person-not-found');
        $missingReference = $this->getReference(self::NONEXISTENT_PERSON_ID)
            ->assertNotFound()
            ->assertJsonPath('type', 'https://cluster.example/problems/person-not-found');
        $this->assertSame($crossScopeReference->status(), $missingReference->status());
        $this->assertSame($crossScopeReference->json('type'), $missingReference->json('type'));

        $crossScopeUpdate = $this->patchPerson(self::ACCOUNT_B_PERSON_ID, ['display_name_ar' => 'لا يجب تحديثه'], 1)
            ->assertNotFound()
            ->assertJsonPath('type', 'https://cluster.example/problems/person-not-found');
        $missingUpdate = $this->patchPerson(self::NONEXISTENT_PERSON_ID, ['display_name_ar' => 'لا يجب تحديثه'], 1)
            ->assertNotFound()
            ->assertJsonPath('type', 'https://cluster.example/problems/person-not-found');
        $this->assertSame($crossScopeUpdate->status(), $missingUpdate->status());
        $this->assertSame($crossScopeUpdate->json('type'), $missingUpdate->json('type'));

        $this->assertDatabaseHas('people', [
            'id' => self::ACCOUNT_B_PERSON_ID,
            'display_name_ar' => 'حساب اختبار W1.3 ب',
            'person_version' => 1,
        ]);
    }

    public function test_facility_scope_hides_a_person_in_another_cluster(): void
    {
        $this->getPerson(self::SECOND_PERSON_ID)->assertNotFound();
        $this->getReference(self::SECOND_PERSON_ID)->assertNotFound();
    }

    public function test_list_excludes_hidden_people_and_cursor_continues_across_hidden_rows(): void
    {
        $first = $this->listPeople(1)->assertOk();
        $this->assertSame([self::ACCOUNT_A_PERSON_ID], array_column($first->json('items'), 'id'));
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);

        $second = $this->listPeople(1, $cursor)->assertOk();
        $this->assertSame([self::PLATFORM_ADMIN_PERSON_ID], array_column($second->json('items'), 'id'));
        $this->assertNull($second->json('next_cursor'));
        $this->assertNotContains(self::ACCOUNT_B_PERSON_ID, array_column($second->json('items'), 'id'));
        $this->assertNotContains(self::SECOND_PERSON_ID, array_column($second->json('items'), 'id'));
    }

    public function test_list_keeps_query_budget_bounded_when_many_hidden_people_precede_authorized_target(): void
    {
        $this->app->instance(DecideAccess::class, new class implements DecideAccess
        {
            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                $resourceType = $facts === null ? 'organization_person' : $facts->resourceType;
                $factsVersion = $facts === null ? 'unavailable' : $facts->factsVersion;
                $classification = $facts === null ? 'unknown' : $facts->classification;

                return new AccessDecision(
                    decision: $facts?->ownerFacilityId === ($actor['facility_id'] ?? null) ? 'allow' : 'deny',
                    action: $capability,
                    resourceType: $resourceType,
                    reasonCodes: [],
                    policyVersion: 'person-query-budget-test',
                    factsVersion: $factsVersion,
                    classification: $classification,
                );
            }

            public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                return $this->decide($actor, $capability, $facts);
            }
        });

        $first = $this->listPeople(1)->assertOk();
        $firstCursor = $first->json('next_cursor');
        $this->assertIsString($firstCursor);

        $targetPersonId = $this->seedManyHiddenPeopleBeforeAuthorizedTarget();

        $second = $this->listPeople(1, $firstCursor)->assertOk();
        $cursor = $second->json('next_cursor');
        $this->assertIsString($cursor);

        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });

        $second = $this->listPeople(1, $cursor)->assertOk();

        $this->assertSame([$targetPersonId], array_column($second->json('items'), 'id'));
        $this->assertNull($second->json('next_cursor'));
        $this->assertLessThanOrEqual(
            24,
            $queryCount,
            'list must batch target scope reads instead of resolving each hidden person independently.',
        );
    }

    public function test_list_caps_raw_scans_and_cursor_walks_hidden_pages_to_a_later_target(): void
    {
        $targetPersonId = $this->seedManyHiddenPeopleBeforeAuthorizedTarget(353);
        $first = $this->listPeople(2)->assertOk();
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);

        $peopleQueries = 0;
        DB::listen(static function (object $query) use (&$peopleQueries): void {
            if (preg_match('/\bfrom\s+["`]?people["`]?\b/i', $query->sql) === 1) {
                $peopleQueries++;
            }
        });

        $page = $this->listPeople(2, $cursor)->assertOk();
        $this->assertSame([], $page->json('items'));
        $this->assertIsString($page->json('next_cursor'));
        $this->assertLessThanOrEqual(
            3,
            $peopleQueries,
            'each request must use a fixed number of people queries, regardless of hidden-row volume.',
        );

        $seen = [];
        $cursor = $page->json('next_cursor');
        for ($pageNumber = 0; $pageNumber < 4; $pageNumber++) {
            $peopleQueries = 0;
            $page = $this->listPeople(2, $cursor)->assertOk();
            $seen = [...$seen, ...array_column($page->json('items'), 'id')];
            $this->assertLessThanOrEqual(3, $peopleQueries);
            if (in_array($targetPersonId, $seen, true)) {
                $this->assertNull($page->json('next_cursor'));
                break;
            }
            $cursor = $page->json('next_cursor');
            $this->assertIsString($cursor);
        }

        $this->assertSame([$targetPersonId], $seen);
    }

    public function test_list_cursor_uses_last_returned_visible_person_when_visible_and_raw_rows_continue(): void
    {
        $this->app->instance(DecideAccess::class, new class implements DecideAccess
        {
            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                $resourceType = $facts === null ? 'organization_person' : $facts->resourceType;
                $factsVersion = $facts === null ? 'unavailable' : $facts->factsVersion;
                $classification = $facts === null ? 'unknown' : $facts->classification;

                return new AccessDecision(
                    decision: 'allow',
                    action: $capability,
                    resourceType: $resourceType,
                    reasonCodes: [],
                    policyVersion: 'person-pagination-cursor-test',
                    factsVersion: $factsVersion,
                    classification: $classification,
                );
            }

            public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                return $this->decide($actor, $capability, $facts);
            }
        });

        $this->seedManyHiddenPeopleBeforeAuthorizedTarget(132);
        $rawRows = DB::table('people')->orderBy('id')->limit(101)->pluck('id');
        $this->assertCount(101, $rawRows);

        $visibleWithinRawBudget = DB::table('people')
            ->join('assignments', 'assignments.person_id', '=', 'people.id')
            ->whereIn('people.id', $rawRows->take(100)->all())
            ->distinct()
            ->count('people.id');
        $this->assertGreaterThan(2, $visibleWithinRawBudget);

        $expected = DB::table('people')
            ->join('assignments', 'assignments.person_id', '=', 'people.id')
            ->orderBy('people.id')
            ->distinct()
            ->pluck('people.id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $first = $this->listPeople(2)->assertOk();
        $seen = array_column($first->json('items'), 'id');
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);

        for ($pageNumber = 0; $pageNumber < 100 && $cursor !== null; $pageNumber++) {
            $page = $this->listPeople(2, $cursor)->assertOk();
            $seen = [...$seen, ...array_column($page->json('items'), 'id')];
            $cursor = $page->json('next_cursor');
        }

        $this->assertNull($cursor);
        $this->assertSame($expected, $seen);
    }

    public function test_cluster_scope_is_bounded_to_the_persons_actual_cluster(): void
    {
        $roleId = DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::ROLE_CODE)->value('id');
        $this->assertIsString($roleId);
        DB::table('role_assignments')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000d17',
            'user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => $this->singletonClusterId(),
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getPerson(self::ACCOUNT_B_PERSON_ID)->assertOk();
        $this->getReference(self::ACCOUNT_B_PERSON_ID)->assertOk();
        $this->getPerson(self::SECOND_PERSON_ID)->assertNotFound();
    }

    public function test_cluster_scope_does_not_reveal_an_unassigned_person(): void
    {
        $roleId = DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::ROLE_CODE)->value('id');
        $this->assertIsString($roleId);
        DB::table('role_assignments')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000d19',
            'user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'role_id' => $roleId,
            'scope_type' => 'cluster',
            'scope_id' => $this->singletonClusterId(),
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getPerson(self::UNASSIGNED_PERSON_ID)->assertNotFound();
        $this->getReference(self::UNASSIGNED_PERSON_ID)->assertNotFound();
        $list = $this->listPeople(100)->assertOk();
        $this->assertNotContains(self::UNASSIGNED_PERSON_ID, array_column($list->json('items'), 'id'));
    }

    public function test_unit_scope_matches_target_assignments_and_uses_any_covered_assignment(): void
    {
        $this->replaceAccountAFacilityGrantWithUnitGrant();

        $this->getPerson(self::ACCOUNT_A_PERSON_ID)->assertOk();
        $this->getPerson(self::ACCOUNT_B_PERSON_ID)->assertNotFound();

        $unitId = $this->accountAUnitId();
        $positionId = (string) DB::table('positions')->where('organization_unit_id', $unitId)->value('id');
        $this->assertNotSame('', $positionId);
        DB::table('assignments')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000d20',
            'person_id' => self::ACCOUNT_B_PERSON_ID,
            'position_id' => $positionId,
            'start_at' => now()->subHour(),
            'end_at' => null,
            'is_primary' => false,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $facts = $this->app->make(PersonAuthorizationFacts::class)->forPerson(
            self::ACCOUNT_B_PERSON_ID,
            'organization_person',
        );
        $this->assertCount(2, $facts);
        $this->assertSame(
            [
                [
                    'record' => self::ACCOUNT_B_PERSON_ID,
                    'cluster' => $this->singletonClusterId(),
                    'facility' => DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID,
                    'unit' => $unitId,
                ],
                [
                    'record' => self::ACCOUNT_B_PERSON_ID,
                    'cluster' => $this->singletonClusterId(),
                    'facility' => DevelopmentJourneyAuthorizationSeeder::FACILITY_B_ID,
                    'unit' => (string) DB::table('organization_units')->where('code', 'w13-e2e-unit-b')->value('id'),
                ],
            ],
            array_map(static fn ($fact): array => [
                'record' => $fact->recordId,
                'cluster' => $fact->clusterId,
                'facility' => $fact->ownerFacilityId,
                'unit' => $fact->organizationUnitId,
            ], $facts),
        );
        $this->getPerson(self::ACCOUNT_B_PERSON_ID)->assertOk();
    }

    public function test_create_is_authorized_without_treating_a_nonexistent_target_as_a_person_fact(): void
    {
        $response = $this->withUnencryptedCookie('cluster_identity_session', $this->accountACookie)
            ->withCredentials()
            ->postJson('/api/v1/organization/people', [
                'employee_number' => 'W13-CREATED-WITHOUT-ASSIGNMENT',
                'display_name_ar' => 'موظف منشأ بلا تكليف',
                'display_name_en' => 'Created without assignment',
                'status' => 'active',
            ], [
                ...$this->headers(),
                'X-CSRF-Token' => $this->accountACsrf,
                'Idempotency-Key' => 'person-create-without-target-facts',
            ])->assertCreated();

        $personId = (string) $response->json('data.id');
        $this->assertNotSame('', $personId);
        $this->getPerson($personId)->assertNotFound();
    }

    public function test_unassigned_person_is_hidden_for_a_facility_scope(): void
    {
        $this->getPerson(self::UNASSIGNED_PERSON_ID)->assertNotFound();
        $this->getReference(self::UNASSIGNED_PERSON_ID)->assertNotFound();
    }

    private function getPerson(string $personId): TestResponse
    {
        return $this->withUnencryptedCookie('cluster_identity_session', $this->accountACookie)
            ->withCredentials()
            ->getJson('/api/v1/organization/people/'.$personId, $this->headers());
    }

    private function getReference(string $personId): TestResponse
    {
        return $this->withUnencryptedCookie('cluster_identity_session', $this->accountACookie)
            ->withCredentials()
            ->getJson('/api/v1/organization/people/'.$personId.'/reference', $this->headers());
    }

    /** @param array<string, string> $payload */
    private function patchPerson(string $personId, array $payload, int $version): TestResponse
    {
        return $this->withUnencryptedCookie('cluster_identity_session', $this->accountACookie)
            ->withCredentials()
            ->patchJson('/api/v1/organization/people/'.$personId, $payload, [
                ...$this->headers(),
                'X-CSRF-Token' => $this->accountACsrf,
                'If-Match' => '"'.$version.'"',
                'Content-Type' => 'application/merge-patch+json',
            ]);
    }

    private function listPeople(int $limit, ?string $cursor = null): TestResponse
    {
        $query = '?limit='.$limit;
        if ($cursor !== null) {
            $query .= '&cursor='.rawurlencode($cursor);
        }

        return $this->withUnencryptedCookie('cluster_identity_session', $this->accountACookie)
            ->withCredentials()
            ->getJson('/api/v1/organization/people'.$query, $this->headers());
    }

    /** @return array{0: string, 1: string} */
    private function loginSession(string $username, string $password): array
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'person authorization scope regression']);
        $response = $this->postJson('/api/v1/identity/login', [
            'username' => $username,
            'password' => $password,
        ], $this->headers());
        $response->assertOk();
        $this->assertCount(1, $response->headers->getCookies());

        return [
            (string) $response->headers->getCookies()[0]->getValue(),
            (string) $response->json('data.csrf_token'),
        ];
    }

    private function singletonClusterId(): string
    {
        return (string) DB::table('clusters')->where('singleton_key', 1)->value('id');
    }

    private function seedSecondClusterPerson(): void
    {
        $now = now();
        $facilityTypeId = (string) DB::table('facilities')->where('id', DevelopmentJourneyAuthorizationSeeder::FACILITY_A_ID)->value('facility_type_id');
        $unitTypeId = (string) DB::table('organization_units')->where('id', '018f6f7d-0c00-7000-8000-000000000041')->value('unit_type_id');
        DB::table('clusters')->insert([
            'id' => self::SECOND_CLUSTER_ID,
            'singleton_key' => 2,
            'code' => 'W13-SECOND-CLUSTER',
            'name_ar' => 'تجمع اختبار ثان',
            'name_en' => 'Second test cluster',
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('facilities')->insert([
            'id' => self::SECOND_FACILITY_ID,
            'cluster_id' => self::SECOND_CLUSTER_ID,
            'facility_type_id' => $facilityTypeId,
            'code' => 'w13-second-facility',
            'name_ar' => 'منشأة اختبار ثانية',
            'name_en' => 'Second test facility',
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('organization_units')->insert([
            'id' => self::SECOND_UNIT_ID,
            'cluster_id' => self::SECOND_CLUSTER_ID,
            'parent_id' => self::SECOND_FACILITY_ID,
            'parent_type' => 'facility',
            'unit_type_id' => $unitTypeId,
            'code' => 'w13-second-unit',
            'name_ar' => 'وحدة اختبار ثانية',
            'name_en' => 'Second test unit',
            'status' => 'active',
            'path_cache' => '/'.self::SECOND_CLUSTER_ID.'/'.self::SECOND_FACILITY_ID.'/'.self::SECOND_UNIT_ID,
            'depth' => 2,
            'sort_order' => 0,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('positions')->insert([
            'id' => self::SECOND_POSITION_ID,
            'organization_unit_id' => self::SECOND_UNIT_ID,
            'code' => 'W13-SECOND-POSITION',
            'title_ar' => 'منصب اختبار ثان',
            'is_active' => true,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('people')->insert([
            'id' => self::SECOND_PERSON_ID,
            'employee_number' => 'W13-SECOND-PERSON',
            'display_name_ar' => 'موظف اختبار ثان',
            'display_name_en' => 'Second test person',
            'status' => 'active',
            'person_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('assignments')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000d18',
            'person_id' => self::SECOND_PERSON_ID,
            'position_id' => self::SECOND_POSITION_ID,
            'start_at' => now()->subHour(),
            'end_at' => null,
            'is_primary' => true,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedUnassignedPerson(): void
    {
        DB::table('people')->insert([
            'id' => self::UNASSIGNED_PERSON_ID,
            'employee_number' => 'W13-UNASSIGNED-PERSON',
            'display_name_ar' => 'موظف بلا تكليف',
            'display_name_en' => 'Unassigned test person',
            'status' => 'active',
            'person_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedManyHiddenPeopleBeforeAuthorizedTarget(int $lastHiddenSuffix = 153): string
    {
        $now = now();
        $hiddenPositionId = (string) DB::table('positions')
            ->where('organization_unit_id', DB::table('organization_units')->where('code', 'w13-e2e-unit-b')->value('id'))
            ->value('id');
        $authorizedPositionId = (string) DB::table('positions')
            ->where('organization_unit_id', $this->accountAUnitId())
            ->value('id');
        $this->assertNotSame('', $hiddenPositionId);
        $this->assertNotSame('', $authorizedPositionId);

        for ($suffix = 34; $suffix <= $lastHiddenSuffix; $suffix++) {
            $personId = sprintf('018f6f7d-0c00-7000-8000-%012d', $suffix);
            DB::table('people')->insert([
                'id' => $personId,
                'employee_number' => 'W13-HIDDEN-'.$suffix,
                'display_name_ar' => 'موظف مخفي '.$suffix,
                'display_name_en' => 'Hidden person '.$suffix,
                'status' => 'active',
                'person_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('assignments')->insert([
                'id' => sprintf('018f6f7d-0c00-7000-8001-%012d', $suffix),
                'person_id' => $personId,
                'position_id' => $hiddenPositionId,
                'start_at' => $now->copy()->subHour(),
                'end_at' => null,
                'is_primary' => true,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $targetPersonId = sprintf('018f6f7d-0c00-7000-8000-%012d', $lastHiddenSuffix + 1);
        DB::table('people')->insert([
            'id' => $targetPersonId,
            'employee_number' => 'W13-AUTHORIZED-TARGET-'.$lastHiddenSuffix,
            'display_name_ar' => 'هدف مصرح',
            'display_name_en' => 'Authorized target',
            'status' => 'active',
            'person_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('assignments')->insert([
            'id' => sprintf('018f6f7d-0c00-7000-8001-%012d', $lastHiddenSuffix + 1),
            'person_id' => $targetPersonId,
            'position_id' => $authorizedPositionId,
            'start_at' => $now->copy()->subHour(),
            'end_at' => null,
            'is_primary' => true,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $targetPersonId;
    }

    private function replaceAccountAFacilityGrantWithUnitGrant(): void
    {
        $roleId = DB::table('roles')->where('code', DevelopmentJourneyAuthorizationSeeder::ROLE_CODE)->value('id');
        $this->assertIsString($roleId);
        DB::table('role_assignments')
            ->where('user_id', DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID)
            ->where('role_id', $roleId)
            ->where('scope_type', 'facility')
            ->delete();
        DB::table('role_assignments')->insert([
            'id' => '018f6f7d-0c00-7000-8000-000000000d21',
            'user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'role_id' => $roleId,
            'scope_type' => 'unit',
            'scope_id' => $this->accountAUnitId(),
            'start_at' => '2026-01-01 00:00:00.000',
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => DevelopmentJourneyAuthorizationSeeder::ACCOUNT_A_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function accountAUnitId(): string
    {
        return (string) DB::table('organization_units')->where('code', 'w13-e2e-unit-a')->value('id');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Correlation-ID' => self::CORRELATION_ID];
    }
}
