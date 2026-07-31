<?php

namespace Modules\Organization\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\ResolvePersonOrganizationScope;
use stdClass;

final class DatabaseResolvePersonOrganizationScope implements ResolvePersonOrganizationScope
{
    public function forPerson(string $personId): array
    {
        $person = DB::table('people')->where('id', $personId)->first(['status']);
        if (! $person instanceof stdClass) {
            return $this->emptyScope();
        }
        // A person who has left the organization loses all organization
        // scope: assignments no longer imply read access once the person is
        // gone. A suspended person keeps read scope — suspension is a
        // temporary access-control measure (Identity treats suspended
        // accounts as disabled) while the assignment window stays current,
        // so read scope remains consistent with the person's own record.
        if ($person->status === 'left') {
            return $this->emptyScope();
        }
        $at = CarbonImmutable::now('UTC')->floorMillisecond()->format('Y-m-d H:i:s.v');

        $assignments = DB::table('assignments as assignment')
            ->join('positions as position', 'position.id', '=', 'assignment.position_id')
            ->where('assignment.person_id', $personId)
            ->where('assignment.start_at', '<=', $at)
            ->where(function ($query) use ($at): void {
                $query->whereNull('assignment.end_at')->orWhere('assignment.end_at', '>', $at);
            })
            ->orderBy('assignment.id')
            ->get(['assignment.id', 'assignment.is_primary', 'position.organization_unit_id']);

        $temporaries = DB::table('temporary_assignments')
            ->where('person_id', $personId)
            ->where('state', '!=', 'revoked')
            ->where('start_at', '<=', $at)
            ->where('end_at', '>', $at)
            ->orderBy('id')
            ->get(['id', 'organization_unit_id']);

        /** @var array<string, true> $unitIds */
        $unitIds = [];
        $primaryUnitId = null;
        foreach ($assignments as $assignment) {
            $unitIds[(string) $assignment->organization_unit_id] = true;
            if ($primaryUnitId === null && (bool) $assignment->is_primary) {
                $primaryUnitId = (string) $assignment->organization_unit_id;
            }
        }
        foreach ($temporaries as $temporary) {
            $unitIds[(string) $temporary->organization_unit_id] = true;
        }
        if ($primaryUnitId === null) {
            $firstTemporary = $temporaries->first();
            $primaryUnitId = $firstTemporary instanceof stdClass
                ? (string) $firstTemporary->organization_unit_id
                : null;
        }

        $units = $this->loadUnitChain(array_keys($unitIds));

        /** @var array<string, true> $clusterIds */
        $clusterIds = [];
        /** @var array<string, true> $facilityIds */
        $facilityIds = [];
        foreach (array_keys($unitIds) as $unitId) {
            $unit = $units[$unitId] ?? null;
            if (! $unit instanceof stdClass) {
                continue;
            }
            $clusterIds[(string) $unit->cluster_id] = true;
            $current = $unit;
            $guard = count($units);
            while ($current->parent_type === 'unit' && $guard-- > 0) {
                $parent = $units[(string) $current->parent_id] ?? null;
                if (! $parent instanceof stdClass) {
                    break;
                }
                $current = $parent;
            }
            if ($current->parent_type === 'facility') {
                $facilityIds[(string) $current->parent_id] = true;
            }
        }

        return [
            'cluster_ids' => $this->sortedIds($clusterIds),
            'facility_ids' => $this->sortedIds($facilityIds),
            'organization_unit_ids' => $this->sortedIds($unitIds),
            'primary_organization_unit_id' => $primaryUnitId,
        ];
    }

    /** @return array{cluster_ids: list<string>, facility_ids: list<string>, organization_unit_ids: list<string>, primary_organization_unit_id: null} */
    private function emptyScope(): array
    {
        return [
            'cluster_ids' => [],
            'facility_ids' => [],
            'organization_unit_ids' => [],
            'primary_organization_unit_id' => null,
        ];
    }

    /**
     * Loads the given units and every ancestor unit reachable through
     * parent_type 'unit' links, keyed by unit id.
     *
     * @param  list<string>  $unitIds
     * @return array<string, stdClass>
     */
    private function loadUnitChain(array $unitIds): array
    {
        /** @var array<string, stdClass> $units */
        $units = [];
        /** @var array<string, true> $seen */
        $seen = [];
        $pending = $unitIds;
        while ($pending !== []) {
            $batch = [];
            foreach ($pending as $id) {
                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    $batch[] = $id;
                }
            }
            $pending = [];
            if ($batch === []) {
                break;
            }
            $rows = DB::table('organization_units')
                ->whereIn('id', $batch)
                ->get(['id', 'cluster_id', 'parent_id', 'parent_type']);
            foreach ($rows as $row) {
                $units[(string) $row->id] = $row;
                if ($row->parent_type === 'unit') {
                    $pending[] = (string) $row->parent_id;
                }
            }
        }

        return $units;
    }

    /**
     * @param  array<string, true>  $ids
     * @return list<string>
     */
    private function sortedIds(array $ids): array
    {
        $values = array_keys($ids);
        sort($values);

        return $values;
    }
}
