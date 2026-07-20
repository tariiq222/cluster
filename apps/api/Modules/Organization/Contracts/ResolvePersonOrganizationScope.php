<?php

namespace Modules\Organization\Contracts;

/**
 * Resolves the effective organizational scope of a person from trusted
 * Organization sources: primary/temporary assignments, positions, units,
 * facilities and the cluster. Read-only facts; it never grants authority.
 */
interface ResolvePersonOrganizationScope
{
    /**
     * @return array{
     *     cluster_ids: list<string>,
     *     facility_ids: list<string>,
     *     organization_unit_ids: list<string>,
     *     primary_organization_unit_id: ?string
     * }
     */
    public function forPerson(string $personId): array;
}
