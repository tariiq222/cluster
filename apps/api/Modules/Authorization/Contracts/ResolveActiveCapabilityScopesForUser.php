<?php

namespace Modules\Authorization\Contracts;

/**
 * Lists the active authorization roots that grant one capability to a user.
 * Callers must still run DecideAccess for the requested resource facts.
 */
interface ResolveActiveCapabilityScopesForUser
{
    /**
     * @return list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}>
     */
    public function roots(string $userId, string $capability): array;
}
