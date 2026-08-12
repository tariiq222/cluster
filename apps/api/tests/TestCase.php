<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\PersistAccessDecision;
use Modules\Authorization\Infrastructure\FixtureFacilityDecision;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use Modules\Organization\Infrastructure\Fixtures\DevelopmentFacilityFixtures;
use Modules\Reporting\Features\GetAuthorizedDashboard\Handler\GetAuthorizedDashboardHandler;
use Modules\Reporting\Features\RunAuthorizedReport\Handler\RunAuthorizedReportHandler;
use Modules\Search\Features\SearchAccessibleRecords\Handler\SearchAccessibleRecordsHandler;
use Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Handler\GetAuthorizedWorkRecordHandler;
use Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Handler\ListAuthorizedWorkRecordsHandler;
use Modules\WorkRecords\Features\SubmitWorkRecord\Http\SubmitWorkRecordController;

abstract class TestCase extends BaseTestCase
{
    /**
     * Legacy HTTP adapter tests exercise consumer mechanics against the
     * deterministic facility fixture; the production container binds the real
     * RBAC+ABAC engine (asserted by a dedicated production-binding test), and
     * security-behavior tests opt into it explicitly via
     * {@see bindRealAccessDecision()}.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(DecideAccess::class, FixtureFacilityDecision::class);
        // Feature-gate default in production is CLUSTER_WORK_MANAGEMENT_ENABLED=false.
        // Legacy and security tests exercise the full work-management surface
        // (workflow/requests/approvals); opt in here. Gate-edge tests
        // (WorkManagementFeatureGateTest) flip this back to false explicitly.
        config()->set('features.work_management', true);
    }

    protected function bindRealAccessDecision(): void
    {
        $factory = fn ($app): DecideAccess => new RbacAbacDecideAccess(
            $app->make(GetActiveSupervisoryRelationships::class),
            $app->make(PersistAccessDecision::class),
        );
        $this->app->bind(DecideAccess::class, $factory);
        $this->app->when([
            GetAuthorizedWorkRecordHandler::class,
            ListAuthorizedWorkRecordsHandler::class,
            SubmitWorkRecordController::class,
            SearchAccessibleRecordsHandler::class,
            RunAuthorizedReportHandler::class,
            GetAuthorizedDashboardHandler::class,
        ])->needs(DecideAccess::class)->give($factory);
        $this->app->forgetInstance(DecideAccess::class);
        $this->app->forgetInstance(GetAuthorizedWorkRecordHandler::class);
        $this->app->forgetInstance(ListAuthorizedWorkRecordsHandler::class);
    }

    protected function assignPersonToFixtureOrganization(
        string $personId,
        string $positionId = DevelopmentFacilityFixtures::POSITION_A_ID,
    ): void {
        $this->seedFixtureOrganization();
        DB::table('assignments')->insert([
            'id' => (string) Str::uuid7(),
            'person_id' => $personId,
            'position_id' => $positionId,
            'start_at' => now()->subMinute(),
            'end_at' => null,
            'is_primary' => true,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function seedFixtureOrganization(): void
    {
        $now = now();
        DB::table('clusters')->insertOrIgnore([
            'id' => DevelopmentFacilityFixtures::CLUSTER_ID,
            'singleton_key' => 1,
            'code' => 'development-cluster',
            'name_ar' => 'تجمع التطوير',
            'name_en' => 'Development Cluster',
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $facilityTypeId = DB::table('facility_types')->where('code', 'hospital')->value('id');
        $unitTypeId = DB::table('unit_types')->where('code', 'department')->value('id');
        if (! is_string($facilityTypeId) || ! is_string($unitTypeId)) {
            throw new \LogicException('fixture_organization_types_missing');
        }
        foreach (DevelopmentFacilityFixtures::facilities() as $facility) {
            DB::table('facilities')->insertOrIgnore([
                'id' => $facility['id'],
                'cluster_id' => DevelopmentFacilityFixtures::CLUSTER_ID,
                'facility_type_id' => $facilityTypeId,
                'code' => $facility['code'],
                'name_ar' => $facility['name'],
                'name_en' => $facility['name'],
                'status' => 'active',
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            [DevelopmentFacilityFixtures::FACILITY_A_ID, DevelopmentFacilityFixtures::UNIT_A_ID, DevelopmentFacilityFixtures::POSITION_A_ID, 'A'],
            [DevelopmentFacilityFixtures::FACILITY_B_ID, DevelopmentFacilityFixtures::UNIT_B_ID, DevelopmentFacilityFixtures::POSITION_B_ID, 'B'],
        ] as [$facilityId, $unitId, $fixturePositionId, $suffix]) {
            DB::table('organization_units')->insertOrIgnore([
                'id' => $unitId,
                'cluster_id' => DevelopmentFacilityFixtures::CLUSTER_ID,
                'parent_id' => $facilityId,
                'parent_type' => 'facility',
                'unit_type_id' => $unitTypeId,
                'code' => 'fixture-unit-'.strtolower($suffix),
                'name_ar' => 'وحدة الاختبار '.$suffix,
                'name_en' => 'Fixture Unit '.$suffix,
                'status' => 'active',
                'path_cache' => '/'.DevelopmentFacilityFixtures::CLUSTER_ID.'/'.$facilityId.'/'.$unitId,
                'depth' => 2,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('positions')->insertOrIgnore([
                'id' => $fixturePositionId,
                'organization_unit_id' => $unitId,
                'code' => 'FIXTURE-POSITION-'.$suffix,
                'title_ar' => 'موظف اختبار '.$suffix,
                'is_active' => true,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
