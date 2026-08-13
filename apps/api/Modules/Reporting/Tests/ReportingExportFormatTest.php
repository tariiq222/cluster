<?php

namespace Modules\Reporting\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
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
            'source_module' => 'Tasks',
            'source_type' => 'task',
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
        $exports = $this->exporter(new ReportingAllowingDecider);

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
        $response = (new DownloadExportController(new ReportingPrincipalResolver, new DownloadExportArtifactHandler(new ReportingAllowingDecider, new ReportingExportScopeAncestry)))->__invoke($request, $export['id']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="export-', $response->headers->get('Content-Disposition'));
        $this->assertSame($expectedCsv, $response->getContent());
    }

    public function test_json_export_stores_json_payload_and_default_download_returns_envelope_for_polling(): void
    {
        $actor = ['user_id' => 'user-1', 'facility_id' => 'scope-a'];
        $exports = $this->exporter(new ReportingAllowingDecider);

        $export = $exports->handle(self::REPORT, $actor, 'json');
        $this->assertSame('json', $export['format']);

        $artifact = DB::table('export_artifacts')->where('id', $export['id'])->first();
        $this->assertSame(json_encode($export['items'], JSON_THROW_ON_ERROR), (string) $artifact->safe_result);

        $request = Request::create('/exports/'.$export['id'], 'GET');
        $request->headers->set('X-Correlation-ID', self::CORRELATION);
        $response = (new DownloadExportController(new ReportingPrincipalResolver, new DownloadExportArtifactHandler(new ReportingAllowingDecider, new ReportingExportScopeAncestry)))->__invoke($request, $export['id']);

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
        $exports = $this->exporter(new ReportingAllowingDecider);
        $export = $exports->handle(self::REPORT, $actor, 'json');

        $request = Request::create('/exports/'.$export['id'], 'GET');
        $request->headers->set('X-Correlation-ID', self::CORRELATION);
        $request->headers->set('Accept', 'text/csv');
        $response = (new DownloadExportController(new ReportingPrincipalResolver, new DownloadExportArtifactHandler(new ReportingAllowingDecider, new ReportingExportScopeAncestry)))->__invoke($request, $export['id']);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(406, $response->getStatusCode());
        $this->assertSame('https://cluster.example/problems/not-acceptable', $response->getData(true)['type']);
    }

    public function test_unsupported_formats_are_rejected_with_422_problem_and_no_state_is_written(): void
    {
        $actor = ['user_id' => 'user-1', 'facility_id' => 'scope-a'];
        $exports = $this->exporter(new ReportingAllowingDecider);

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
        $exports = $this->exporter(new ReportingAllowingDecider);
        $export = $exports->handle(self::REPORT, $actor, 'csv');

        $request = Request::create('/exports/'.$export['id'], 'GET');
        $request->headers->set('X-Correlation-ID', self::CORRELATION);
        $response = (new DownloadExportController(new ReportingPrincipalResolver, new DownloadExportArtifactHandler(new ReportingDenyingDecider, new ReportingExportScopeAncestry)))->__invoke($request, $export['id']);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $response->getData(true)['total']);
        $this->assertSame([], $response->getData(true)['items']);
    }

    public function test_export_artifact_is_not_downloadable_by_a_different_actor(): void
    {
        $owner = ['user_id' => 'user-1', 'facility_id' => 'scope-a'];
        $export = $this->exporter(new ReportingMutableDecider)->handle(self::REPORT, $owner, 'csv');
        $request = Request::create('/exports/'.$export['id'], 'GET');
        $request->headers->set('Accept', 'text/csv');

        $response = (new DownloadExportArtifactHandler(new ReportingMutableDecider, new ReportingExportScopeAncestry))->handle(
            $request,
            $export['id'],
            ['user_id' => 'user-2', 'facility_id' => 'scope-a'],
            self::CORRELATION,
        );

        $this->assertNull($response);
    }

    public function test_csv_download_regenerates_payload_after_per_row_reauthorization(): void
    {
        $actor = ['user_id' => 'user-1', 'facility_id' => 'scope-a'];
        $decider = new ReportingMutableDecider;
        $export = $this->exporter($decider)->handle(self::REPORT, $actor, 'csv');
        $this->assertStringContainsString('record-1', (string) DB::table('export_artifacts')->where('id', $export['id'])->value('safe_result'));
        $decider->denyTaskRows = true;

        $request = Request::create('/exports/'.$export['id'], 'GET');
        $request->headers->set('Accept', 'text/csv');
        $response = (new DownloadExportArtifactHandler($decider, new ReportingExportScopeAncestry))->handle($request, $export['id'], $actor, self::CORRELATION);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('record-1', (string) $response->getContent());
        $this->assertSame(CsvExportEncoder::encode([]), $response->getContent());
    }

    public function test_cluster_scoped_download_receives_authoritative_cluster_facts_for_the_run_facility(): void
    {
        $actor = ['user_id' => 'user-1', 'facility_id' => 'scope-a'];
        $export = $this->exporter(new ReportingMutableDecider)->handle(self::REPORT, $actor, 'csv');
        $request = Request::create('/exports/'.$export['id'], 'GET');
        $request->headers->set('Accept', 'text/csv');

        $response = (new DownloadExportArtifactHandler(
            new ReportingClusterDownloadDecider,
            new ReportingExportScopeAncestry,
        ))->handle($request, $export['id'], $actor, self::CORRELATION);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_export_replay_decodes_items_from_the_run_result_not_the_artifact(): void
    {
        $controller = new CreateReportExportController(
            new ReportingPrincipalResolver,
            $this->exporter(new ReportingAllowingDecider),
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

    public function test_download_reconstructs_authoritative_record_facts_for_every_supported_scope_type(): void
    {
        DB::table('report_read_models')->delete();
        $rows = [
            ['cluster', 'cluster-1', ['scope_type' => 'cluster']],
            ['facility', 'facility-1', ['scope_type' => 'facility', 'cluster_id' => 'cluster-1']],
            ['unit', 'unit-1', ['scope_type' => 'unit', 'facility_id' => 'facility-1', 'cluster_id' => 'cluster-1']],
            ['record_set', 'record-set-1', ['scope_type' => 'record_set', 'organization_unit_id' => 'unit-1', 'facility_id' => 'facility-1', 'cluster_id' => 'cluster-1']],
        ];
        foreach ($rows as [$suffix, $scopeId, $safeData]) {
            (new RefreshReportingProjectionHandler)->handle([
                'report_id' => self::REPORT,
                'source_module' => 'Tasks',
                'source_type' => 'task',
                'source_id' => 'record-'.$suffix,
                'source_version' => 'v1',
                'scope_id' => $scopeId,
                'classification' => 'confidential',
                'title' => $suffix,
                'safe_data' => $safeData,
            ]);
        }

        $actor = ['user_id' => 'user-1'];
        $decider = new ReportingFactsRecordingDecider;
        $export = $this->exporter($decider)->handle(self::REPORT, $actor, 'csv', null);
        $decider->resetFacts();
        $request = Request::create('/exports/'.$export['id'], 'GET');
        $request->headers->set('Accept', 'text/csv');

        $response = (new DownloadExportArtifactHandler($decider, new ReportingExportScopeAncestry))->handle($request, $export['id'], $actor, self::CORRELATION);
        $this->assertNotNull($response);
        $this->assertGreaterThanOrEqual(5, count($decider->facts));

        $facts = $decider->taskFactsByRecordId();
        $this->assertNull($facts['record-cluster']->ownerFacilityId);
        $this->assertSame('cluster-1', $facts['record-cluster']->clusterId);
        $this->assertSame('facility-1', $facts['record-facility']->ownerFacilityId);
        $this->assertSame('cluster-1', $facts['record-facility']->clusterId);
        $this->assertSame('unit-1', $facts['record-unit']->organizationUnitId);
        $this->assertSame('facility-1', $facts['record-unit']->ownerFacilityId);
        $this->assertSame('unit-1', $facts['record-record_set']->organizationUnitId);
        $this->assertSame('facility-1', $facts['record-record_set']->ownerFacilityId);
        $this->assertSame('cluster-1', $facts['record-record_set']->clusterId);
    }

    public function test_requested_export_scope_is_authorized_before_run_or_artifact_creation(): void
    {
        $handler = $this->exporter(new ReportingRequestedScopeDecider('scope-denied'));
        $request = Request::create(
            '/reports/'.self::REPORT.'/exports',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['format' => 'csv', 'scope_id' => 'scope-denied'], JSON_THROW_ON_ERROR),
        );
        $request->headers->set('X-Correlation-ID', self::CORRELATION);
        $request->headers->set('Idempotency-Key', 'denied-requested-scope');

        $response = (new CreateReportExportController(new ReportingExportPrincipalResolver, $handler))->__invoke($request, self::REPORT);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, DB::table('report_runs')->count());
        $this->assertSame(0, DB::table('export_artifacts')->count());
    }

    private function exporter(DecideAccess $access): ExportAuthorizedReportHandler
    {
        return new ExportAuthorizedReportHandler($access, new ReportingExportScopeAncestry);
    }
}

