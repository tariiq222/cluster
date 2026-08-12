<?php

namespace Modules\Organization\Features\Person\Authorization;

use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\Organization\Contracts\ResolvePersonOrganizationScope;

/**
 * Builds authorization facts for a Person from Organization-owned assignment
 * data. The actor's selected facility is deliberately never used as a Person's
 * owner scope.
 */
final class PersonAuthorizationFacts
{
    private const FACTS_VERSION = 'organization-person-facts-v1';

    public function __construct(
        private readonly ResolvePersonOrganizationScope $scopeResolver,
        private readonly ResolveOrganizationScopeAncestry $ancestry,
        private readonly DecideAccess $access,
    ) {}

    /**
     * @return list<RecordFacts>
     */
    public function forPerson(string $personId, string $resourceType): array
    {
        $scope = $this->scopeResolver->forPerson($personId);
        $facts = [];
        foreach ($scope['organization_unit_ids'] as $unitId) {
            $unitAncestry = $this->ancestry->ancestry('unit', $unitId);
            if ($unitAncestry === null) {
                continue;
            }

            $facts[] = $this->facts(
                $personId,
                $resourceType,
                $unitAncestry['facility_id'],
                $unitAncestry['unit_id'],
                $unitAncestry['cluster_id'],
            );
        }

        if ($facts !== []) {
            return $this->unique($facts);
        }

        // A Person without a current assignment has no assignment-owned
        // organizational facts. Never substitute actor or deployment context.
        return [];
    }

    /**
     * @param  list<string>  $personIds
     * @return array<string, list<RecordFacts>>
     */
    public function forPeople(array $personIds, string $resourceType): array
    {
        $uniquePersonIds = [];
        foreach ($personIds as $personId) {
            if (trim($personId) !== '') {
                $uniquePersonIds[$personId] = true;
            }
        }

        $relationshipsByPerson = $this->scopeResolver->forPeople(array_keys($uniquePersonIds));
        $factsByPerson = array_fill_keys(array_keys($uniquePersonIds), []);
        foreach ($factsByPerson as $personId => $_) {
            foreach ($relationshipsByPerson[$personId] ?? [] as $relationship) {
                $factsByPerson[$personId][] = $this->facts(
                    $personId,
                    $resourceType,
                    $relationship['facility_id'],
                    $relationship['organization_unit_id'],
                    $relationship['cluster_id'],
                );
            }
            $factsByPerson[$personId] = $this->unique($factsByPerson[$personId]);
        }

        return $factsByPerson;
    }

    /** @param array<string, mixed> $principal */
    public function allows(array $principal, string $capability, string $personId, string $resourceType): bool
    {
        foreach ($this->forPerson($personId, $resourceType) as $facts) {
            if ($this->access->decide($principal, $capability, $facts)->isAllowed()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $personIds
     * @return array<string, bool>
     */
    public function allowsMany(array $principal, string $capability, array $personIds, string $resourceType): array
    {
        $allowed = array_fill_keys(array_values(array_unique($personIds)), false);
        foreach ($this->forPeople($personIds, $resourceType) as $personId => $facts) {
            foreach ($facts as $fact) {
                if ($this->access->decide($principal, $capability, $fact)->isAllowed()) {
                    $allowed[$personId] = true;
                    break;
                }
            }
        }

        return $allowed;
    }

    private function facts(
        string $personId,
        string $resourceType,
        ?string $facilityId,
        ?string $unitId,
        ?string $clusterId,
    ): RecordFacts {
        return new RecordFacts(
            ownerFacilityId: $facilityId,
            resourceType: $resourceType,
            classification: 'confidential',
            factsVersion: self::FACTS_VERSION,
            organizationUnitId: $unitId,
            recordId: $personId,
            sourceModule: 'organization',
            clusterId: $clusterId,
        );
    }

    /** @param list<RecordFacts> $facts @return list<RecordFacts> */
    private function unique(array $facts): array
    {
        $unique = [];
        foreach ($facts as $fact) {
            $key = implode('|', [
                $fact->clusterId ?? '',
                $fact->ownerFacilityId ?? '',
                $fact->organizationUnitId ?? '',
            ]);
            $unique[$key] = $fact;
        }

        return array_values($unique);
    }
}
