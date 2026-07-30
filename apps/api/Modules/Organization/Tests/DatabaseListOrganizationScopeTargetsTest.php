<?php

namespace Modules\Organization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Organization\Infrastructure\Persistence\DatabaseListOrganizationScopeTargets;
use Tests\TestCase;

class DatabaseListOrganizationScopeTargetsTest extends TestCase
{
    use RefreshDatabase;

    private string $clusterId;

    private string $nullNameEnClusterId;

    private string $facilityTypeId;

    private string $unitTypeId;

    private string $facilityId;

    private string $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clusterId = $this->insertClusterWithEnglish();
        $this->nullNameEnClusterId = $this->insertClusterWithNullEnglish();
        $this->facilityTypeId = $this->insertLookup('facility_types', 'hospital');
        $this->unitTypeId = $this->insertLookup('unit_types', 'sector');
        $this->facilityId = $this->insertFacility($this->facilityTypeId, 'FAC-MAIN');
        $this->unitId = $this->insertUnit('U-MAIN', 'facility', $this->facilityId);
    }

    public function test_returns_labels_for_mixed_candidates_keyed_by_original_index(): void
    {
        $missing = (string) Str::uuid7();

        $candidates = [
            ['scope_type' => 'cluster', 'scope_id' => $this->clusterId],
            ['scope_type' => 'facility', 'scope_id' => $missing],
            ['scope_type' => 'facility', 'scope_id' => $this->facilityId],
            ['scope_type' => 'unit', 'scope_id' => $this->unitId],
        ];

        $results = (new DatabaseListOrganizationScopeTargets)->labelCandidates('cluster', $candidates, null);

        $this->assertSame([0, 2, 3], array_keys($results), 'Missing-DB candidate index must be omitted and order preserved.');
        $this->assertSame('cluster', $results[0]['scope_type']);
        $this->assertSame($this->clusterId, $results[0]['scope_id']);
        $this->assertSame('تجمع اختبار النطاق', $results[0]['label_ar']);
        $this->assertSame('THC Main Cluster', $results[0]['label_en']);
        $this->assertSame('THC-MAIN', $results[0]['code']);

        $this->assertSame('facility', $results[2]['scope_type']);
        $this->assertSame($this->facilityId, $results[2]['scope_id']);
        $this->assertSame('منشأة اختبار FAC-MAIN', $results[2]['label_ar']);
        $this->assertSame('Main Facility', $results[2]['label_en']);
        $this->assertSame('FAC-MAIN', $results[2]['code']);

        $this->assertSame('unit', $results[3]['scope_type']);
        $this->assertSame($this->unitId, $results[3]['scope_id']);
        $this->assertSame('وحدة اختبار U-MAIN', $results[3]['label_ar']);
        $this->assertSame('Main Unit', $results[3]['label_en']);
        $this->assertSame('U-MAIN', $results[3]['code']);
    }

    public function test_empty_search_returns_every_existing_candidate(): void
    {
        $candidates = [
            ['scope_type' => 'cluster', 'scope_id' => $this->clusterId],
            ['scope_type' => 'facility', 'scope_id' => $this->facilityId],
            ['scope_type' => 'unit', 'scope_id' => $this->unitId],
        ];

        $results = (new DatabaseListOrganizationScopeTargets)->labelCandidates('cluster', $candidates, null);

        $this->assertCount(3, $results);
    }

    public function test_search_filters_by_arabic_label(): void
    {
        $other = $this->insertFacility($this->facilityTypeId, 'FAC-OTHER', 'Other Facility', 'منشأة أخرى');

        $candidates = [
            ['scope_type' => 'facility', 'scope_id' => $this->facilityId],
            ['scope_type' => 'facility', 'scope_id' => $other],
        ];

        $results = (new DatabaseListOrganizationScopeTargets)->labelCandidates('cluster', $candidates, 'منشأة اختبار');

        $this->assertSame([0], array_keys($results));
        $this->assertSame($this->facilityId, $results[0]['scope_id']);
    }

    public function test_search_filters_by_english_label(): void
    {
        $other = $this->insertFacility($this->facilityTypeId, 'FAC-OTHER', 'Other Facility', 'منشأة أخرى');

        $candidates = [
            ['scope_type' => 'facility', 'scope_id' => $this->facilityId],
            ['scope_type' => 'facility', 'scope_id' => $other],
        ];

        $results = (new DatabaseListOrganizationScopeTargets)->labelCandidates('cluster', $candidates, 'Main Facility');

        $this->assertSame([0], array_keys($results));
    }

    public function test_search_filters_by_code(): void
    {
        $other = $this->insertFacility($this->facilityTypeId, 'FAC-OTHER', 'Other Facility', 'منشأة أخرى');

        $candidates = [
            ['scope_type' => 'facility', 'scope_id' => $this->facilityId],
            ['scope_type' => 'facility', 'scope_id' => $other],
        ];

        $results = (new DatabaseListOrganizationScopeTargets)->labelCandidates('cluster', $candidates, 'FAC-MAIN');

        $this->assertSame([0], array_keys($results));
    }

    public function test_search_with_no_matches_returns_empty_results(): void
    {
        $candidates = [
            ['scope_type' => 'facility', 'scope_id' => $this->facilityId],
            ['scope_type' => 'unit', 'scope_id' => $this->unitId],
        ];

        $results = (new DatabaseListOrganizationScopeTargets)->labelCandidates('cluster', $candidates, 'never-matches-anything');

        $this->assertSame([], $results);
    }

    public function test_blank_search_returns_all_existing_candidates(): void
    {
        $candidates = [
            ['scope_type' => 'facility', 'scope_id' => $this->facilityId],
            ['scope_type' => 'unit', 'scope_id' => $this->unitId],
        ];

        $results = (new DatabaseListOrganizationScopeTargets)->labelCandidates('cluster', $candidates, '   ');

        $this->assertCount(2, $results);
    }

    public function test_null_name_en_falls_back_to_arabic_label(): void
    {
        // Regression: OpenAPI AssignmentScopeTarget requires `label_en` with
        // minLength: 1. The previous implementation emitted '' and violated
        // the contract when the row's name_en was null. The Organization
        // contract must prefer a non-blank Arabic label as the English
        // fallback so the wire shape always satisfies minLength.
        $candidates = [
            ['scope_type' => 'cluster', 'scope_id' => $this->nullNameEnClusterId],
        ];

        $results = (new DatabaseListOrganizationScopeTargets)->labelCandidates('cluster', $candidates, null);

        $this->assertSame('تجمع بلا إنجليزي', $results[0]['label_en']);
        $this->assertSame('تجمع بلا إنجليزي', $results[0]['label_ar']);
        $this->assertArrayHasKey('code', $results[0]);
    }

    public function test_every_label_blank_falls_back_to_stable_code(): void
    {
        // Regression: when both name_en and name_ar are blank, the contract
        // falls back to the stable `code` so the wire shape never emits an
        // empty label_en (OpenAPI minLength: 1).
        $id = (string) Str::uuid7();
        DB::table('clusters')->insert([
            'id' => $id,
            'singleton_key' => 9,
            'code' => 'THC-CODEONLY',
            'name_ar' => '',
            'name_en' => null,
            'status' => 'active',
            'lock_version' => 1,
        ]);

        $candidates = [
            ['scope_type' => 'cluster', 'scope_id' => $id],
        ];

        $results = (new DatabaseListOrganizationScopeTargets)->labelCandidates('cluster', $candidates, null);

        $this->assertNotSame('', $results[0]['label_en']);
        $this->assertNotSame('', $results[0]['label_ar']);
    }

    public function test_null_name_en_emits_no_empty_label_en_string(): void
    {
        // Regression: every label_en emitted for the catalog must be a
        // non-empty string. The previous implementation cast a null name_en
        // to '' which violated the OpenAPI minLength: 1 contract on
        // AssignmentScopeTarget.label_en.
        $candidates = [
            ['scope_type' => 'cluster', 'scope_id' => $this->nullNameEnClusterId],
        ];

        $results = (new DatabaseListOrganizationScopeTargets)->labelCandidates('cluster', $candidates, null);

        $this->assertNotSame('', $results[0]['label_en']);
        $this->assertNotSame('', $results[0]['label_ar']);
    }

    public function test_every_candidate_missing_returns_empty_array(): void
    {
        $candidates = [
            ['scope_type' => 'cluster', 'scope_id' => (string) Str::uuid7()],
            ['scope_type' => 'facility', 'scope_id' => (string) Str::uuid7()],
            ['scope_type' => 'unit', 'scope_id' => (string) Str::uuid7()],
        ];

        $results = (new DatabaseListOrganizationScopeTargets)->labelCandidates('cluster', $candidates, null);

        $this->assertSame([], $results);
    }

    private function insertClusterWithEnglish(): string
    {
        $id = (string) Str::uuid7();
        DB::table('clusters')->insert([
            'id' => $id,
            'singleton_key' => 1,
            'code' => 'THC-MAIN',
            'name_ar' => 'تجمع اختبار النطاق',
            'name_en' => 'THC Main Cluster',
            'status' => 'active',
            'lock_version' => 1,
        ]);

        return $id;
    }

    private function insertClusterWithNullEnglish(): string
    {
        $id = (string) Str::uuid7();
        DB::table('clusters')->insert([
            'id' => $id,
            'singleton_key' => 2,
            'code' => 'THC-NULLEN',
            'name_ar' => 'تجمع بلا إنجليزي',
            'name_en' => null,
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

    private function insertFacility(
        string $facilityTypeId,
        string $code,
        ?string $nameEn = null,
        ?string $nameAr = null,
    ): string {
        $id = (string) Str::uuid7();
        DB::table('facilities')->insert([
            'id' => $id,
            'cluster_id' => $this->clusterId,
            'facility_type_id' => $facilityTypeId,
            'code' => $code,
            'name_ar' => $nameAr ?? 'منشأة اختبار '.$code,
            'name_en' => $nameEn ?? 'Main Facility',
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
            'name_en' => 'Main Unit',
            'status' => 'active',
            'path_cache' => '/'.$id,
            'depth' => 1,
            'lock_version' => 1,
        ]);

        return $id;
    }
}