final class ReportingMutableDecider implements DecideAccess
{
    public bool $denyTaskRows = false;

    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return new AccessDecision(
            $this->denyTaskRows && $facts->resourceType === 'task' ? 'deny' : 'allow',
            $capability,
            $facts->resourceType,
            [],
            'test',
            'test',
            $facts->classification,
        );
    }
}

final class ReportingFactsRecordingDecider implements DecideAccess
{
    /** @var list<RecordFacts> */
    public array $facts = [];

    public function resetFacts(): void
    {
        $this->facts = [];
    }

    /** @return array<string, RecordFacts> */
    public function taskFactsByRecordId(): array
    {
        $byRecordId = [];
        foreach ($this->facts as $fact) {
            if ($fact->resourceType === 'task' && $fact->recordId !== null) {
                $byRecordId[$fact->recordId] = $fact;
            }
        }

        return $byRecordId;
    }

    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        if ($facts !== null) {
            $this->facts[] = $facts;
        }

        return new AccessDecision('allow', $capability, $facts->resourceType, [], 'test', 'test', $facts->classification);
    }
}

final class ReportingRequestedScopeDecider implements DecideAccess
{
    public function __construct(private readonly string $deniedScope) {}

    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $effect = $capability === 'reporting.export' && $facts->ownerFacilityId === $this->deniedScope ? 'deny' : 'allow';

