<?php

namespace Modules\Identity\Contracts;

/**
 * Trusted server-side principal facts for the real authorization engine.
 * Built only from the validated Identity session, the account record and
 * Organization scope facts; browsers never supply roles or scopes.
 */
final readonly class PrincipalContext
{
    /**
     * @param  list<string>  $clusterIds
     * @param  list<string>  $facilityIds
     * @param  list<string>  $organizationUnitIds
     * @param  array{scope_type: string, scope_id: string}|null  $selectedScope
     */
    public function __construct(
        public string $userId,
        public ?string $personId,
        public string $accountStatus,
        public array $clusterIds,
        public array $facilityIds,
        public array $organizationUnitIds,
        public ?string $primaryOrganizationUnitId,
        public ?array $selectedScope,
        public bool $sessionRestricted,
    ) {}

    /**
     * @return array{user_id: string, facility_id: string|null, cluster_ids: list<string>, facility_ids: list<string>, organization_unit_ids: list<string>, correlation_id: string|null}
     */
    public function toActorArray(?string $correlationId = null): array
    {
        return [
            'user_id' => $this->userId,
            'facility_id' => $this->defaultFacilityId(),
            'cluster_ids' => $this->clusterIds,
            'facility_ids' => $this->facilityIds,
            'organization_unit_ids' => $this->organizationUnitIds,
            'correlation_id' => $correlationId,
        ];
    }

    /** @return array{user_id: string, facility_id: string|null} */
    public function toLegacyArray(): array
    {
        return [
            'user_id' => $this->userId,
            'facility_id' => $this->defaultFacilityId(),
        ];
    }

    private function defaultFacilityId(): ?string
    {
        return $this->primaryOrganizationUnitId ?? ($this->facilityIds[0] ?? null);
    }
}
