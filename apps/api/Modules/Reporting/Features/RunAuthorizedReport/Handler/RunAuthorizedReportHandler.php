<?php

namespace Modules\Reporting\Features\RunAuthorizedReport\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Throwable;

final class RunAuthorizedReportHandler
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public function __construct(private readonly DecideAccess $access) {}

    /**
     * @param  array{user_id?: string, facility_id?: string}  $actor
     * @return array{id: string, report_id: string, items: list<array<string, mixed>>, total: int, status: string}
     */
    public function handle(string $reportId, array $actor, ?string $scopeId = null): array
    {
        $scopeId ??= $actor['facility_id'] ?? null;

        try {
            $items = $this->authorizedRows($reportId, $actor, $scopeId, 'reporting.run');
        } catch (Throwable $exception) {
            $this->storeRun($reportId, $actor, $scopeId, [], $exception->getMessage());

            throw $exception;
        }

        $runId = $this->storeRun($reportId, $actor, $scopeId, $items, null);

        return [
            'id' => $runId,
            'report_id' => $reportId,
            'items' => $items,
            'total' => count($items),
            'status' => self::STATUS_COMPLETED,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function authorizedRows(string $reportId, array $actor, ?string $scopeId, string $capability = 'tasks.read'): array
    {
        $query = DB::table('report_read_models')->where('report_id', $reportId)->orderBy('id');
        if ($scopeId !== null) {
            $query->where('scope_id', $scopeId);
        }

        $items = [];
        foreach ($query->get() as $row) {
            $decision = $this->access->decide(
                $actor,
                $capability,
                new RecordFacts($row->scope_id, $row->source_type, $row->classification),
            );
            if (! $decision->isAllowed()) {
                continue;
            }

            $safeData = json_decode((string) $row->safe_data, true);
            $items[] = AccessProjection::fromDecision($decision)->compose([
                'id' => $row->id,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'title' => $row->title,
                'scope_id' => $row->scope_id,
                'classification' => $row->classification,
                'data' => is_array($safeData) ? $safeData : [],
            ]);
        }

        return $items;
    }

    /**
     * Persist one bounded run snapshot per (report, scope). Runs claimed by
     * the export idempotency flow (idempotency_key_hash IS NOT NULL) are
     * append-only history and are never replaced here; ad-hoc report views
     * reuse the cached row so repeated GETs cannot grow report_runs.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function storeRun(string $reportId, array $actor, ?string $scopeId, array $items, ?string $errorMessage): string
    {
        $now = now();

        return DB::transaction(function () use ($reportId, $actor, $scopeId, $items, $errorMessage, $now): string {
            $existing = DB::table('report_runs')
                ->whereNull('idempotency_key_hash')
                ->where('report_id', $reportId)
                ->where('scope_id', $scopeId)
                ->orderBy('created_at', 'desc')
                ->lockForUpdate()
                ->first();

            $status = $errorMessage === null ? self::STATUS_COMPLETED : self::STATUS_FAILED;
            if ($existing !== null) {
                DB::table('report_runs')->where('id', $existing->id)->update([
                    'status' => $status,
                    'result_count' => count($items),
                    'result' => json_encode($items, JSON_THROW_ON_ERROR),
                    'error_message' => $errorMessage,
                    'updated_at' => $now,
                ]);

                return (string) $existing->id;
            }

            $runId = (string) Str::uuid();
            DB::table('report_runs')->insert([
                'id' => $runId,
                'report_id' => $reportId,
                'actor_id' => $actor['user_id'] ?? null,
                'scope_id' => $scopeId,
                'status' => $status,
                'result_count' => count($items),
                'result' => json_encode($items, JSON_THROW_ON_ERROR),
                'error_message' => $errorMessage,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $runId;
        });
    }
}
