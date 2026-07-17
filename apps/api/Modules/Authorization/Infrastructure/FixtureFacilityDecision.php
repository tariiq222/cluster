<?php

namespace Modules\Authorization\Infrastructure;

use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;

final class FixtureFacilityDecision implements DecideAccess
{
    private const POLICY_VERSION = 'development-fixture-facility-v1';

    private const BOOTSTRAP_ADMIN_USER_ID = '018f6f7d-0c00-7000-8000-000000000021';

    /** @var list<string> */
    private const SUPPORTED_CAPABILITIES = [
        'work_record.submit',
        'work_record.read',
        'work_record.list',
        'organization.cluster.manage',
        'organization.cluster.read',
        'organization.facility.manage',
        'organization.facility.read',
    ];

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        if ($facts === null) {
            return $this->deny($capability, 'work_record', 'internal', 'unavailable', 'record_facts_unavailable');
        }

        if (! in_array($capability, self::SUPPORTED_CAPABILITIES, true)) {
            return $this->deny($capability, $facts->resourceType, $facts->classification, $facts->factsVersion, 'capability_not_supported');
        }

        if (str_starts_with($capability, 'organization.')) {
            $actorUserId = $actor['user_id'] ?? null;
            if (! is_string($actorUserId) || ! hash_equals(self::BOOTSTRAP_ADMIN_USER_ID, $actorUserId)) {
                return $this->deny($capability, $facts->resourceType, $facts->classification, $facts->factsVersion, 'bootstrap_admin_required');
            }

            return new AccessDecision(
                decision: 'allow',
                action: $capability,
                resourceType: $facts->resourceType,
                reasonCodes: ['bootstrap_admin'],
                policyVersion: self::POLICY_VERSION,
                factsVersion: $facts->factsVersion,
                classification: $facts->classification,
            );
        }

        $actorFacilityId = $actor['facility_id'] ?? null;
        if (! is_string($actorFacilityId) || $actorFacilityId === '') {
            return $this->deny($capability, $facts->resourceType, $facts->classification, $facts->factsVersion, 'actor_facility_missing');
        }

        if ($facts->ownerFacilityId === null || $facts->ownerFacilityId === '') {
            return $this->deny($capability, $facts->resourceType, $facts->classification, $facts->factsVersion, 'owner_facility_missing');
        }

        if (! hash_equals($facts->ownerFacilityId, $actorFacilityId)) {
            return $this->deny($capability, $facts->resourceType, $facts->classification, $facts->factsVersion, 'facility_scope_mismatch');
        }

        return new AccessDecision(
            decision: 'allow',
            action: $capability,
            resourceType: $facts->resourceType,
            reasonCodes: ['facility_scope_match'],
            policyVersion: self::POLICY_VERSION,
            factsVersion: $facts->factsVersion,
            classification: $facts->classification,
        );
    }

    private function deny(
        string $capability,
        string $resourceType,
        string $classification,
        string $factsVersion,
        string $reasonCode,
    ): AccessDecision {
        return new AccessDecision(
            decision: 'deny',
            action: $capability,
            resourceType: $resourceType,
            reasonCodes: [$reasonCode],
            policyVersion: self::POLICY_VERSION,
            factsVersion: $factsVersion,
            classification: $classification,
        );
    }
}
