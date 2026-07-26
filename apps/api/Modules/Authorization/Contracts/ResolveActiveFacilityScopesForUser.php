<?php

namespace Modules\Authorization\Contracts;

/**
 * Batch resolve the active facility scope ids currently granted to a user.
 * Read-only authorization facts owned by Authorization; downstream modules
 * ask through this contract instead of reading role_assignments directly.
 */
interface ResolveActiveFacilityScopesForUser
{
    /**
     * @return list<string>
     */
    public function facilityScopeIds(string $userId, ?string $atIso8601 = null): array;
}
