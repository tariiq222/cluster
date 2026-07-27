<?php

namespace Modules\Organization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Organization\Infrastructure\Persistence\DatabaseResolveScopeDescendants;
use Tests\TestCase;

class DatabaseResolveScopeDescendantsTest extends TestCase
{
    use RefreshDatabase;

    private string $clusterId;

    private string $facilityOneId;

    private string $facilityTwoId;

    private string $unitTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clusterId = $this->insertCluster();
        $facilityTypeId = $this->insertLookup('facility_types', 'hospital');
        $this->unitTypeId = $this->insertLookup('unit_types', 'sector');
        $this->facilityOneId = $this->insertFacility($facilityTypeId, 'FAC-ONE');
        $this->facilityTwoId = $this->insertFacility($facilityTypeId, 'FAC-TWO');
    }

    public function test_cluster_scope_lists_every_facility_and_unit_in_the_cluster(): void
    {
        $unitOne = $this->insertUnit('U-ONE', 'facility', $this->facilityOneId);
        $unitTwo = $this->insertUnit('U-TWO', 'unit', $unitOne);
        $unitThree = $this->insertUnit('U-THREE', 'cluster', $this->clusterId);

        $descendants = (new DatabaseResolveScopeDescendants)->descendants('cluster', $this->clusterId);

        $this->assertEqualsCanonicalizing(
            [
                ['scope_type' => 'facility', 'scope_id' => $this->facilityOneId],
                ['scope_type' => 'facility', 'scope_id' => $this->facilityTwoId],
                ['scope_type' => 'unit', 'scope_id' => $unitOne],
                ['scope_type' => 'unit', 'scope_id' => $unitTwo],
                ['scope_type' => 'unit', 'scope_id' => $unitThree],
            ],
            $descendants,
        );
    }

    public function test_facility_scope_walks_the_whole_unit_subtree_in_breadth_first_order(): void
    {
        $unitOne = $this->insertUnit('U-ONE', 'facility', $this->facilityOneId);
        $unitTwo = $this->insertUnit('U-TWO', 'unit', $unitOne);
        $unitThree = $this->insertUnit('U-THREE', 'unit', $unitTwo);
        $this->insertUnit('U-OTHER', 'facility', $this->facilityTwoId);
        $this->insertUnit('U-ROOT', 'cluster', $this->clusterId);

        $descendants = (new DatabaseResolveScopeDescendants)->descendants('facility', $this->facilityOneId);

        $this->assertSame(
            [
                ['scope_type' => 'unit', 'scope_id' => $unitOne],
                ['scope_type' => 'unit', 'scope_id' => $unitTwo],
                ['scope_type' => 'unit', 'scope_id' => $unitThree],
            ],
            $descendants,
        );
    }

    public function test_facility_scope_ignores_units_reachable_only_through_another_facility(): void
    {
        $otherRoot = $this->insertUnit('U-OTHER', 'facility', $this->facilityTwoId);
        $this->insertUnit('U-OTHER-CHILD', 'unit', $otherRoot);

        $descendants = (new DatabaseResolveScopeDescendants)->descendants('facility', $this->facilityOneId);

        $this->assertSame([], $descendants);
    }

    public function test_facility_scope_terminates_and_ignores_a_corrupt_unit_parent_cycle_elsewhere(): void
    {
        $unitOne = $this->insertUnit('U-ONE', 'facility', $this->facilityOneId);
        // A single-parent tree cannot attach a reachable cycle below a
        // facility: closing the loop detaches the cycle from the tree. The
        // corrupt rows still sit in the table, and the walk must terminate
        // without including them.
        $cycleA = $this->insertUnit('U-CYCLE-A', 'unit', $unitOne);
        $cycleB = $this->insertUnit('U-CYCLE-B', 'unit', $cycleA);
        DB::table('organization_units')->where('id', $cycleA)->update(['parent_id' => $cycleB]);

        $descendants = (new DatabaseResolveScopeDescendants)->descendants('facility', $this->facilityOneId);

        $this->assertSame(
            [
                ['scope_type' => 'unit', 'scope_id' => $unitOne],
            ],
            $descendants,
        );
    }

    public function test_unknown_scope_returns_an_empty_list(): void
    {
        $this->assertSame([], (new DatabaseResolveScopeDescendants)->descendants('facility', (string) Str::uuid7()));
    }

    private function insertCluster(): string
    {
        $id = (string) Str::uuid7();
        DB::table('clusters')->insert([
            'id' => $id,
            'code' => 'THC-'.Str::upper(Str::random(6)),
            'name_ar' => 'تجمع اختبار النطاق',
            'status' => 'active',
            'lock_version' => 1,
        ]);

        return $id;
    }

    private function insertLookup(string $table, string $code): string
    {
        $id = (string) Str::uuid7();
        DB::table($table)->insert([
            'id' => $id,
            'code' => $code.'-'.Str::upper(Str::random(4)),
            'name_ar' => 'نوع اختبار',
            'is_active' => true,
        ]);

        return $id;
    }

    private function insertFacility(string $facilityTypeId, string $code): string
    {
        $id = (string) Str::uuid7();
        DB::table('facilities')->insert([
            'id' => $id,
            'cluster_id' => $this->clusterId,
            'facility_type_id' => $facilityTypeId,
            'code' => $code,
            'name_ar' => 'منشأة اختبار '.$code,
            'status' => 'active',
            'lock_version' => 1,
        ]);

        return $id;
    }

    private function insertUnit(string $code, string $parentType, string $parentId): string
    {
        $id = (string) Str::uuid7();
        DB::table('organization_units')->insert([
            'id' => $id,
            'cluster_id' => $this->clusterId,
            'parent_id' => $parentId,
            'parent_type' => $parentType,
            'unit_type_id' => $this->unitTypeId,
            'code' => $code,
            'name_ar' => 'وحدة اختبار '.$code,
            'status' => 'active',
            'path_cache' => '/'.$id,
            'depth' => 1,
            'lock_version' => 1,
        ]);

        return $id;
    }
}
