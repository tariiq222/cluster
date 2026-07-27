<?php

namespace Modules\Organization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\ResolveScopeDescendants;

final class DatabaseResolveScopeDescendants implements ResolveScopeDescendants
{
    /** @return list<array{scope_type: 'facility'|'unit', scope_id: string}> */
    public function descendants(string $scopeType, string $scopeId): array
    {
        $descendants = [];
        if ($scopeType === 'cluster') {
            foreach (DB::table('facilities')->where('cluster_id', $scopeId)->pluck('id') as $facilityId) {
                $descendants[] = ['scope_type' => 'facility', 'scope_id' => (string) $facilityId];
            }
            foreach (DB::table('organization_units')->where('cluster_id', $scopeId)->pluck('id') as $unitId) {
                $descendants[] = ['scope_type' => 'unit', 'scope_id' => (string) $unitId];
            }
        } elseif ($scopeType === 'facility') {
            // Breadth-first walk from the facility downward through unit
            // children only. A unit's ancestry facility is the first facility
            // on its upward parent chain, which is exactly the set reachable
            // downward from that facility. Bounded by tree depth (one query
            // per level) instead of one ancestry walk per unit, and guarded
            // against parent cycles in corrupt data.
            $seen = [];
            $frontier = DB::table('organization_units')
                ->where('parent_type', 'facility')
                ->where('parent_id', $scopeId)
                ->pluck('id')
                ->all();
            while ($frontier !== []) {
                foreach ($frontier as $unitId) {
                    $unitId = (string) $unitId;
                    $seen[$unitId] = true;
                    $descendants[] = ['scope_type' => 'unit', 'scope_id' => $unitId];
                }
                $next = DB::table('organization_units')
                    ->where('parent_type', 'unit')
                    ->whereIn('parent_id', $frontier)
                    ->pluck('id')
                    ->all();
                $frontier = [];
                foreach ($next as $unitId) {
                    $unitId = (string) $unitId;
                    if (! isset($seen[$unitId])) {
                        $frontier[] = $unitId;
                    }
                }
            }
        }

        return $descendants;
    }
}
