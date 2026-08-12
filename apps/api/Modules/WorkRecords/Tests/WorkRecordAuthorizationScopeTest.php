<?php

declare(strict_types=1);

namespace Modules\WorkRecords\Tests;

use Database\Seeders\AuthorizationCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Contracts\ResolveActiveFacilityScopesForUser;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\Workflow\Contracts\WorkflowSourceReference;
use Modules\WorkRecords\Application\WorkRecordAuthorizationFacts;
use Modules\WorkRecords\Application\WorkRecordResourceFacts;
use Modules\WorkRecords\Application\WorkRecordWorkflowSourceAuthorizationFacts;
use Modules\WorkRecords\Features\DocumentLink\Http\WorkRecordDocumentLinkController;
use Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Handler\GetAuthorizedWorkRecordHandler;
use Modules\WorkRecords\Features\Lifecycle\Handler\WorkRecordLifecycleMutator;
use Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Handler\ListAuthorizedWorkRecordsHandler;
use Modules\WorkRecords\Features\SubmitWorkRecord\Http\SubmitWorkRecordController;
use Tests\TestCase;

final class WorkRecordAuthorizationScopeTest extends TestCase
{
    use RefreshDatabase;

    private const CLUSTER_A = '018f6f7d-0000-7000-8000-000000000401';

    private const CLUSTER_B = '018f6f7d-0000-7000-8000-000000000402';

    private const FACILITY_A1 = '018f6f7d-0000-7000-8000-000000000411';

    private const FACILITY_A2 = '018f6f7d-0000-7000-8000-000000000412';

    private const RECORD_ID = '018f6f7d-0000-7000-8000-000000000421';

    private const UNKNOWN_RECORD_ID = '018f6f7d-0000-7000-8000-000000000422';

    private const UNKNOWN_FACILITY = '018f6f7d-0000-7000-8000-000000000499';

    private const USER_ID = '018f6f7d-0000-7000-8000-000000000431';

    private const CREATOR_ID = '018f6f7d-0000-7000-8000-000000000432';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindRealAccessDecision();

