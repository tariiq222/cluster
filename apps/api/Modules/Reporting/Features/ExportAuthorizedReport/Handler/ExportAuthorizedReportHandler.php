<?php

namespace Modules\Reporting\Features\ExportAuthorizedReport\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;

final class ExportAuthorizedReportHandler
{
    public function __construct(private readonly DecideAccess $access) {}

    /**
     * @param  array{user_id?: string, facility_id?: string}  $actor
     * @return array{id: string, report_id: string, format: string, items: list<array<string, mixed>>, total: int, status: string}
     */
    public function handle(string $reportId, array $actor, string $format = 'csv', ?string $scopeId = null): array
    {
        $format = strtolower($format);
        if (! in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            throw new \InvalidArgumentException('Unsupported export format.');
        }
        $scopeId ??= $actor['facility_id'] ?? null;
        $items = [];
        $query = DB::table('report_read_models')->where('report_id', $reportId)->orderBy('id');
        if ($scopeId !== null) {
            $query->where('scope_id', $scopeId);
        }
        foreach ($query->get() as $row) {
            $decision = $this->access->decide(
                $actor,
                'work_record.read',
                new RecordFacts($row->scope_id, $row->source_type, $row->classification),
            );
            if (! $decision->isAllowed()) {
                continue;
            }
            $data = json_decode((string) $row->safe_data, true);
            $items[] = [
                'id' => $row->id,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'title' => $row->title,
                'scope_id' => $row->scope_id,
                'data' => is_array($data) ? $data : [],
            ];
        }

        $runId = (string) Str::uuid();
        $artifactId = (string) Str::uuid();
        $now = now();
        DB::table('report_runs')->insert([
            'id' => $runId, 'report_id' => $reportId, 'actor_id' => $actor['user_id'] ?? null,
            'scope_id' => $scopeId, 'status' => 'completed', 'result_count' => count($items),
            'result' => json_encode($items, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('export_artifacts')->insert([
            'id' => $artifactId, 'report_run_id' => $runId, 'format' => $format,
            'status' => 'available', 'result_count' => count($items),
            'safe_result' => json_encode($items, JSON_THROW_ON_ERROR), 'expires_at' => $now->copy()->addDay(),
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['id' => $artifactId, 'report_id' => $reportId, 'format' => $format, 'items' => $items, 'total' => count($items), 'status' => 'available'];
    }
}
