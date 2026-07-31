<?php

namespace Modules\Reporting\Features\DownloadExportArtifact\Handler;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Reporting\Http\ReportingApi;
use Symfony\Component\HttpFoundation\Response;

final class DownloadExportArtifactHandler
{
    public function __construct(private readonly DecideAccess $access) {}

    /**
     * Content-negotiated artifact download.
     *
     * - `Accept: text/csv` serves the stored artifact payload as a real
     *   file whose Content-Type matches the stored format.
     * - Any other Accept (browser/poller default) returns the JSON
     *   envelope with per-item re-authorization so the polling client
     *   keeps reading `status`/`id` without regressions.
     *
     * @param  array{user_id?: string, facility_id?: string}  $actor
     */
    public function handle(Request $request, string $artifactId, array $actor, ?string $correlationId): Response|JsonResponse|null
    {
        $artifact = DB::table('export_artifacts')->where('id', $artifactId)->first();
        if ($artifact === null || $artifact->status !== 'available' || ($artifact->expires_at !== null && now()->greaterThan($artifact->expires_at))) {
            return null;
        }
        $run = DB::table('report_runs')->where('id', $artifact->report_run_id)->first();
        if ($run === null || $run->status !== 'completed') {
            return null;
        }

        $accept = strtolower((string) $request->header('Accept', '*/*'));
        if (str_contains($accept, 'text/csv')) {
            if ($artifact->format !== 'csv') {
                return ReportingApi::problem(406, 'not-acceptable', 'Not Acceptable', 'This export is not available as CSV.', $correlationId);
            }
            $decision = $this->access->decide(
                $actor,
                'reporting.download',
                new RecordFacts($run->scope_id, 'report_definition', 'internal'),
            );
            if (! $decision->isAllowed()) {
                return null;
            }

            return response((string) $artifact->safe_result, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="export-'.$artifactId.'.csv"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'X-Correlation-ID' => $correlationId ?? Str::uuid7()->toString(),
            ]);
        }

        $items = $this->authorizedItems($run, $actor);
        if ($items === null) {
            return null;
        }

        return ReportingApi::response([
            'id' => $artifact->id,
            'report_id' => $run->report_id,
            'format' => $artifact->format,
            'status' => $artifact->status,
            'items' => $items,
            'total' => count($items),
            'download_url' => '/api/v1/exports/'.$artifact->id,
        ], 200, $correlationId ?? Str::uuid7()->toString());
    }

    /**
     * @param  array{user_id?: string, facility_id?: string}  $actor
     * @return list<array<string, mixed>>|null
     */
    private function authorizedItems(object $run, array $actor): ?array
    {
        $decoded = json_decode((string) $run->result, true);
        $items = is_array($decoded) ? $decoded : null;
        if ($items === null) {
            return null;
        }

        $allowed = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['source_type'] ?? null) || ! is_string($item['source_id'] ?? null)) {
                continue;
            }
            $decision = $this->access->decide(
                $actor,
                'reporting.download',
                new RecordFacts(
                    is_string($item['scope_id'] ?? null) ? $item['scope_id'] : null,
                    $item['source_type'],
                    is_string($item['classification'] ?? null) ? $item['classification'] : 'internal',
                ),
            );
            if (! $decision->isAllowed()) {
                continue;
            }

            unset($item['allowed_actions'], $item['field_access'], $item['decision_id']);
            $allowed[] = AccessProjection::fromDecision($decision)->compose($item);
        }

        return $allowed;
    }
}
