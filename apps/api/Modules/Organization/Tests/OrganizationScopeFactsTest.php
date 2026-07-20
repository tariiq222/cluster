<?php

namespace Modules\Organization\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use Modules\Organization\Contracts\ResolvePersonOrganizationScope;
use Modules\Organization\Infrastructure\Persistence\DatabaseGetActiveSupervisoryRelationships;
use Modules\Organization\Infrastructure\Persistence\DatabaseResolvePersonOrganizationScope;
use Tests\TestCase;

class OrganizationScopeFactsTest extends TestCase
{
    use RefreshDatabase;

    private const CLUSTER_ID = '018f6f7d-0c00-7000-8000-000000000901';

    private const FACILITY_TYPE_ID = '018f6f7d-0c00-7000-8000-000000000902';

    private const UNIT_TYPE_ID = '018f6f7d-0c00-7000-8000-000000000903';

    private const FACILITY_ID = '018f6f7d-0c00-7000-8000-000000000904';

    private const UNIT_A_ID = '018f6f7d-0c00-7000-8000-000000000905';

    private const UNIT_B_ID = '018f6f7d-0c00-7000-8000-000000000906';

    private const UNIT_C_ID = '018f6f7d-0c00-7000-8000-000000000907';

    private const UNIT_D_ID = '018f6f7d-0c00-7000-8000-000000000908';

    private const UNIT_E_ID = '018f6f7d-0c00-7000-8000-000000000909';

    private const PERSON_ID = '018f6f7d-0c00-7000-8000-000000000910';

    private const SECOND_PERSON_ID = '018f6f7d-0c00-7000-8000-000000000911';

    private const POSITION_A_ID = '018f6f7d-0c00-7000-8000-000000000912';

    private const POSITION_B_ID = '018f6f7d-0c00-7000-8000-000000000913';

    private const POSITION_D_ID = '018f6f7d-0c00-7000-8000-000000000914';

    private const ASSIGNMENT_PRIMARY_ID = '018f6f7d-0c00-7000-8000-000000000915';

    private const ASSIGNMENT_SECONDARY_ID = '018f6f7d-0c00-7000-8000-000000000916';

    private const ASSIGNMENT_ENDED_ID = '018f6f7d-0c00-7000-8000-000000000917';

    private const TEMP_ACTIVE_ID = '018f6f7d-0c00-7000-8000-000000000918';

    private const TEMP_EXPIRED_ID = '018f6f7d-0c00-7000-8000-000000000919';

    private const TEMP_REVOKED_ID = '018f6f7d-0c00-7000-8000-000000000920';

    private const TEMP_SECOND_PERSON_ID = '018f6f7d-0c00-7000-8000-000000000921';

    private const ACTOR_ID = '018f6f7d-0c00-7000-8000-000000000922';

    private const REL_ACTIVE_ID = '018f6f7d-0c00-7000-8000-000000000923';

    private const REL_FUTURE_ID = '018f6f7d-0c00-7000-8000-000000000924';

    private const REL_EXPIRED_ID = '018f6f7d-0c00-7000-8000-000000000925';

    private const REL_REVERSE_ID = '018f6f7d-0c00-7000-8000-000000000926';

    private const CAP_ONE_ID = '018f6f7d-0c00-7000-8000-000000000927';

    private const CAP_TWO_ID = '018f6f7d-0c00-7000-8000-000000000928';

    private const REL_ENDING_NOW_ID = '018f6f7d-0c00-7000-8000-000000000929';

    private const REL_STARTING_NOW_ID = '018f6f7d-0c00-7000-8000-000000000930';

    private const CAP_THREE_ID = '018f6f7d-0c00-7000-8000-000000000931';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-19T10:00:00.000Z');
        $this->seedOrganizationTree();
        $this->seedWorkforceAssignments();
        $this->seedSupervisoryRelationships();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_scope_unions_active_assignments_and_temporaries_with_ancestry(): void
    {
        $resolver = new DatabaseResolvePersonOrganizationScope;
        $this->assertInstanceOf(ResolvePersonOrganizationScope::class, $resolver);

        $scope = $resolver->forPerson(self::PERSON_ID);

        $this->assertSame([self::CLUSTER_ID], $scope['cluster_ids']);
        $this->assertSame([self::FACILITY_ID], $scope['facility_ids']);
        $this->assertSame(
            [self::UNIT_A_ID, self::UNIT_B_ID, self::UNIT_C_ID],
            $scope['organization_unit_ids'],
        );
        $this->assertSame(self::UNIT_A_ID, $scope['primary_organization_unit_id']);
    }

