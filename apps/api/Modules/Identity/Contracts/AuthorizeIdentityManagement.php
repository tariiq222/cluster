<?php

namespace Modules\Identity\Contracts;

interface AuthorizeIdentityManagement
{
    /** @param array{facility_id: ?string} $principal */
    public function canReadAccounts(array $principal): bool;

    /** @param array{facility_id: ?string} $principal */
    public function canManageAccounts(array $principal): bool;

    /** @param array<string, mixed> $principal */
    public function canIssueActivation(array $principal): bool;

    /**
     * @return array{
     *     roles: list<string>,
     *     capabilities: list<string>,
     *     clearance: string
     * }
     */
    public function principalAccess(string $userId): array;
}
