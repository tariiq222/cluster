<?php

namespace Modules\PlatformSettings\Contracts;

/**
 * PlatformSettings needs to resolve an organizational scope to its cluster
 * ancestor so business-calendar lookups can scope by tenant. The
 * Organization module owns the underlying implementation; this contract
 * lives in PlatformSettings so the dependency edges flow inward.
 */
interface ResolveOrganizationScopeAncestry
{
    /**
     * @return array{cluster_id: ?string, facility_id: ?string, unit_id: ?string}|null
     *                                                                                 Null when the reference does not resolve.
     */
    public function ancestry(string $scopeType, string $scopeId): ?array;
}
