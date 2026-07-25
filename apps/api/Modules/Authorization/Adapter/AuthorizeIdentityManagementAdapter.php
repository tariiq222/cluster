<?php

namespace Modules\Authorization\Adapter;

use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Infrastructure\Persistence\ListActiveRoleSummariesForUser;
use Modules\Authorization\Infrastructure\Persistence\ListEffectiveCapabilitiesForUser;
use Modules\Identity\Contracts\AuthorizeIdentityManagement;

final readonly class AuthorizeIdentityManagementAdapter implements AuthorizeIdentityManagement
{
    public function __construct(
        private DecideAccess $access,
        private ListActiveRoleSummariesForUser $roleSummaries,
        private ListEffectiveCapabilitiesForUser $capabilities,
    ) {}

    public function canReadAccounts(array $principal): bool
    {
        return $this->decideForAccounts($principal, 'identity.account.read');
    }

    public function canManageAccounts(array $principal): bool
    {
        return $this->decideForAccounts($principal, 'identity.account.manage');
    }

    public function canIssueActivation(array $principal): bool
    {
        return $this->access->decide(
            $principal,
            'identity.account.manage',
            new RecordFacts(
                ownerFacilityId: null,
                resourceType: 'identity_activation',
                classification: 'confidential',
            ),
        )->isAllowed();
    }

    public function principalAccess(string $userId): array
    {
        $summary = $this->roleSummaries->forUser($userId);

        return [
            'roles' => array_map(static fn (array $role): string => $role['code'], $summary['roles']),
            'capabilities' => $this->capabilities->forUser($userId),
            'clearance' => $summary['clearance'],
        ];
    }

    /** @param array{facility_id: ?string} $principal */
    private function decideForAccounts(array $principal, string $capability): bool
    {
        return $this->access->decide(
            $principal,
            $capability,
            new RecordFacts(
                ownerFacilityId: $principal['facility_id'],
                resourceType: 'identity_account',
                classification: 'confidential',
            ),
        )->isAllowed();
    }
}
