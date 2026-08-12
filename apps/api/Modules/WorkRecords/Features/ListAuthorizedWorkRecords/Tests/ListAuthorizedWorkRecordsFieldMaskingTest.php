<?php

namespace Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Contracts\ResolveActiveFacilityScopesForUser;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\WorkRecords\Application\WorkRecordResourceFacts;
use Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Handler\ListAuthorizedWorkRecordsHandler;
use Tests\TestCase;

final class ListAuthorizedWorkRecordsFieldMaskingTest extends TestCase
{
    use RefreshDatabase;

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000701';

    private const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000702';

    private const FACILITY_TYPE_ID = '018f6f7d-0c00-7000-8000-000000000704';

    private const PRINCIPAL_ID = '018f6f7d-0c00-7000-8000-000000000703';

    private const FIELD_POLICY_KEY = 'work_record.classified';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('clusters')->insert([
            'id' => self::CLUSTER_ID,
            'code' => 'WR-CLUSTER-LIST',
            'name_ar' => 'تجمع',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('facility_types')->insert([
            'id' => self::FACILITY_TYPE_ID,
            'code' => 'work_record_list_test_facility',
            'name_ar' => 'منشأة اختبار',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('facilities')->insert([
            'id' => self::FACILITY_ID,
            'cluster_id' => self::CLUSTER_ID,
            'facility_type_id' => self::FACILITY_TYPE_ID,
            'code' => 'WR-FAC-LIST',
            'name_ar' => 'منشأة',
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seedWorkRecord('title-A', 'desc-A');
        $this->seedWorkRecord('title-B', 'desc-B');
    }

    private function seedWorkRecord(string $title, string $description): void
    {
        DB::table('work_records')->insert([
            'id' => Str::uuid7()->toString(),
            'record_number' => 'WR-LIST-'.Str::random(8),
            'work_type_version_id' => '0197f0e0-0000-7000-8000-000000000001',
            'owner_facility_id' => self::FACILITY_ID,
            'creator_user_id' => self::PRINCIPAL_ID,
            'status' => 'submitted',
            'classification' => 'confidential',
            'field_policy_key' => self::FIELD_POLICY_KEY,
            'payload' => json_encode([
                'title' => $title,
                'description' => $description,
                'internal_memo' => 'memo-'.$title,
            ], JSON_THROW_ON_ERROR),
            'lock_version' => 1,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_list_handler_hides_masked_and_keeps_visible_fields_per_field_policy(): void
    {
        $decider = new ListFieldPolicyDecider;
        $ancestry = new ListSingleAncestry(self::CLUSTER_ID, self::FACILITY_ID);
        $facilityScopes = new class implements ResolveActiveFacilityScopesForUser
        {
            public function facilityScopeIds(string $userId, ?string $atIso8601 = null): array
            {
                return [];
            }
        };
        $handler = new ListAuthorizedWorkRecordsHandler($decider, $ancestry, $facilityScopes, new WorkRecordResourceFacts($ancestry));

        $page = $handler->handle(['user_id' => self::PRINCIPAL_ID, 'facility_id' => self::FACILITY_ID], null, 10, 'confidential');

        $this->assertCount(2, $page['items']);
        foreach ($page['items'] as $item) {
            $this->assertArrayHasKey('title', $item['payload']);
            $this->assertArrayHasKey('description', $item['payload']);
            $this->assertArrayNotHasKey('internal_memo', $item['payload']);
            $this->assertSame('***', $item['payload']['description']);
        }
        $this->assertNotNull($decider->lastFacts);
        $this->assertSame(self::FACILITY_ID, $decider->lastFacts->ownerFacilityId);
        $this->assertSame(self::CLUSTER_ID, $decider->lastFacts->clusterId);
        $this->assertSame('confidential', $decider->lastFacts->classification);
        $this->assertSame('submitted', $decider->lastFacts->lifecycleState);
        $this->assertSame(self::FIELD_POLICY_KEY, $decider->lastFacts->fieldPolicyKey);
        $this->assertSame('0197f0e0-0000-7000-8000-000000000001', $decider->lastFacts->workTypeVersionId);
        $this->assertSame(1, $decider->lastFacts->lockVersion);
    }
}

final class ListFieldPolicyDecider implements DecideAccess
{
    public ?RecordFacts $lastFacts = null;

    /**
     * Test doubles persist nothing, so the read-side evaluation IS decide().
     */
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $this->lastFacts = $facts;

        return new AccessDecision(
            decision: 'allow',
            action: $capability,
            resourceType: 'work_record',
            reasonCodes: ['focused_list_field_masking_test'],
            policyVersion: 'rbac-abac-v2',
            factsVersion: $facts === null ? 'test' : $facts->factsVersion,
            classification: $facts === null ? 'confidential' : $facts->classification,
            decisionId: '0197f0e0-0000-7000-8000-000000000bbb',
            allowedActions: ['list'],
            fieldAccess: [
                'payload.title' => 'readonly',
                'payload.description' => 'masked',
                'payload.internal_memo' => 'hidden',
            ],
        );
    }
}

final class ListSingleAncestry implements ResolveOrganizationScopeAncestry
{
    public function __construct(
        private readonly string $clusterId,
        private readonly string $facilityId,
    ) {}

    public function ancestry(string $scopeType, string $scopeId): array
    {
        return ['cluster_id' => $this->clusterId, 'facility_id' => $this->facilityId, 'unit_id' => null];
    }

    public function facilityClusterIds(array $facilityIds): array
    {
        return array_fill_keys($facilityIds, $this->clusterId);
    }
}
