<?php

declare(strict_types=1);

namespace Modules\Reporting\Features\PurgeExpiredReporting\Handler;

use Illuminate\Support\Facades\DB;

/**
 * Bounded, batch-scoped purge of expired reporting data.
 *
 * 1. Deletes `export_artifacts` whose `expires_at` is in the past.
 * 2. Deletes `report_runs` older than the artifact retention window that no
 *    remaining artifact references (orphaned export runs whose artifact has
 *    already been purged, and stale ad-hoc run snapshots).
 *
 * A live artifact always protects its run row, so download envelopes that
 * decode `report_runs.result` can never dangle. Each cycle is capped at
 * `$limit` rows per table and must be re-invoked until `has_more` is false.
 */
final class PurgeExpiredReportingHandler
{
    public const MAX_BATCH_SIZE = 500;

    private const RETENTION_DAYS = 1;

    /** @return array{artifacts_purged: int, runs_purged: int, has_more: bool} */
    public function purge(int $limit): array
    {
        $limit = max(1, min($limit, self::MAX_BATCH_SIZE));
        $now = now();
        $retentionCutoff = now()->subDays(self::RETENTION_DAYS);

        $artifactsPurged = DB::transaction(function () use ($limit, $now): int {
            $ids = DB::table('export_artifacts')
                ->where('expires_at', '<', $now)
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');
            if ($ids->isEmpty()) {
                return 0;
            }

            return DB::table('export_artifacts')->whereIn('id', $ids)->delete();
        });

        $runsPurged = DB::transaction(function () use ($limit, $retentionCutoff): int {
            $ids = DB::table('report_runs')
                ->where('updated_at', '<', $retentionCutoff)
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('export_artifacts')
                        ->whereColumn('export_artifacts.report_run_id', 'report_runs.id');
                })
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');
            if ($ids->isEmpty()) {
                return 0;
            }

            return DB::table('report_runs')->whereIn('id', $ids)->delete();
        });

        $hasMore = $artifactsPurged === $limit || $runsPurged === $limit;

        return ['artifacts_purged' => $artifactsPurged, 'runs_purged' => $runsPurged, 'has_more' => $hasMore];
    }
}
