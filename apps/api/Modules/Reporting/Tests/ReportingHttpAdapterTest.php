<?php

namespace Modules\Reporting\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Reporting\Features\DownloadExportArtifact\Handler\DownloadExportArtifactHandler;
use Modules\Reporting\Features\ExportAuthorizedReport\Handler\ExportAuthorizedReportHandler;
use Modules\Reporting\Features\GetAuthorizedDashboard\Handler\GetAuthorizedDashboardHandler;
use Modules\Reporting\Features\RefreshReportingProjection\Handler\RefreshReportingProjectionHandler;
use Modules\Reporting\Features\RunAuthorizedReport\Handler\RunAuthorizedReportHandler;
use Modules\Reporting\Http\CreateReportExportController;
use Modules\Reporting\Http\DownloadExportController;
use Modules\Reporting\Http\GetDashboardController;
use Modules\Reporting\Http\GetReportController;
use Tests\TestCase;

final class ReportingHttpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT = '0197f0e0-0000-7000-8000-000000000010';

    private const DASHBOARD = '0197f0e0-0000-7000-8000-000000000011';

    private const CORRELATION = '0197f0e0-0000-7000-8000-000000000012';

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('report_read_models')) {
            $this->artisan('migrate', ['--path' => 'Modules/Reporting/Infrastructure/Persistence/Migrations/CreateReportingProjectionTables.php', '--force' => true]);
        }
        DB::table('dashboard_definitions')->insert(['id' => self::DASHBOARD, 'code' => 'main', 'title' => 'Main', 'report_id' => self::REPORT, 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        (new RefreshReportingProjectionHandler)->handle(['report_id' => self::REPORT, 'source_module' => 'WorkRecords', 'source_type' => 'work_record', 'source_id' => 'record-1', 'source_version' => 'v1', 'scope_id' => 'scope-a', 'title' => 'Visible', 'safe_data' => ['status' => 'open']]);
    }

    public function test_get_report_post_export_get_download_and_get_dashboard_adapters(): void
    {
        $resolver = new ReportingPrincipalResolver;
        $decider = new ReportingAllowingDecider;
        $get = fn (string $uri): Request => tap(Request::create($uri, 'GET'), fn (Request $r) => $r->headers->set('X-Correlation-ID', self::CORRELATION));
        $report = (new GetReportController($resolver, new RunAuthorizedReportHandler($decider)))->__invoke($get('/reports/'.self::REPORT), self::REPORT);
        $this->assertSame(200, $report->getStatusCode());

        $post = Request::create('/reports/'.self::REPORT.'/exports', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['format' => 'csv'], JSON_THROW_ON_ERROR));
        $post->headers->set('X-Correlation-ID', self::CORRELATION);
        $export = (new CreateReportExportController($resolver, new ExportAuthorizedReportHandler($decider)))->__invoke($post, self::REPORT);
        $this->assertSame(202, $export->getStatusCode());
        $exportId = $export->getData(true)['id'];

        $download = (new DownloadExportController($resolver, new DownloadExportArtifactHandler($decider)))->__invoke($get('/exports/'.$exportId), $exportId);
        $this->assertSame(200, $download->getStatusCode());
        $dashboard = (new GetDashboardController($resolver, new GetAuthorizedDashboardHandler($decider)))->__invoke($get('/dashboards/'.self::DASHBOARD), self::DASHBOARD);
        $this->assertSame(200, $dashboard->getStatusCode());
        $this->assertSame(1, $dashboard->getData(true)['total']);
    }
}

final class ReportingPrincipalResolver implements ResolveDevelopmentFixturePrincipal
{
    public function issue(array $principal): array
    {
        return ['access_token' => 'test', 'expires_at' => now()->addHour()->toIso8601String()];
    }

    public function resolve(Request $request): ?array
    {
        return $request->header('Authorization') === 'missing' ? null : ['user_id' => 'user-1', 'facility_id' => 'scope-a'];
    }
}

final class ReportingAllowingDecider implements DecideAccess
{
    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return new AccessDecision('allow', $capability, $facts === null ? 'work_record' : $facts->resourceType, [], 'test', 'test', $facts === null ? 'internal' : $facts->classification);
    }
}
