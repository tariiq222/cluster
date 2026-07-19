<?php

namespace Modules\Reporting\Features\GetAuthorizedDashboard\Handler;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;

final class GetAuthorizedDashboardHandler
{
    public function __construct(private readonly DecideAccess $access) {}

    /**
     * @param  array{user_id?: string, facility_id?: string}  $actor
     * @return array{id: string, title: string|null, items: list<array<string, mixed>>, total: int}
     */
    public function handle(string $dashboardId, array $actor, ?string $scopeId = null): array
    {
        $dashboard = DB::table('dashboard_definitions')->where('id', $dashboardId)->where('status', 'published')->first();
        if ($dashboard === null) {
            return ['id' => $dashboardId, 'title' => null, 'items' => [], 'total' => 0];
        }
        $reportId = $dashboard->report_id ?: $dashboard->id;
        $scopeId ??= $actor['facility_id'] ?? null;
        $query = DB::table('report_read_models')->where('report_id', $reportId)->orderBy('id');
        if ($scopeId !== null) {
            $query->where('scope_id', $scopeId);
        }

        $items = [];
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

        return ['id' => $dashboardId, 'title' => $dashboard->title, 'items' => $items, 'total' => count($items)];
    }
}
