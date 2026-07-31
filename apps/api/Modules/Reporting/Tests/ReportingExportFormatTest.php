<?php

namespace Modules\Reporting\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Reporting\Features\DownloadExportArtifact\Handler\DownloadExportArtifactHandler;
use Modules\Reporting\Features\ExportAuthorizedReport\Handler\ExportAuthorizedReportHandler;
use Modules\Reporting\Features\ExportAuthorizedReport\Handler\UnsupportedExportFormatException;
use Modules\Reporting\Features\Exports\Http\CreateReportExportController;
use Modules\Reporting\Features\Exports\Http\DownloadExportController;
use Modules\Reporting\Features\RefreshReportingProjection\Handler\RefreshReportingProjectionHandler;
use Modules\Reporting\Infrastructure\Export\CsvExportEncoder;
use Tests\TestCase;

final class ReportingExportFormatTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT = '0197f0e0-0000-7000-8000-000000000010';

    private const CORRELATION = '0197f0e0-0000-7000-8000-000000000012';

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('report_read_models')) {
            $this->artisan('migrate', ['--path' => 'Modules/Reporting/Infrastructure/Persistence/Migrations/CreateReportingProjectionTables.php', '--force' => true]);
        }
        if (! Schema::hasColumn('report_runs', 'idempotency_key_hash')) {
            $this->artisan('migrate', ['--path' => 'Modules/Reporting/Infrastructure/Persistence/Migrations/ZAddReportRunIdempotency.php', '--force' => true]);
        }
        if (! Schema::hasColumn('report_runs', 'error_message')) {
            $this->artisan('migrate', ['--path' => 'Modules/Reporting/Infrastructure/Persistence/Migrations/ZAddReportRunFailureState.php', '--force' => true]);
        }
        (new RefreshReportingProjectionHandler)->handle([
            'report_id' => self::REPORT,
            'source_module' => 'WorkRecords',
            'source_type' => 'work_record',
            'source_id' => 'record-1',
            'source_version' => 'v1',
            'scope_id' => 'scope-a',
            'title' => 'Visible',
            'safe_data' => ['status' => 'open', 'secret' => 'omit'],
        ]);
    }

    public function test_csv_export_stores_real_csv_payload_and_downloads_it_as_text_csv(): void
    {
        $actor = ['user_id' => 'user-1', 'facility_id' => 'scope-a'];
        $exports = new ExportAuthorizedReportHandler(new ReportingAllowingDecider);

        $export = $exports->handle(self::REPORT, $actor, 'csv');
        $this->assertSame('csv', $export['format']);
        $this->assertSame(1, $export['total']);

        $artifact = DB::table('export_artifacts')->where('id', $export['id'])->first();
        $expectedCsv = CsvExportEncoder::encode($export['items']);
        $this->assertSame($expectedCsv, (string) $artifact->safe_result);
        $this->assertStringStartsWith("id,source_type,source_id,title,scope_id,classification,decision_id,allowed_actions,field_access,data\r\n", $expectedCsv);
        $this->assertStringContainsString('record-1', $expectedCsv);
        $this->assertStringNotContainsString('"secret"', $expectedCsv);

        $run = DB::table('report_runs')->where('id', $artifact->report_run_id)->first();
        $this->assertSame('completed', $run->status);
        $decoded = json_decode((string) $run->result, true);
        $this->assertSame('record-1', $decoded[0]['source_id']);

        $request = Request::create('/exports/'.$export['id'], 'GET');
        $request->headers->set('X-Correlation-ID', self::CORRELATION);
        $request->headers->set('Accept', 'text/csv');
        $response = (new DownloadExportController(new ReportingPrincipalResolver, new DownloadExportArtifactHandler(new ReportingAllowingDecider)))->__invoke($request, $export['id']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="export-', $response->headers->get('Content-Disposition'));
        $this->assertSame($expectedCsv, $response->getContent());
    }

    public function test_json_export_stores_json_payload_and_default_download_returns_envelope_for_polling(): void
    {
        $actor = ['user_id' => 'user-1', 'facility_id' => 'scope-a'];
        $exports = new ExportAuthorizedReportHandler(new ReportingAllowingDecider);

        $export = $exports->handle(self::REPORT, $actor, 'json');
        $this->assertSame('json', $export['format']);

        $artifact = DB::table('export_artifacts')->where('id', $export['id'])->first();
        $this->assertSame(json_encode($export['items'], JSON_THROW_ON_ERROR), (string) $artifact->safe_result);

        $request = Request::create('/exports/'.$export['id'], 'GET');
        $request->headers->set('X-Correlation-ID', self::CORRELATION);
        $response = (new DownloadExportController(new ReportingPrincipalResolver, new DownloadExportArtifactHandler(new ReportingAllowingDecider)))->__invoke($request, $export['id']);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertSame($export['id'], $body['id']);
        $this->assertSame('available', $body['status']);
        $this->assertSame('json', $body['format']);
        $this->assertSame('/api/v1/exports/'.$export['id'], $body['download_url']);
        $this->assertSame(1, $body['total']);
        $this->assertSame('record-1', $body['items'][0]['source_id']);
    }

    public function test_csv_accept_on_json_artifact_returns_406(): void
    {
        $actor = ['user_id' => 'user-1', 'facility_id' => 'scope-a'];
        $exports = new ExportAuthorizedReportHandler(new ReportingAllowingDecider);
        $export = $exports->handle(self::REPORT, $actor, 'json');

        $request = Request::create('/exports/'.$export['id'], 'GET');
        $request->headers->set('X-Correlation-ID', self::CORRELATION);
        $request->headers->set('Accept', 'text/csv');
        $response = (new DownloadExportController(new ReportingPrincipalResolver, new DownloadExportArtifactHandler(new ReportingAllowingDecider)))->__invoke($request, $export['id']);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(406, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/not-acceptable', $response->getData(true)['type']);
    }

    public function test_unsupported_formats_are_rejected_with_422_problem_and_no_state_is_written(): void
    {
        $actor = ['user_id' => 'user-1', 'facility_id' => 'scope-a'];
        $exports = new ExportAuthorizedReportHandler(new ReportingAllowingDecider);

        foreach (['xlsx', 'pdf'] as $format) {
            try {
                $exports->handle(self::REPORT, $actor, $format);
                $this->fail("Expected UnsupportedExportFormatException for {$format}.");
            } catch (UnsupportedExportFormatException) {
                // expected
            }
        }

        $request = Request::create(
            '/reports/'.self::REPORT.'/exports',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['format' => 'pdf'], JSON_THROW_ON_ERROR),
        );
        $request->headers->set('X-Correlation-ID', self::CORRELATION);
        $request->headers->set('Idempotency-Key', 'report-export-pdf');
        $response = (new CreateReportExportController(new ReportingPrincipalResolver, $exports))->__invoke($request, self::REPORT);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('application/problem+json', $response->headers->get('Content-Type'));
        $this->assertSame('https://cluster.example/problems/unsupported-export-format', $response->getData(true)['type']);
        $this->assertSame(0, DB::table('report_runs')->count());
        $this->assertSame(0, DB::table('export_artifacts')->count());
    }

    public function test_download_reauthorizes_items_per_actor_and_filters_denied_items(): void
    {
        $actor = ['user_id' => 'user-1', 'facility_id' => 'scope-a'];
        $exports = new ExportAuthorizedReportHandler(new ReportingAllowingDecider);
        $export = $exports->handle(self::REPORT, $actor, 'csv');

        $request = Request::create('/exports/'.$export['id'], 'GET');
        $request->headers->set('X-Correlation-ID', self::CORRELATION);
        $response = (new DownloadExportController(new ReportingPrincipalResolver, new DownloadExportArtifactHandler(new ReportingDenyingDecider)))->__invoke($request, $export['id']);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $response->getData(true)['total']);
        $this->assertSame([], $response->getData(true)['items']);
    }

    public function test_export_replay_decodes_items_from_the_run_result_not_the_artifact(): void
    {
        $controller = new CreateReportExportController(
            new ReportingPrincipalResolver,
            new ExportAuthorizedReportHandler(new ReportingAllowingDecider),
        );
        $request = fn (string $key): Request => tap(
            Request::create(
                '/reports/'.self::REPORT.'/exports',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['format' => 'csv'], JSON_THROW_ON_ERROR),
            ),
            function (Request $request) use ($key): void {
                $request->headers->set('X-Correlation-ID', self::CORRELATION);
                $request->headers->set('Idempotency-Key', $key);
            },
        );

        $first = $controller->__invoke($request('report-export-csv-replay'), self::REPORT);
        $replay = $controller->__invoke($request('report-export-csv-replay'), self::REPORT);

        $this->assertSame(202, $first->getStatusCode());
        $this->assertSame($first->getData(true), $replay->getData(true));
        $this->assertSame(1, DB::table('report_runs')->where('idempotency_key_hash', hash('sha256', 'report-export-csv-replay'))->count());
        $this->assertSame(1, DB::table('export_artifacts')->count());
    }
}
