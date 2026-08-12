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
     * Resolves assignment-owned organizational relationships for a batch of
     * people without using the actor's current or selected scope.
     *
     * @param  list<string>  $personIds
     * @return array<string, list<array{cluster_id: ?string, facility_id: ?string, organization_unit_id: string}>>
     */
    public function forPeople(array $personIds): array;

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
