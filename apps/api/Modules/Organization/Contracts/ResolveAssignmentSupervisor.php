<?php

declare(strict_types=1);

namespace Modules\Organization\Contracts;

/**
 * Resolves the person who holds the manager position above a person's
 * primary assignment inside one organization unit. Read-only facts over
 * Organization-owned assignment/position rows; it never grants authority.
 */
interface ResolveAssignmentSupervisor
{
    /**
     * Returns the person id of the active holder of the manager position of
     * the given person's primary position within the unit, or null when the
     * person has no assignment there, the position has no manager, or the
     * manager position is vacant.
     */
    public function supervisorPersonId(string $personId, string $organizationUnitId): ?string;
}
