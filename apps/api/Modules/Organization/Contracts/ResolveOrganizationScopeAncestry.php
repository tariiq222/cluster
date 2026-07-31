<?php

namespace Modules\Organization\Contracts;

/**
 * Resolves the ancestry of one organizational scope reference. Read-only
 * facts used by Authorization to prove that a narrower scope truly sits
 * inside a broader one; it never grants authority.
 */
interface ResolveOrganizationScopeAncestry
{
    /**
     * @return array{cluster_id: ?string, facility_id: ?string, unit_id: ?string}|null
     *                                                                                 Null when the reference does not resolve.
     */
    public function ancestry(string $scopeType, string $scopeId): ?array;

    /**
     * Resolves the owning cluster for a batch of facility ids in one
     * Organization-owned read. Facilities that do not resolve are omitted.
     *
     * @param  list<string>  $facilityIds
     * @return array<string, string|null>
     */
    public function facilityClusterIds(array $facilityIds): array;
}
