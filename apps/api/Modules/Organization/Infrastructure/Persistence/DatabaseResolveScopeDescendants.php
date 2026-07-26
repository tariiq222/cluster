<?php

namespace Modules\Organization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\Organization\Contracts\ResolveScopeDescendants;

final class DatabaseResolveScopeDescendants implements ResolveScopeDescendants
{
    public function __construct(
        private readonly ResolveOrganizationScopeAncestry $ancestry,
    ) {}

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
            foreach (DB::table('organization_units')->pluck('id') as $unitId) {
                $ancestry = $this->ancestry->ancestry('unit', (string) $unitId);
                if (($ancestry['facility_id'] ?? null) === $scopeId) {
                    $descendants[] = ['scope_type' => 'unit', 'scope_id' => (string) $unitId];
                }
            }
        }

        return $descendants;
    }
}
