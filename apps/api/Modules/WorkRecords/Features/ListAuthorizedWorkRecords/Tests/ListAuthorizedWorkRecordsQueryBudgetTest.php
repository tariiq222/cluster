<?php

namespace Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Handler\ListAuthorizedWorkRecordsHandler;
use Tests\TestCase;

final class ListAuthorizedWorkRecordsQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000501';

    public const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000502';
    private const FACILITY_TYPE_ID = '018f6f7d-0c00-7000-8000-000000000504';

    private const PRINCIPAL_ID = '018f6f7d-0c00-7000-8000-000000000503';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('clusters')->insert([
            'id' => self::CLUSTER_ID,
            'code' => 'WR-CLUSTER',
            'name_ar' => 'تجمع',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('facility_types')->insert([
            'id' => self::FACILITY_TYPE_ID,
            'code' => 'work_record_query_budget_test_facility',
            'name_ar' => 'منشأة اختبار',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('facilities')->insert([
            'id' => self::FACILITY_ID,
            'cluster_id' => self::CLUSTER_ID,
            'facility_type_id' => self::FACILITY_TYPE_ID,
            'code' => 'WR-FAC',
            'name_ar' => 'منشأة',
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_handler_uses_bounded_candidate_window_and_batches_facility_ancestry(): void
    {
        for ($i = 0; $i < 3; $i++) {
            DB::table('work_records')->insert([
                'id' => Str::uuid7()->toString(),
                'record_number' => 'WR-'.$i,
                'work_type_version_id' => '0197f0e0-0000-7000-8000-000000000001',
                'owner_facility_id' => self::FACILITY_ID,
                'creator_user_id' => self::PRINCIPAL_ID,
                'classification' => 'internal',
                'status' => 'submitted',
                'field_policy_key' => 'request',
                'payload' => json_encode(['title' => 'T', 'description' => 'D'], JSON_THROW_ON_ERROR),
                'lock_version' => 1,
                'submitted_at' => now(),
                'created_at' => now()->addSeconds($i),
                'updated_at' => now()->addSeconds($i),
            ]);
        }

        $ancestryCalls = 0;
        $ancestry = new class($ancestryCalls) implements ResolveOrganizationScopeAncestry
        {
            public function __construct(private int &$calls) {}

            public function ancestry(string $scopeType, string $scopeId): ?array
            {
                $this->calls++;

                return ['cluster_id' => ListAuthorizedWorkRecordsQueryBudgetTest::CLUSTER_ID, 'facility_id' => $scopeId, 'unit_id' => null];
            }
        };
        $access = new class implements DecideAccess
        {
            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                return new AccessDecision('allow', $capability, 'work_record', [], 'test', 'test', 'internal');
            }
        };
        $handler = new ListAuthorizedWorkRecordsHandler($access, $ancestry);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = $handler->handle(['user_id' => self::PRINCIPAL_ID, 'facility_id' => self::FACILITY_ID], null, 5, null);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(3, count($page['items']));
        $this->assertLessThanOrEqual(
            4,
            count($queries),
            'handler must keep query budget bounded (role_assignments + work_records candidates + facilities batch + decisions are NOT SQL).',
        );
        $this->assertSame(
            0,
            $ancestryCalls,
            'Per-row ancestry calls must be replaced by a single batched facilities query.',
        );
    }

    public function test_handler_bounds_candidate_window_to_multiplier_times_limit(): void
    {
        $seen = 0;
        $access = new class($seen) implements DecideAccess
        {
            public function __construct(public int &$seen) {}

            public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
            {
                $this->seen++;

                return new AccessDecision('allow', $capability, 'work_record', [], 'test', 'test', 'internal');
            }
        };
        $ancestry = new class implements ResolveOrganizationScopeAncestry
        {
            public function ancestry(string $scopeType, string $scopeId): ?array
            {
                return ['cluster_id' => null, 'facility_id' => $scopeId, 'unit_id' => null];
            }
        };
        $handler = new ListAuthorizedWorkRecordsHandler($access, $ancestry);

        for ($i = 0; $i < 12; $i++) {
            DB::table('work_records')->insert([
                'id' => Str::uuid7()->toString(),
                'record_number' => 'WR-'.$i,
                'work_type_version_id' => '0197f0e0-0000-7000-8000-000000000001',
                'owner_facility_id' => self::FACILITY_ID,
                'creator_user_id' => self::PRINCIPAL_ID,
                'classification' => 'internal',
                'status' => 'submitted',
                'field_policy_key' => 'request',
                'payload' => json_encode(['title' => 'T', 'description' => 'D'], JSON_THROW_ON_ERROR),
                'lock_version' => 1,
                'submitted_at' => now(),
                'created_at' => now()->addSeconds($i),
                'updated_at' => now()->addSeconds($i),
            ]);
        }

        $page = $handler->handle(['user_id' => self::PRINCIPAL_ID, 'facility_id' => self::FACILITY_ID], null, 2, null);
        $this->assertSame(2, count($page['items']));
        $this->assertNotNull($page['next_cursor']);
        $this->assertLessThanOrEqual(2 * 4, $seen, 'Decisions per page must be bounded by the candidate window.');
    }
}
