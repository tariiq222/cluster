<?php

namespace Modules\Reporting\Features\ExportAuthorizedReport\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\Reporting\Application\ReportingAuthorizationFacts;
use Modules\Reporting\Infrastructure\Export\CsvExportEncoder;
use UnexpectedValueException;

final class ExportAuthorizedReportHandler
{
    /** @var list<string> */
    private const SUPPORTED_FORMATS = ['csv', 'json'];

    public function __construct(
        private readonly DecideAccess $access,
        private readonly ResolveOrganizationScopeAncestry $scopeAncestry,
    ) {}

    /**
     * @param  array{user_id?: string}  $actor
     * @return array{id: string, report_id: string, format: string, items: list<array<string, mixed>>, total: int, status: string}|null
     */
    public function findReplay(array $actor, string $idempotencyKey, string $requestHash): ?array
    {
        $existing = DB::table('report_runs')
            ->where('actor_id', $actor['user_id'] ?? null)
            ->where('idempotency_key_hash', hash('sha256', $idempotencyKey))
            ->first();
        if ($existing === null) {
            return null;
        }
        if (! hash_equals((string) $existing->request_hash, $requestHash)) {
            throw new ExportIdempotencyConflict;
        }

        return $this->replay($existing);
    }

    public function authorize(array $actor, string $reportId, ?string $scopeId = null): bool
    {
        $scopeId ??= is_string($actor['facility_id'] ?? null) ? $actor['facility_id'] : null;
        $clusterId = null;
        if ($scopeId !== null) {
            $ancestry = $this->scopeAncestry->ancestry('facility', $scopeId);
            if ($ancestry === null || ! is_string($ancestry['facility_id']) || ! hash_equals($scopeId, $ancestry['facility_id'])) {
                return false;
            }
            $clusterId = $ancestry['cluster_id'];
        }

        return $this->access->decide(
            $actor,
            'reporting.export',
            ReportingAuthorizationFacts::forRequestedReport($reportId, $scopeId, $clusterId),
        )->isAllowed();
    }

    /**
     * Synchronous, transactional export creation. The run row is claimed
     * and completed inside one transaction, so an execution failure rolls
     * every effect back and a retry with the same idempotency key starts
     * a fresh attempt; no fictional intermediate state is ever persisted.
     *
     * @param  array{user_id?: string, facility_id?: string}  $actor
     * @return array{id: string, report_id: string, format: string, items: list<array<string, mixed>>, total: int, status: string}
     */
    public function handle(
        string $reportId,
        array $actor,
        string $format = 'csv',
        ?string $scopeId = null,
        ?string $idempotencyKey = null,
        ?string $requestHash = null,
    ): array {
        $format = strtolower($format);
        if (! in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new UnsupportedExportFormatException($format);
        }
        $scopeId ??= $actor['facility_id'] ?? null;
        if (! $this->authorize($actor, $reportId, $scopeId)) {
            throw new ExportAuthorizationDenied;
        }

        return DB::transaction(function () use ($reportId, $actor, $format, $scopeId, $idempotencyKey, $requestHash): array {
            $runId = (string) Str::uuid();
            $keyHash = $idempotencyKey === null ? null : hash('sha256', $idempotencyKey);
            if ($keyHash !== null) {
                $claimed = DB::table('report_runs')->insertOrIgnore([
                    'id' => $runId,
                    'report_id' => $reportId,
                    'actor_id' => $actor['user_id'] ?? null,
                    'scope_id' => $scopeId,
                    'status' => 'completed',
                    'result_count' => 0,
                    'result' => json_encode([], JSON_THROW_ON_ERROR),
                    'idempotency_key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if ($claimed !== 1) {
                    $existing = DB::table('report_runs')
                        ->where('actor_id', $actor['user_id'] ?? null)
                        ->where('idempotency_key_hash', $keyHash)
                        ->lockForUpdate()
                        ->first();
                    if ($existing === null || ! hash_equals((string) $existing->request_hash, (string) $requestHash)) {
                        throw new ExportIdempotencyConflict;
                    }

                    return $this->replay($existing);
                }
            }

            $items = [];
            $query = DB::table('report_read_models')->where('report_id', $reportId)->orderBy('id');
            if ($scopeId !== null) {
                $query->where('scope_id', $scopeId);
            }
            foreach ($query->get() as $row) {
                $decision = $this->access->decide(
                    $actor,
                    'reporting.export',
                    ReportingAuthorizationFacts::forProjectionRow($row),
                );
                if (! $decision->isAllowed()) {
                    continue;
                }
                $data = json_decode((string) $row->safe_data, true);
                $items[] = AccessProjection::fromDecision($decision)->compose([
                    'id' => $row->id,
                    'source_type' => $row->source_type,
                    'source_id' => $row->source_id,
                    'title' => $row->title,
                    'scope_id' => $row->scope_id,
                    'classification' => $row->classification,
                    'data' => is_array($data) ? $data : [],
                ]);
            }

            $payload = $format === 'csv'
                ? CsvExportEncoder::encode($items)
                : json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $artifactId = (string) Str::uuid();
            $now = now();
            if ($keyHash === null) {
                DB::table('report_runs')->insert([
                    'id' => $runId,
                    'report_id' => $reportId,
                    'actor_id' => $actor['user_id'] ?? null,
                    'scope_id' => $scopeId,
                    'status' => 'completed',
                    'result_count' => count($items),
                    'result' => json_encode($items, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('report_runs')->where('id', $runId)->update([
                    'status' => 'completed',
                    'result_count' => count($items),
                    'result' => json_encode($items, JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ]);
            }
            DB::table('export_artifacts')->insert([
                'id' => $artifactId,
                'report_run_id' => $runId,
                'format' => $format,
                'status' => 'available',
                'result_count' => count($items),
                'safe_result' => $payload,
                'expires_at' => $now->copy()->addDay(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'id' => $artifactId,
                'report_id' => $reportId,
                'format' => $format,
                'items' => $items,
                'total' => count($items),
                'status' => 'available',
            ];
        });
    }

    /** @return array{id: string, report_id: string, format: string, items: list<array<string, mixed>>, total: int, status: string} */
    private function replay(object $run): array
    {
        $artifact = DB::table('export_artifacts')->where('report_run_id', $run->id)->first();
        if ($artifact === null || $run->status !== 'completed') {
            throw new UnexpectedValueException('Stored report export idempotency state is incomplete.');
        }
        $items = json_decode((string) $run->result, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($items)) {
            throw new UnexpectedValueException('Stored report export idempotency state is incomplete.');
        }

        return [
            'id' => $artifact->id,
            'report_id' => $run->report_id,
            'format' => $artifact->format,
            'items' => $items,
            'total' => (int) $artifact->result_count,
            'status' => $artifact->status,
        ];
    }
}
