<?php

namespace Modules\Reporting\Features\RunAuthorizedReport\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;

final class RunAuthorizedReportHandler
{
    public function __construct(private readonly DecideAccess $access) {}

    /**
     * @param  array{user_id?: string, facility_id?: string}  $actor
     * @return array{id: string, report_id: string, items: list<array<string, mixed>>, total: int, status: string}
     */
    public function handle(string $reportId, array $actor, ?string $scopeId = null): array
    {
        $scopeId ??= $actor['facility_id'] ?? null;
        $items = $this->authorizedRows($reportId, $actor, $scopeId, 'reporting.run');
        $runId = (string) Str::uuid();

        DB::table('report_runs')->insert([
            'id' => $runId,
            'report_id' => $reportId,
            'actor_id' => $actor['user_id'] ?? null,
            'scope_id' => $scopeId,
            'status' => 'completed',
            'result_count' => count($items),
            'result' => json_encode($items, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $runId, 'report_id' => $reportId, 'items' => $items, 'total' => count($items), 'status' => 'completed'];
    }

    /** @return list<array<string, mixed>> */
    public function authorizedRows(string $reportId, array $actor, ?string $scopeId, string $capability = 'work_record.read'): array
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
}
