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

        $run = (new RunAuthorizedReportHandler($decider))->handle($reportId, $actor);
        $export = (new ExportAuthorizedReportHandler($decider))->handle($reportId, $actor);
        $download = (new DownloadExportArtifactHandler($decider))->handle($export['id'], $actor);
        $dashboard = (new GetAuthorizedDashboardHandler($decider))->handle($dashboardId, $actor);

        $this->assertSame(1, $run['total']);
        $this->assertSame(1, $export['total']);
        $this->assertNotNull($download);
        $this->assertSame(1, $download['total']);
        $this->assertSame(1, $dashboard['total']);
        foreach ([$run, $export, $download, $dashboard] as $result) {
            $this->assertArrayHasKey('allowed_actions', $result['items'][0]);
            $this->assertArrayHasKey('field_access', $result['items'][0]);
            $this->assertArrayHasKey('decision_id', $result['items'][0]);
        }
        $this->assertEqualsCanonicalizing(
            ['reporting.run', 'reporting.export', 'reporting.download', 'reporting.dashboard'],
            array_values(array_unique($decider->capabilities)),
        );
    }

    /** @return array<string, mixed> */
    private function event(string $reportId, string $scope): array
    {
        return ['report_id' => $reportId, 'source_module' => 'WorkRecords', 'source_type' => 'work_record', 'source_id' => 'record-'.$scope, 'source_version' => 'v1', 'scope_id' => $scope, 'title' => 'Visible', 'safe_data' => ['status' => 'open', 'secret' => 'omit']];
    }
}

final class ReportingScopeDecider implements DecideAccess
{
    /** @var list<string> */
    public array $capabilities = [];

    /**
     * Test doubles persist nothing, so the read-side evaluation IS decide().
     */
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $this->capabilities[] = $capability;
        $allowed = $facts !== null && ($actor['facility_id'] ?? null) === $facts->ownerFacilityId;

        return new AccessDecision(
            decision: $allowed ? 'allow' : 'deny',
            action: $capability,
            resourceType: $facts === null ? 'work_record' : $facts->resourceType,
            reasonCodes: [],
            policyVersion: 'test',
            factsVersion: 'test',
            classification: $facts === null ? 'internal' : $facts->classification,
            decisionId: $allowed ? 'decision-'.$capability : null,
            allowedActions: $allowed ? [$capability] : [],
        );
    }
}
