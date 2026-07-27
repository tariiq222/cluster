<?php

namespace Modules\Organization\Tests;

use App\Http\Authentication\SessionPrincipalResolver;
use Database\Seeders\AuthorizationCatalogSeeder;
use Database\Seeders\DevelopmentJourneyAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Tests\TestCase;

/**
 * Tests for the deterministic sibling ordering and the POST
 * /organization/units/reorder endpoint:
 *
 * - list orders by (parent_type, parent_id, sort_order, code, id)
 * - reorderAll assigns a deterministic, type-priority-then-code order
 * - reorder is idempotent for identical inputs
 * - reorder requires organization.unit.manage and is rejected without it
 * - structural conformance: siblings under the same parent carry a unique,
 *   contiguous sort_order after reordering
 */
final class OrganizationUnitReorderHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_ID = '018f6f7d-0c00-7000-8000-000000000c14';

    private const SESSION_COOKIE = 'cluster_identity_session';

    private string $clusterId = '';

    private string $adminCookie;

    private string $adminCsrf;

    private string $userBCookie;

    private string $userBCsrf;

    private int $keySequence = 0;

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

    public function test_list_returns_units_in_stable_sibling_sort_order(): void
    {
        $items = $this->listAllUnits();
        $previous = null;
        foreach ($items as $item) {
            if ($previous !== null) {
                $this->assertLessThanOrEqual(
                    0,
                    $this->compareSiblingTuples($previous, $item),
                    'list order is not (parent_type, parent_id, sort_order, code, id)',
                );
            }
            $previous = $item;
        }
    }

    public function test_reorder_assigns_deterministic_type_priority_then_code_order(): void
    {
        $response = $this->postAsAdmin('/api/v1/organization/units/reorder', []);
        $response->assertOk();
        $response->assertJsonPath('data.policy', 'type-priority-then-code');

        $items = $this->listAllUnits();
        $byParent = [];
        foreach ($items as $item) {
            $key = $item['parent_type'].'/'.$item['parent_id'];
            $byParent[$key] ??= [];
            $byParent[$key][] = $item;
        }

        $expectedByParentType = [
            'sector' => 1,
            'department' => 2,
            'section' => 3,
            'unit' => 4,
            'committee' => 5,
        ];
        foreach ($byParent as $siblings) {
            $previous = null;
            foreach ($siblings as $sibling) {
                $this->assertSame(
                    $previous === null ? 1 : $previous['sort_order'] + 1,
                    $sibling['sort_order'],
                    'sort_order must be contiguous and start at 1 inside each parent',
                );
                $previous = $sibling;
            }
            $priorities = array_map(
                static fn (string $code): int => $expectedByParentType[$code] ?? 99,
                array_map(static fn (array $item): string => $item['type_code'], $siblings),
            );
            $sortedPriorities = $priorities;
            sort($sortedPriorities);
            $this->assertSame($sortedPriorities, $priorities, 'siblings are not ordered by type priority');
            for ($index = 1; $index < count($siblings); $index++) {
                if ($siblings[$index]['type_code'] === $siblings[$index - 1]['type_code']) {
                    $this->assertLessThanOrEqual(
                        0,
                        strcmp($siblings[$index]['code'], $siblings[$index - 1]['code']),
                        'siblings of the same type must be sorted by code',
                    );
                }
            }
        }
    }

    public function test_reorder_is_idempotent(): void
    {
        $this->postAsAdmin('/api/v1/organization/units/reorder', [])->assertOk();
        $snapshot = $this->listAllUnits();

        $this->postAsAdmin('/api/v1/organization/units/reorder', [])->assertOk();

        $next = $this->listAllUnits();
        $this->assertSame(
            array_map(static fn (array $item): array => [
                'id' => $item['id'], 'parent_id' => $item['parent_id'], 'sort_order' => $item['sort_order'],
            ], $snapshot),
            array_map(static fn (array $item): array => [
                'id' => $item['id'], 'parent_id' => $item['parent_id'], 'sort_order' => $item['sort_order'],
            ], $next),
        );
    }

    public function test_reorder_requires_idempotency_key_and_if_match(): void
    {
        $headers = [
            'X-Correlation-ID' => self::CORRELATION_ID,
            'X-CSRF-Token' => $this->adminCsrf,
        ];
        $this->withUnencryptedCookie(self::SESSION_COOKIE, $this->adminCookie)
            ->withCredentials()
            ->postJson('/api/v1/organization/units/reorder', [], $headers + ['If-Match' => '"1"'])
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://cluster.example/problems/invalid-idempotency-key');
        $this->withUnencryptedCookie(self::SESSION_COOKIE, $this->adminCookie)
            ->withCredentials()
            ->postJson('/api/v1/organization/units/reorder', [], $headers + ['Idempotency-Key' => 'missing-if-match'])
            ->assertStatus(412)
            ->assertJsonPath('type', 'https://cluster.example/problems/precondition-required');
    }

    public function test_reorder_replays_and_rejects_conflicting_key_reuse(): void
    {
        $version = (int) DB::table('clusters')->where('id', $this->clusterId)->value('lock_version');
        $first = $this->postAsAdmin('/api/v1/organization/units/reorder', [], 'reorder-replay', $version)->assertOk();
        $outboxCount = DB::table('outbox_events')->count();
        $replay = $this->postAsAdmin('/api/v1/organization/units/reorder', [], 'reorder-replay', $version)->assertOk();
        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertSame($outboxCount, DB::table('outbox_events')->count());
        $this->postAsAdmin('/api/v1/organization/units/reorder', ['unexpected' => true], 'reorder-replay', $version)
            ->assertConflict()
            ->assertJsonPath('type', 'https://cluster.example/problems/idempotency-conflict');
    }

    public function test_reorder_rejects_stale_if_match_without_changes(): void
    {
        $before = DB::table('organization_units')->orderBy('id')->pluck('sort_order', 'id')->all();
        $this->postAsAdmin('/api/v1/organization/units/reorder', [], 'reorder-stale', 99)
            ->assertStatus(412)
            ->assertJsonPath('type', 'https://cluster.example/problems/precondition-failed');
        $this->assertSame($before, DB::table('organization_units')->orderBy('id')->pluck('sort_order', 'id')->all());
    }

    public function test_reorder_requires_organization_unit_manage(): void
    {
        DB::table('role_assignments')->where('user_id', DevelopmentJourneyAuthorizationSeeder::ACCOUNT_B_ID)->delete();

        $response = $this->withUnencryptedCookie(self::SESSION_COOKIE, $this->userBCookie)
            ->withCredentials()
            ->postJson('/api/v1/organization/units/reorder', [], [
                'X-Correlation-ID' => self::CORRELATION_ID,
                'X-CSRF-Token' => $this->userBCsrf,
                'Idempotency-Key' => $this->nextKey(),
            ]);
        $response->assertForbidden();
    }

    private function listAllUnits(): array
    {
        $response = $this->withUnencryptedCookie(self::SESSION_COOKIE, $this->adminCookie)
            ->withCredentials()
            ->getJson('/api/v1/organization/units?limit=100', ['X-Correlation-ID' => self::CORRELATION_ID]);
        $response->assertOk();

        return $response->json('data.items') ?? [];
    }

    private function postAsAdmin(string $uri, array $payload, ?string $idempotencyKey = null, ?int $ifMatch = null): TestResponse
    {
        $version = $ifMatch ?? (int) DB::table('clusters')->where('id', $this->clusterId)->value('lock_version');

        return $this->withUnencryptedCookie(self::SESSION_COOKIE, $this->adminCookie)
            ->withCredentials()
            ->postJson($uri, $payload, [
                'X-Correlation-ID' => self::CORRELATION_ID,
                'X-CSRF-Token' => $this->adminCsrf,
                'Idempotency-Key' => $idempotencyKey ?? $this->nextKey(),
                'If-Match' => '"'.$version.'"',
            ]);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareSiblingTuples(array $a, array $b): int
    {
        $keys = ['parent_type', 'parent_id', 'sort_order', 'code', 'id'];
        foreach ($keys as $key) {
            $cmp = strcmp((string) $a[$key], (string) $b[$key]);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    private function nextKey(): string
    {
        return 'reorder-'.($this->keySequence++);
    }

    private function seedOrganizationTree(): void
    {
        $this->clusterId = (string) DB::table('clusters')->orderBy('created_at')->value('id');
        $this->assertNotEmpty($this->clusterId, 'clusters table is empty after seeding; expected the dev journey seeder to insert one.');
        $facilityAId = (string) DB::table('facilities')->where('code', 'w13-e2e-facility-a')->value('id');
        $this->assertNotEmpty($facilityAId, 'facility w13-e2e-facility-a missing');
        $sector = $this->unitTypeId('sector');
        $dept = $this->unitTypeId('department');
        $sect = $this->unitTypeId('section');
        $unit = $this->unitTypeId('unit');
        $cmte = $this->unitTypeId('committee');
        $parentId = Str::uuid7()->toString();

        $rows = [
            ['id' => Str::uuid7()->toString(), 'parent_type' => 'cluster', 'parent_id' => $this->clusterId, 'unit_type_id' => $dept, 'code' => 'REORDER-DEPT-Z', 'name_ar' => 'إدارة ز', 'depth' => 1, 'path_cache' => '/'.$this->clusterId.'/'.$facilityAId],
            ['id' => Str::uuid7()->toString(), 'parent_type' => 'cluster', 'parent_id' => $this->clusterId, 'unit_type_id' => $dept, 'code' => 'REORDER-DEPT-A', 'name_ar' => 'إدارة أ', 'depth' => 1, 'path_cache' => '/'.$this->clusterId.'/'.$facilityAId],
            ['id' => $parentId, 'parent_type' => 'cluster', 'parent_id' => $this->clusterId, 'unit_type_id' => $sector, 'code' => 'REORDER-SECTOR-A', 'name_ar' => 'قطاع أ', 'depth' => 1, 'path_cache' => '/'.$this->clusterId.'/'.$facilityAId],
            ['id' => Str::uuid7()->toString(), 'parent_type' => 'unit', 'parent_id' => $parentId, 'unit_type_id' => $sect, 'code' => 'REORDER-SECT-2', 'name_ar' => 'قسم 2', 'depth' => 2, 'path_cache' => '/'.$this->clusterId.'/'.$facilityAId],
            ['id' => Str::uuid7()->toString(), 'parent_type' => 'unit', 'parent_id' => $parentId, 'unit_type_id' => $sect, 'code' => 'REORDER-SECT-1', 'name_ar' => 'قسم 1', 'depth' => 2, 'path_cache' => '/'.$this->clusterId.'/'.$facilityAId],
            ['id' => Str::uuid7()->toString(), 'parent_type' => 'unit', 'parent_id' => $parentId, 'unit_type_id' => $unit, 'code' => 'REORDER-UNIT-1', 'name_ar' => 'وحدة 1', 'depth' => 2, 'path_cache' => '/'.$this->clusterId.'/'.$facilityAId],
            ['id' => Str::uuid7()->toString(), 'parent_type' => 'unit', 'parent_id' => $parentId, 'unit_type_id' => $cmte, 'code' => 'REORDER-CMT-1', 'name_ar' => 'لجنة 1', 'depth' => 2, 'path_cache' => '/'.$this->clusterId.'/'.$facilityAId],
        ];
        foreach ($rows as $row) {
            DB::table('organization_units')->insert(array_merge($row, [
                'cluster_id' => $this->clusterId,
                'name_en' => null,
                'status' => 'active',
                'sort_order' => 0,
                'lock_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    private function unitTypeId(string $code): string
    {
        $id = DB::table('unit_types')->where('code', $code)->value('id');
        $this->assertIsString($id, "unit_types row missing for {$code}");

        return (string) $id;
    }

    /** @return array{0: string, 1: string} */
    private function loginSession(string $username, string $password): array
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'reorder unit regression']);
        $response = $this->postJson('/api/v1/identity/login', [
            'username' => $username,
            'password' => $password,
        ], ['X-Correlation-ID' => self::CORRELATION_ID]);
        $response->assertOk();
        $this->assertCount(1, $response->headers->getCookies());

        return [
            (string) $response->headers->getCookies()[0]->getValue(),
            (string) $response->json('data.csrf_token'),
        ];
    }
}
