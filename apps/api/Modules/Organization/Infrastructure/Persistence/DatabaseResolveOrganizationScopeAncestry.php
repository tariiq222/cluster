<?php

namespace Modules\Organization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use stdClass;

/**
 * Persistence-backed ancestry resolution over Organization-owned tables only.
 * Unit ancestry walks the authoritative parent chain (cycle-guarded) exactly
 * like DatabaseResolvePersonOrganizationScope.
 */
final class DatabaseResolveOrganizationScopeAncestry implements ResolveOrganizationScopeAncestry
{
    public function ancestry(string $scopeType, string $scopeId): ?array
    {
        return match ($scopeType) {
            'cluster' => $this->clusterAncestry($scopeId),
            'facility' => $this->facilityAncestry($scopeId),
            'unit' => $this->unitAncestry($scopeId),
            'record_set' => ['cluster_id' => null, 'facility_id' => null, 'unit_id' => null],
            default => null,
        };
    }

    /** @return array{cluster_id: ?string, facility_id: ?string, unit_id: ?string}|null */
    private function clusterAncestry(string $scopeId): ?array
    {
        if (! DB::table('clusters')->where('id', $scopeId)->exists()) {
            return null;
        }

        return ['cluster_id' => $scopeId, 'facility_id' => null, 'unit_id' => null];
    }

    /** @return array{cluster_id: ?string, facility_id: ?string, unit_id: ?string}|null */
    private function facilityAncestry(string $scopeId): ?array
    {
        $facility = DB::table('facilities')->where('id', $scopeId)->first(['id', 'cluster_id']);
        if ($facility === null) {
            return null;
        }

        return ['cluster_id' => (string) $facility->cluster_id, 'facility_id' => $scopeId, 'unit_id' => null];
    }

    /** @return array{cluster_id: ?string, facility_id: ?string, unit_id: ?string}|null */
    private function unitAncestry(string $scopeId): ?array
    {
        $unit = DB::table('organization_units')->where('id', $scopeId)->first(['id', 'cluster_id', 'parent_id', 'parent_type']);
        if ($unit === null) {
            return null;
        }

        $facilityId = null;
        $current = $unit;
        $visited = [$unit->id => true];
        for ($depth = 0; $depth < 32; $depth++) {
            if ($current->parent_type === 'facility' && is_string($current->parent_id)) {
                $facilityId = $current->parent_id;
                break;
            }
            if ($current->parent_type === 'unit' && is_string($current->parent_id)) {
                if (isset($visited[$current->parent_id])) {
                    break;
                }
                $visited[$current->parent_id] = true;
                $parent = DB::table('organization_units')->where('id', $current->parent_id)->first(['id', 'cluster_id', 'parent_id', 'parent_type']);
                if (! $parent instanceof stdClass) {
                    break;
                }
                $current = $parent;

                continue;
            }

            break;
        }

        return [
            'cluster_id' => is_string($unit->cluster_id) ? $unit->cluster_id : null,
            'facility_id' => $facilityId,
            'unit_id' => $scopeId,
        ];
    }
}