    public function test_scope_excludes_ended_assignments_and_expired_or_revoked_temporaries(): void
    {
        $scope = (new DatabaseResolvePersonOrganizationScope)->forPerson(self::PERSON_ID);

        $this->assertNotContains(self::UNIT_D_ID, $scope['organization_unit_ids']);
        $this->assertNotContains(self::UNIT_E_ID, $scope['organization_unit_ids']);
    }

    public function test_scope_falls_back_to_the_first_temporary_unit_as_primary(): void
    {
        $scope = (new DatabaseResolvePersonOrganizationScope)->forPerson(self::SECOND_PERSON_ID);

        $this->assertSame([self::UNIT_D_ID], $scope['organization_unit_ids']);
        $this->assertSame([self::FACILITY_ID], $scope['facility_ids']);
        $this->assertSame([self::CLUSTER_ID], $scope['cluster_ids']);
        $this->assertSame(self::UNIT_D_ID, $scope['primary_organization_unit_id']);
    }

    public function test_scope_fails_closed_for_unknown_person_identifiers(): void
    {
        $empty = [
            'cluster_ids' => [],
            'facility_ids' => [],
            'organization_unit_ids' => [],
            'primary_organization_unit_id' => null,
        ];
        $resolver = new DatabaseResolvePersonOrganizationScope;

        $this->assertSame($empty, $resolver->forPerson('018f6f7d-0c00-7000-8000-000000000999'));
        $this->assertSame($empty, $resolver->forPerson(''));
    }

    public function test_supervisory_facts_return_windowed_relationships_with_ordered_capabilities(): void
    {
        $relationships = new DatabaseGetActiveSupervisoryRelationships;
        $this->assertInstanceOf(GetActiveSupervisoryRelationships::class, $relationships);

        $facts = $relationships->forSourceOrganizationUnit(self::UNIT_A_ID);

        $this->assertSame(
            [self::REL_ACTIVE_ID, self::REL_STARTING_NOW_ID],
            array_column($facts, 'supervisory_relationship_id'),
        );
        $this->assertSame([
            'supervisory_relationship_id' => self::REL_ACTIVE_ID,
            'source_organization_unit_id' => self::UNIT_A_ID,
            'target_organization_unit_id' => self::UNIT_B_ID,
            'relationship_type' => 'direct',
            'valid_from' => '2026-07-19T09:00:00.000Z',
            'valid_until' => '2026-07-19T11:00:00.000Z',
            'relationship_capabilities' => [
                [
                    'relationship_capability_id' => self::CAP_ONE_ID,
                    'module_code' => 'authorization',
                    'capability_code' => 'grant',
                ],
                [
                    'relationship_capability_id' => self::CAP_TWO_ID,
                    'module_code' => 'work-records',
                    'capability_code' => 'view_details',
                ],
            ],
        ], $facts[0]);
        $this->assertSame([
            'supervisory_relationship_id' => self::REL_STARTING_NOW_ID,
            'source_organization_unit_id' => self::UNIT_A_ID,
            'target_organization_unit_id' => self::UNIT_B_ID,
            'relationship_type' => 'functional',
            'valid_from' => '2026-07-19T10:00:00.000Z',
            'valid_until' => '2026-07-19T11:00:00.000Z',
            'relationship_capabilities' => [
                [
                    'relationship_capability_id' => self::CAP_THREE_ID,
                    'module_code' => 'identity',
                    'capability_code' => 'accounts.read',
                ],
            ],
        ], $facts[1]);
    }

    public function test_supervisory_facts_keep_capability_less_relationships_and_empty_unknown_sources(): void
    {
        $relationships = new DatabaseGetActiveSupervisoryRelationships;

        $facts = $relationships->forSourceOrganizationUnit(self::UNIT_B_ID);

        $this->assertCount(1, $facts);
        $this->assertSame(self::REL_REVERSE_ID, $facts[0]['supervisory_relationship_id']);
        $this->assertSame('read_only', $facts[0]['relationship_type']);
        $this->assertSame([], $facts[0]['relationship_capabilities']);
        $this->assertSame([], $relationships->forSourceOrganizationUnit('018f6f7d-0c00-7000-8000-000000000999'));
    }

