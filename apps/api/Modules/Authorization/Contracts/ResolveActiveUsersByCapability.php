<?php

declare(strict_types=1);

namespace Modules\Authorization\Contracts;

/**
 * Resolves the active user ids that hold a capability through a live role
 * assignment. Read-only membership facts; it never grants authority.
 */
interface ResolveActiveUsersByCapability
{
    /**
     * @return list<string>
     */
    public function users(string $capabilityCode): array;
}
