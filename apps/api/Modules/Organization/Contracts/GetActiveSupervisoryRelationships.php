<?php

namespace Modules\Organization\Contracts;

interface GetActiveSupervisoryRelationships
{
    /**
     * Returns only relationship facts whose half-open UTC validity window
     * contains the current clock instant.
     *
     * @return list<array{
     *     supervisory_relationship_id: string,
     *     source_organization_unit_id: string,
     *     target_organization_unit_id: string,
     *     relationship_type: 'direct'|'functional'|'coordination'|'read_only',
     *     valid_from: string,
     *     valid_until: string,
     *     relationship_capabilities: list<array{
     *         relationship_capability_id: string,
     *         module_code: string,
     *         capability_code: string
     *     }>
     * }>
     */
    public function forSourceOrganizationUnit(string $sourceOrganizationUnitId): array;
}
