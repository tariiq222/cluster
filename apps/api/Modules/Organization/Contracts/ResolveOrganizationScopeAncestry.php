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
}