        $this->seed(AuthorizationCatalogSeeder::class);
        $this->seedOrganizationTree();
        $this->insertWorkRecord(self::RECORD_ID, self::FACILITY_A1);
        $this->insertWorkRecord(self::UNKNOWN_RECORD_ID, self::UNKNOWN_FACILITY);
    }

    public function test_work_record_resource_facts_are_consistent_and_scope_authorized(): void
    {
        $record = DB::table('work_records')->where('id', self::RECORD_ID)->first();

        $facts = $this->app->make(WorkRecordResourceFacts::class)->forRecord($record);

        $this->assertSame(self::FACILITY_A1, $facts->ownerFacilityId);
        $this->assertSame(self::CLUSTER_A, $facts->clusterId);
        $this->assertNull($facts->organizationUnitId);
        $this->assertSame(self::RECORD_ID, $facts->recordId);
        $this->assertSame(self::CREATOR_ID, $facts->createdByUserId);
        $this->assertSame('submitted', $facts->lifecycleState);
        $this->assertSame('internal', $facts->classification);
        $this->assertSame('work-type-version', $facts->workTypeVersionId);
        $this->assertSame('payload-policy', $facts->fieldPolicyKey);
        $this->assertSame(3, $facts->lockVersion);

        foreach ([
            ['cluster', self::CLUSTER_A, true],
            ['cluster', self::CLUSTER_B, false],
            ['facility', self::FACILITY_A1, true],
            ['facility', self::FACILITY_A2, false],
        ] as [$scopeType, $scopeId, $expected]) {
            $this->assignReaderScope($scopeType, $scopeId);

            $decision = $this->app->make(DecideAccess::class)->evaluateOnly(['user_id' => self::USER_ID], 'work_record.read', $facts);

            $this->assertSame($expected, $decision->isAllowed(), "Unexpected decision for {$scopeType}:{$scopeId}.");
        }
    }

    public function test_unknown_work_record_facility_is_unscoped_and_denied(): void
    {
        $record = DB::table('work_records')->where('id', self::UNKNOWN_RECORD_ID)->first();

        $facts = $this->app->make(WorkRecordResourceFacts::class)->forRecord($record);

        $this->assertNull($facts->ownerFacilityId);
        $this->assertNull($facts->clusterId);
        $this->assertNull($facts->organizationUnitId);

        foreach ([
            ['cluster', self::CLUSTER_A],
            ['facility', self::FACILITY_A1],
        ] as [$scopeType, $scopeId]) {
            $this->assignReaderScope($scopeType, $scopeId);

            $decision = $this->app->make(DecideAccess::class)->evaluateOnly(['user_id' => self::USER_ID], 'work_record.read', $facts);

            $this->assertFalse($decision->isAllowed(), "Unknown facility must deny {$scopeType}:{$scopeId}.");
        }
    }

    public function test_linked_resource_and_workflow_source_paths_share_the_resource_facts(): void
    {
        $linkedFacts = $this->app->make(WorkRecordAuthorizationFacts::class)->resolve(
            new DocumentSourceReference('work-records', 'work_record', self::RECORD_ID),
        );
        $workflowFacts = $this->app->make(WorkRecordWorkflowSourceAuthorizationFacts::class)->resolve(
            new WorkflowSourceReference('work_records', 'work_record', self::RECORD_ID),
        );

        $this->assertNotNull($linkedFacts);
        $this->assertNotNull($workflowFacts);
        foreach ([$linkedFacts, $workflowFacts] as $facts) {
            $this->assertSame(self::FACILITY_A1, $facts->ownerFacilityId);
            $this->assertSame(self::CLUSTER_A, $facts->clusterId);
            $this->assertSame(self::RECORD_ID, $facts->recordId);
            $this->assertSame(self::CREATOR_ID, $facts->createdByUserId);
            $this->assertSame('submitted', $facts->lifecycleState);
            $this->assertSame('payload-policy', $facts->fieldPolicyKey);
            $this->assertSame('work-type-version', $facts->workTypeVersionId);
            $this->assertSame(3, $facts->lockVersion);
        }
    }

    public function test_batch_facts_reject_a_facility_key_without_a_resolved_cluster(): void
    {
        $record = DB::table('work_records')->where('id', self::UNKNOWN_RECORD_ID)->first();

        $facts = $this->app->make(WorkRecordResourceFacts::class)->forRecord(
            $record,
            [self::UNKNOWN_FACILITY => null],
        );

        $this->assertNull($facts->ownerFacilityId);
        $this->assertNull($facts->clusterId);
    }

    public function test_list_keeps_unresolved_facilities_unscoped_without_per_row_ancestry_fallback(): void
    {
        $access = new RecordingWorkRecordAccess;
        $ancestry = new ListAncestrySpy;
        $facilityScopes = new FixedFacilityScopes([self::UNKNOWN_FACILITY]);
        $handler = new ListAuthorizedWorkRecordsHandler(
            $access,
            $ancestry,
            $facilityScopes,
            new WorkRecordResourceFacts($ancestry),
        );

        $handler->handle(
            ['user_id' => self::USER_ID, 'facility_id' => self::UNKNOWN_FACILITY],
            null,
            10,
        );

        $this->assertNotNull($access->facts);
        $this->assertNull($access->facts->ownerFacilityId);
        $this->assertNull($access->facts->clusterId);
        $this->assertSame(1, $ancestry->facilityClusterCalls);
        $this->assertSame(0, $ancestry->singleAncestryCalls);
    }

    public function test_workflow_sources_resolve_facility_ancestry_once_for_the_batch(): void
    {
        $secondRecordId = '018f6f7d-0000-7000-8000-000000000423';
        $this->insertWorkRecord($secondRecordId, self::FACILITY_A2);
        $ancestry = new WorkflowAncestrySpy;
        $factsResolver = new WorkRecordWorkflowSourceAuthorizationFacts(
            new WorkRecordResourceFacts($ancestry),
            $ancestry,
        );

        $facts = $factsResolver->resolveMany([
            new WorkflowSourceReference('work_records', 'work_record', self::RECORD_ID),
            new WorkflowSourceReference('work_records', 'work_record', $secondRecordId),
        ]);

        $this->assertCount(2, $facts);
        $this->assertSame(self::CLUSTER_A, $facts[(new WorkflowSourceReference('work_records', 'work_record', self::RECORD_ID))->key()]->clusterId);
        $this->assertSame(self::CLUSTER_A, $facts[(new WorkflowSourceReference('work_records', 'work_record', $secondRecordId))->key()]->clusterId);
        $this->assertSame(1, $ancestry->facilityClusterCalls);
        $this->assertSame([self::FACILITY_A1, self::FACILITY_A2], $ancestry->facilityClusterArguments);
        $this->assertSame(0, $ancestry->singleAncestryCalls);
    }

    public function test_linked_and_workflow_unknown_facilities_remain_unscoped(): void
    {
        $linkedFacts = $this->app->make(WorkRecordAuthorizationFacts::class)->resolve(
            new DocumentSourceReference('work-records', 'work_record', self::UNKNOWN_RECORD_ID),
        );
        $workflowFacts = $this->app->make(WorkRecordWorkflowSourceAuthorizationFacts::class)->resolve(
            new WorkflowSourceReference('work_records', 'work_record', self::UNKNOWN_RECORD_ID),
        );

        $this->assertNotNull($linkedFacts);
        $this->assertNotNull($workflowFacts);
        foreach ([$linkedFacts, $workflowFacts] as $facts) {
            $this->assertNull($facts->ownerFacilityId);
            $this->assertNull($facts->clusterId);
            $this->assertNull($facts->organizationUnitId);
        }
    }

    public function test_work_record_decision_paths_require_the_shared_builder_via_constructor_injection(): void
    {
        $classes = [
            WorkRecordDocumentLinkController::class,
            GetAuthorizedWorkRecordHandler::class,
            ListAuthorizedWorkRecordsHandler::class,
            WorkRecordLifecycleMutator::class,
            SubmitWorkRecordController::class,
        ];

        foreach ($classes as $class) {
            $constructor = (new \ReflectionClass($class))->getConstructor();
            $this->assertNotNull($constructor, $class.' must declare a constructor.');
            $builderParameters = array_values(array_filter(
                $constructor->getParameters(),
                static function (\ReflectionParameter $parameter): bool {
                    $type = $parameter->getType();

                    return $type instanceof \ReflectionNamedType
                        && $type->getName() === WorkRecordResourceFacts::class;
                },
            ));
            $this->assertCount(1, $builderParameters, $class.' must declare exactly one builder dependency.');
            $this->assertFalse($builderParameters[0]->isOptional(), $class.' must not use optional service-locator fallback.');
        }
    }

    private function assignReaderScope(string $scopeType, string $scopeId): void
    {
        $roleId = '018f6f7d-0000-7000-8000-000000000441';
        $assignmentId = '018f6f7d-0000-7000-8000-000000000442';

        DB::table('roles')->insertOrIgnore([
            'id' => $roleId,
            'code' => 'work-record-scope-reader',
            'name_ar' => 'قارئ سجلات العمل',
            'name_en' => 'Work record scope reader',
            'role_type' => 'administrative',
            'status' => 'active',
            'is_system_role' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $capabilityId = DB::table('capabilities')->where('capability_code', 'work_record.read')->value('id');
        DB::table('role_capabilities')->insertOrIgnore([
            'role_id' => $roleId,
            'capability_id' => $capabilityId,
            'effect' => 'allow',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_assignments')->where('user_id', self::USER_ID)->delete();
        DB::table('role_assignments')->insert([
            'id' => $assignmentId,
            'user_id' => self::USER_ID,
            'role_id' => $roleId,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'start_at' => now()->subMinute(),
            'end_at' => null,
            'status' => 'active',
            'granted_by_user_id' => self::CREATOR_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertWorkRecord(string $recordId, string $facilityId): void
    {
        DB::table('work_records')->insert([
            'id' => $recordId,
            'record_number' => 'WR-'.substr($recordId, -4),
            'work_type_version_id' => 'work-type-version',
            'owner_facility_id' => $facilityId,
            'creator_user_id' => self::CREATOR_ID,
            'status' => 'submitted',
            'classification' => 'internal',
            'field_policy_key' => 'payload-policy',
            'payload' => json_encode(['title' => 'Scoped record'], JSON_THROW_ON_ERROR),
            'lock_version' => 3,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedOrganizationTree(): void
    {
        $now = now();
        DB::table('facility_types')->insert([
            'id' => '018f6f7d-0000-7000-8000-000000000451',
            'code' => 'work-record-hospital',
            'name_ar' => 'مستشفى سجلات العمل',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            [self::CLUSTER_A, 1, 'A'],
            [self::CLUSTER_B, 2, 'B'],
        ] as [$clusterId, $singletonKey, $code]) {
            DB::table('clusters')->insert([
                'id' => $clusterId,
                'singleton_key' => $singletonKey,
                'code' => 'WORK-RECORD-CLUSTER-'.$code,
                'name_ar' => 'تجمع سجلات العمل '.$code,
                'name_en' => 'Work record cluster '.$code,
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            [self::FACILITY_A1, self::CLUSTER_A, 'A1'],
            [self::FACILITY_A2, self::CLUSTER_A, 'A2'],
        ] as [$facilityId, $clusterId, $code]) {
            DB::table('facilities')->insert([
                'id' => $facilityId,
                'cluster_id' => $clusterId,
                'facility_type_id' => '018f6f7d-0000-7000-8000-000000000451',
                'code' => 'WORK-RECORD-FACILITY-'.$code,
                'name_ar' => 'منشأة سجلات العمل '.$code,
                'name_en' => 'Work record facility '.$code,
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

final class RecordingWorkRecordAccess implements DecideAccess
{
    public ?RecordFacts $facts = null;

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $this->facts = $facts;

        return new AccessDecision('allow', $capability, 'work_record', [], 'test', 'test', 'internal');
    }

    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }
}

final class FixedFacilityScopes implements ResolveActiveFacilityScopesForUser
{
    /** @param list<string> $facilityIds */
    public function __construct(private readonly array $facilityIds) {}

    public function facilityScopeIds(string $userId, ?string $atIso8601 = null): array
    {
        return $this->facilityIds;
    }
}

class ListAncestrySpy implements ResolveOrganizationScopeAncestry
{
    public int $facilityClusterCalls = 0;

    public int $singleAncestryCalls = 0;

    /** @var list<string> */
    public array $facilityClusterArguments = [];

    public function ancestry(string $scopeType, string $scopeId): ?array
    {
        $this->singleAncestryCalls++;

        return ['cluster_id' => '018f6f7d-0000-7000-8000-000000000401', 'facility_id' => $scopeId, 'unit_id' => null];
    }

    public function facilityClusterIds(array $facilityIds): array
    {
        $this->facilityClusterCalls++;
        $this->facilityClusterArguments = $facilityIds;

        return [];
    }
}

final class WorkflowAncestrySpy extends ListAncestrySpy
{
    public function facilityClusterIds(array $facilityIds): array
    {
        parent::facilityClusterIds($facilityIds);

        return array_fill_keys($facilityIds, '018f6f7d-0000-7000-8000-000000000401');
    }
}