        return new AccessDecision($effect, $capability, $facts->resourceType, [], 'test', 'test', $facts->classification);
    }
}

final class ReportingClusterDownloadDecider implements DecideAccess
{
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $allowed = $facts->clusterId === 'cluster-1';

        return new AccessDecision(
            $allowed ? 'allow' : 'deny',
            $capability,
            $facts->resourceType,
            [],
            'test',
            'test',
            $facts->classification,
        );
    }
}

final class ReportingExportPrincipalResolver implements ResolveDevelopmentFixturePrincipal
{
    public function issue(array $principal): array
    {
        return ['access_token' => 'test', 'expires_at' => now()->addHour()->toIso8601String()];
    }

    public function resolve(Request $request): array
    {
        return ['user_id' => 'user-1', 'facility_id' => 'scope-a'];
    }
}

final class ReportingExportScopeAncestry implements ResolveOrganizationScopeAncestry
{
    public function ancestry(string $scopeType, string $scopeId): ?array
    {
        return $scopeType === 'facility'
            ? ['cluster_id' => 'cluster-1', 'facility_id' => $scopeId, 'unit_id' => null]
            : null;
    }

    public function facilityClusterIds(array $facilityIds): array
    {
        return array_fill_keys($facilityIds, 'cluster-1');
    }
}
