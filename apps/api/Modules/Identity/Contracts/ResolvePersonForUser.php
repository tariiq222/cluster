<?php

namespace Modules\Identity\Contracts;

/**
 * Resolves the Organization person backing an Identity user account.
 * Read-only identity fact; it never grants authority.
 */
interface ResolvePersonForUser
{
    /** Returns the users.person_id for the account, or null when unlinked/unknown. */
    public function forUser(string $userId): ?string;
}
