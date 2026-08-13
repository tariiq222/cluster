<?php

namespace Modules\Organization\Features\Authorization;

use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\RecordFacts;
use stdClass;

/**
 * Builds authorization facts from Organization-owned target rows. Actor
 * context is deliberately not an input: a target whose ancestry cannot be
 * proven from the persisted parent chain has no usable authorization facts.
 */
final class OrganizationResourceFacts
{
    private const FACTS_VERSION = 'organization-resource-facts-v1';

    public function factsForCluster(string $clusterId): ?RecordFacts
    {
        if (! DB::table('clusters')->where('id', $clusterId)->exists()) {
            return null;
        }

        return $this->facts(null, null, $clusterId, $clusterId, 'organization_cluster');
    }

    public function factsForFacility(string $facilityId): ?RecordFacts
    {
        $facility = DB::table('facilities')->where('id', $facilityId)->first(['id', 'cluster_id']);
        if (! $facility instanceof stdClass
            || ! is_string($facility->cluster_id)
            || $this->factsForCluster($facility->cluster_id) === null) {
            return null;
        }

        return $this->facts($facilityId, null, $facility->cluster_id, $facilityId, 'organization_facility');
    }

    public function factsForUnit(string $unitId): ?RecordFacts
    {
        $unit = DB::table('organization_units')
            ->where('id', $unitId)
            ->first(['id', 'cluster_id', 'parent_id', 'parent_type']);
        if (! $unit instanceof stdClass || ! is_string($unit->cluster_id)) {
            return null;
        }

        $clusterId = $unit->cluster_id;
        if ($this->factsForCluster($clusterId) === null) {
            return null;
        }

        $current = $unit;
        $visited = [(string) $unit->id => true];
        for ($depth = 0; $depth < 32; $depth++) {
            $parentType = (string) $current->parent_type;
            $parentId = is_string($current->parent_id) ? $current->parent_id : null;
            if ($parentId === null) {
                return null;
            }
            if ($parentType === 'cluster') {
                return hash_equals($clusterId, $parentId)
                    ? $this->facts(null, $unitId, $clusterId, $unitId, 'organization_unit')
                    : null;
            }
            if ($parentType === 'facility') {
                $facilityFacts = $this->factsForFacility($parentId);
                if ($facilityFacts === null || ! hash_equals($clusterId, (string) $facilityFacts->clusterId)) {
                    return null;
                }

                return $this->facts($parentId, $unitId, $clusterId, $unitId, 'organization_unit');
            }
            if ($parentType !== 'unit' || isset($visited[$parentId])) {
                return null;
            }

            $visited[$parentId] = true;
            $parent = DB::table('organization_units')
                ->where('id', $parentId)
                ->first(['id', 'cluster_id', 'parent_id', 'parent_type']);
            if (! $parent instanceof stdClass
                || ! is_string($parent->cluster_id)
                || ! hash_equals($clusterId, $parent->cluster_id)) {
                return null;
            }
            $current = $parent;
        }

        return null;
    }

    public function factsForPosition(string $positionId): ?RecordFacts
    {
        $position = DB::table('positions')
            ->where('id', $positionId)
            ->first(['id', 'organization_unit_id']);
        if (! $position instanceof stdClass || ! is_string($position->organization_unit_id)) {
            return null;
        }

        $unitFacts = $this->factsForUnit($position->organization_unit_id);
        if ($unitFacts === null) {
            return null;
        }

        return $this->facts(
            $unitFacts->ownerFacilityId,
            $unitFacts->organizationUnitId,
            $unitFacts->clusterId,
            $positionId,
            'organization_position',
        );
    }

    public function factsForUnitParent(string $clusterId, ?string $parentId): ?RecordFacts
    {
        if ($parentId === null || hash_equals($clusterId, $parentId)) {
            return $this->factsForCluster($clusterId);
        }

        $facts = $this->factsForFacility($parentId) ?? $this->factsForUnit($parentId);

        return $facts !== null && hash_equals($clusterId, (string) $facts->clusterId) ? $facts : null;
    }

    private function facts(
        ?string $facilityId,
        ?string $unitId,
        ?string $clusterId,
        string $recordId,
        string $resourceType,
    ): RecordFacts {
        return new RecordFacts(
            ownerFacilityId: $facilityId,
            resourceType: $resourceType,
            classification: 'internal',
            factsVersion: self::FACTS_VERSION,
            organizationUnitId: $unitId,
            recordId: $recordId,
            sourceModule: 'organization',
            clusterId: $clusterId,
        );
    }
}
