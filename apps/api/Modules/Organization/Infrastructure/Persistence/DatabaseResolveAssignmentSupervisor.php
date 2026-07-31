<?php

declare(strict_types=1);

namespace Modules\Organization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\ResolveAssignmentSupervisor;

/**
 * Persistence-backed supervisor resolution over Organization-owned tables
 * only: the manager position of the person's primary position in the unit,
 * then the active holder of that manager position.
 */
final class DatabaseResolveAssignmentSupervisor implements ResolveAssignmentSupervisor
{
    public function supervisorPersonId(string $personId, string $organizationUnitId): ?string
    {
        $managerPositionId = DB::table('assignments as assignment')
            ->join('positions as position', 'position.id', '=', 'assignment.position_id')
            ->where('assignment.person_id', $personId)
            ->where('position.organization_unit_id', $organizationUnitId)
            ->whereNull('assignment.end_at')
            ->orderByDesc('assignment.is_primary')
            ->value('position.manager_position_id');
        if (! is_string($managerPositionId) || $managerPositionId === '') {
            return null;
        }

        $managerPersonId = DB::table('assignments')
            ->where('position_id', $managerPositionId)
            ->whereNull('end_at')
            ->orderByDesc('is_primary')
            ->value('person_id');

        return is_string($managerPersonId) ? $managerPersonId : null;
    }
}
