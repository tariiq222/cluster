<?php

namespace Modules\Organization\Contracts;

/**
 * Resolves the descendant scopes owned by a parent cluster or facility.
 * Read-only organizational facts used by Authorization to build an actor's
 * effective scope set; the contract never grants authority.
 */
interface ResolveScopeDescendants
{
    /**
     * @return list<array{scope_type: 'facility'|'unit', scope_id: string}>
     */
    public function descendants(string $scopeType, string $scopeId): array;
}
