<?php

namespace Modules\Reporting\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Reporting\Features\DownloadExportArtifact\Handler\DownloadExportArtifactHandler;
use Modules\Reporting\Features\ExportAuthorizedReport\Handler\ExportAuthorizedReportHandler;
use Modules\Reporting\Features\GetAuthorizedDashboard\Handler\GetAuthorizedDashboardHandler;
use Modules\Reporting\Features\RefreshReportingProjection\Handler\RefreshReportingProjectionHandler;
use Modules\Reporting\Features\RunAuthorizedReport\Handler\RunAuthorizedReportHandler;
use Tests\TestCase;

final class ReportingProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('report_read_models')) {
            $this->artisan('migrate', ['--path' => 'Modules/Reporting/Infrastructure/Persistence/Migrations/CreateReportingProjectionTables.php', '--force' => true]);
        }
    }

    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->artisan('migrate', ['--path' => 'Modules/Reporting/Infrastructure/Persistence/Migrations/CreateReportingProjectionTables.php', '--force' => true]);
    }

    public function test_report_dashboard_export_and_download_filter_with_same_decider(): void
    {
        $reportId = '0197f0e0-0000-7000-8000-000000000001';
        $dashboardId = '0197f0e0-0000-7000-8000-000000000002';
        DB::table('dashboard_definitions')->insert(['id' => $dashboardId, 'code' => 'main', 'title' => 'Main', 'report_id' => $reportId, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        (new RefreshReportingProjectionHandler)->handle($this->event($reportId, 'scope-a'));
        (new RefreshReportingProjectionHandler)->handle($this->event($reportId, 'scope-b'));
        $actor = ['user_id' => 'u', 'facility_id' => 'scope-a'];
        $decider = new ReportingScopeDecider;

        $this->assertSame(1, (new RunAuthorizedReportHandler($decider))->handle($reportId, $actor)['total']);
        $export = (new ExportAuthorizedReportHandler($decider))->handle($reportId, $actor);
        $this->assertSame(1, $export['total']);
        $this->assertSame(1, (new DownloadExportArtifactHandler($decider))->handle($export['id'], $actor)['total']);
        $this->assertSame(1, (new GetAuthorizedDashboardHandler($decider))->handle($dashboardId, $actor)['total']);
    }

    /** @return array<string, mixed> */
    private function event(string $reportId, string $scope): array
    {
        return ['report_id' => $reportId, 'source_module' => 'WorkRecords', 'source_type' => 'work_record', 'source_id' => 'record-'.$scope, 'source_version' => 'v1', 'scope_id' => $scope, 'title' => 'Visible', 'safe_data' => ['status' => 'open', 'secret' => 'omit']];
    }
}

final class ReportingScopeDecider implements DecideAccess
{
    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $allowed = $facts !== null && ($actor['facility_id'] ?? null) === $facts->ownerFacilityId;

        return new AccessDecision($allowed ? 'allow' : 'deny', $capability, $facts === null ? 'work_record' : $facts->resourceType, [], 'test', 'test', $facts === null ? 'internal' : $facts->classification);
    }
}