    private function seedOrganizationTree(): void
    {
        DB::table('clusters')->insert([
            'id' => self::CLUSTER_ID,
            'code' => 'SCOPE',
            'name_ar' => 'تجمع الاختبار',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('facility_types')->insert([
            'id' => self::FACILITY_TYPE_ID,
            'code' => 'scope_test_facility',
            'name_ar' => 'منشأة اختبار',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('unit_types')->insert([
            'id' => self::UNIT_TYPE_ID,
            'code' => 'scope_test_unit',
            'name_ar' => 'وحدة اختبار',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('facilities')->insert([
            'id' => self::FACILITY_ID,
            'cluster_id' => self::CLUSTER_ID,
            'facility_type_id' => self::FACILITY_TYPE_ID,
            'code' => 'SCOPE-FACILITY',
            'name_ar' => 'منشأة الاختبار',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $facilityPath = '/'.self::CLUSTER_ID.'/'.self::FACILITY_ID;
        $this->seedUnit(self::UNIT_A_ID, 'SCOPE-UNIT-A', self::FACILITY_ID, 'facility', $facilityPath.'/'.self::UNIT_A_ID, 2);
        $this->seedUnit(self::UNIT_B_ID, 'SCOPE-UNIT-B', self::UNIT_A_ID, 'unit', $facilityPath.'/'.self::UNIT_A_ID.'/'.self::UNIT_B_ID, 3);
        $this->seedUnit(self::UNIT_C_ID, 'SCOPE-UNIT-C', self::CLUSTER_ID, 'cluster', '/'.self::CLUSTER_ID.'/'.self::UNIT_C_ID, 1);
        $this->seedUnit(self::UNIT_D_ID, 'SCOPE-UNIT-D', self::FACILITY_ID, 'facility', $facilityPath.'/'.self::UNIT_D_ID, 2);
        $this->seedUnit(self::UNIT_E_ID, 'SCOPE-UNIT-E', self::FACILITY_ID, 'facility', $facilityPath.'/'.self::UNIT_E_ID, 2);
        foreach ([self::POSITION_A_ID => self::UNIT_A_ID, self::POSITION_B_ID => self::UNIT_B_ID, self::POSITION_D_ID => self::UNIT_D_ID] as $positionId => $unitId) {
            DB::table('positions')->insert([
                'id' => $positionId,
                'organization_unit_id' => $unitId,
                'code' => 'SCOPE-POS',
                'title_ar' => 'منصب اختبار',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach ([self::PERSON_ID => 'SCOPE-EMP-001', self::SECOND_PERSON_ID => 'SCOPE-EMP-002'] as $personId => $employeeNumber) {
            DB::table('people')->insert([
                'id' => $personId,
                'employee_number' => $employeeNumber,
                'display_name_ar' => 'موظف اختبار',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedWorkforceAssignments(): void
    {
        foreach ([
            [self::ASSIGNMENT_PRIMARY_ID, self::PERSON_ID, self::POSITION_A_ID, $this->databaseAt('-1 day'), null, true],
            [self::ASSIGNMENT_SECONDARY_ID, self::PERSON_ID, self::POSITION_B_ID, $this->databaseAt('-1 day'), null, false],
            [self::ASSIGNMENT_ENDED_ID, self::PERSON_ID, self::POSITION_D_ID, $this->databaseAt('-2 days'), $this->databaseAt('-1 hour'), false],
        ] as [$id, $personId, $positionId, $startAt, $endAt, $isPrimary]) {
            DB::table('assignments')->insert([
                'id' => $id,
                'person_id' => $personId,
                'position_id' => $positionId,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'is_primary' => $isPrimary,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach ([
            [self::TEMP_ACTIVE_ID, self::PERSON_ID, self::UNIT_C_ID, 'active', $this->databaseAt('-1 hour'), $this->databaseAt('+1 day')],
            [self::TEMP_EXPIRED_ID, self::PERSON_ID, self::UNIT_E_ID, 'expired', $this->databaseAt('-2 days'), $this->databaseAt('-1 day')],
            [self::TEMP_SECOND_PERSON_ID, self::SECOND_PERSON_ID, self::UNIT_D_ID, 'active', $this->databaseAt('-1 hour'), $this->databaseAt('+1 day')],
        ] as [$id, $personId, $unitId, $state, $startAt, $endAt]) {
            $this->seedTemporaryAssignment($id, $personId, $unitId, $state, $startAt, $endAt);
        }
        $this->seedTemporaryAssignment(
            self::TEMP_REVOKED_ID,
            self::PERSON_ID,
            self::UNIT_E_ID,
            'revoked',
            $this->databaseAt('-1 hour'),
            $this->databaseAt('+1 day'),
            [
                'revoked_at' => $this->databaseAt('-30 minutes'),
                'revoked_by_user_id' => self::ACTOR_ID,
                'revocation_reason' => 'إلغاء اختبار',
            ],
        );
        foreach ([self::TEMP_ACTIVE_ID, self::TEMP_SECOND_PERSON_ID] as $temporaryId) {
            DB::table('temporary_assignment_capabilities')->insert([
                'temporary_assignment_id' => $temporaryId,
                'capability_code' => 'records.read',
            ]);
        }
    }

    private function seedSupervisoryRelationships(): void
    {
        foreach ([
            [self::REL_ACTIVE_ID, self::UNIT_A_ID, self::UNIT_B_ID, 'direct', $this->databaseAt('-1 hour'), $this->databaseAt('+1 hour')],
            [self::REL_FUTURE_ID, self::UNIT_A_ID, self::UNIT_B_ID, 'coordination', $this->databaseAt('+1 day'), $this->databaseAt('+2 days')],
            [self::REL_EXPIRED_ID, self::UNIT_A_ID, self::UNIT_B_ID, 'direct', $this->databaseAt('-2 days'), $this->databaseAt('-1 day')],
            [self::REL_REVERSE_ID, self::UNIT_B_ID, self::UNIT_A_ID, 'read_only', $this->databaseAt('-1 hour'), $this->databaseAt('+1 hour')],
            [self::REL_ENDING_NOW_ID, self::UNIT_A_ID, self::UNIT_B_ID, 'direct', $this->databaseAt('-1 hour'), $this->databaseAt('now')],
            [self::REL_STARTING_NOW_ID, self::UNIT_A_ID, self::UNIT_B_ID, 'functional', $this->databaseAt('now'), $this->databaseAt('+1 hour')],
        ] as [$id, $sourceId, $targetId, $type, $validFrom, $validUntil]) {
            DB::table('supervisory_relationships')->insert([
                'id' => $id,
                'source_organization_unit_id' => $sourceId,
                'target_organization_unit_id' => $targetId,
                'relationship_type' => $type,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach ([
            [self::CAP_ONE_ID, self::REL_ACTIVE_ID, 'authorization', 'grant'],
            [self::CAP_TWO_ID, self::REL_ACTIVE_ID, 'work-records', 'view_details'],
            [self::CAP_THREE_ID, self::REL_STARTING_NOW_ID, 'identity', 'accounts.read'],
        ] as [$id, $relationshipId, $moduleCode, $capabilityCode]) {
            DB::table('relationship_capabilities')->insert([
                'id' => $id,
                'supervisory_relationship_id' => $relationshipId,
                'module_code' => $moduleCode,
                'capability_code' => $capabilityCode,
            ]);
        }
    }

    private function seedUnit(string $unitId, string $code, string $parentId, string $parentType, string $path, int $depth): void
    {
        DB::table('organization_units')->insert([
            'id' => $unitId,
            'cluster_id' => self::CLUSTER_ID,
            'parent_id' => $parentId,
            'parent_type' => $parentType,
            'unit_type_id' => self::UNIT_TYPE_ID,
            'code' => $code,
            'name_ar' => 'وحدة الاختبار',
            'status' => 'active',
            'path_cache' => $path,
            'depth' => $depth,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array{revoked_at: string, revoked_by_user_id: string, revocation_reason: string}|array<empty, empty> $revocation */
    private function seedTemporaryAssignment(
        string $id,
        string $personId,
        string $unitId,
        string $state,
        string $startAt,
        string $endAt,
        array $revocation = [],
    ): void {
        DB::table('temporary_assignments')->insert([
            'id' => $id,
            'person_id' => $personId,
            'organization_unit_id' => $unitId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'state' => $state,
            'reason' => 'سبب اختبار',
            'approved_by_user_id' => self::ACTOR_ID,
            'revoked_at' => $revocation['revoked_at'] ?? null,
            'revoked_by_user_id' => $revocation['revoked_by_user_id'] ?? null,
            'revocation_reason' => $revocation['revocation_reason'] ?? null,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function databaseAt(string $modify): string
    {
        return CarbonImmutable::now('UTC')->modify($modify)->format('Y-m-d H:i:s.v');
    }
}
