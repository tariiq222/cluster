<?php

namespace Modules\Authorization\Infrastructure;

use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;

final class FixtureFacilityDecision implements DecideAccess
{
    private const POLICY_VERSION = 'development-fixture-facility-v1';

    private const BOOTSTRAP_ADMIN_USER_ID = '018f6f7d-0c00-7000-8000-000000000021';

    private const IMPORT_APPROVER_USER_ID = '018f6f7d-0c00-7000-8000-000000000022';

    /**
     * The fixture engine never persists access_decisions or
     * sensitive_access_events, so the read-side evaluation IS decide().
     */
    public function evaluateOnly(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        return $this->decide($actor, $capability, $facts);
    }

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        if ($facts === null) {
            return $this->deny($capability, 'resource', 'internal', 'unavailable', 'record_facts_unavailable');
        }

        if (! CapabilityCatalog::supports($capability)) {
            return $this->deny($capability, $facts->resourceType, $facts->classification, $facts->factsVersion, 'capability_not_supported');
        }

        if (str_starts_with($capability, 'organization.temporary-assignment.')
            && ($facts->organizationUnitId === null || $facts->organizationUnitId === '')) {
            return $this->deny($capability, $facts->resourceType, $facts->classification, $facts->factsVersion, 'organization_unit_scope_missing');
        }

        if (str_starts_with($capability, 'organization.')
            || str_starts_with($capability, 'identity.')
            || str_starts_with($capability, 'documents.')) {
            $actorUserId = $actor['user_id'] ?? null;
            if (in_array($capability, ['organization.import.approve', 'organization.import.read'], true)
                && is_string($actorUserId)
                && hash_equals(self::IMPORT_APPROVER_USER_ID, $actorUserId)) {
                return new AccessDecision(
                    decision: 'allow',
                    action: $capability,
                    resourceType: $facts->resourceType,
                    reasonCodes: ['designated_import_approver'],
                    policyVersion: self::POLICY_VERSION,
                    factsVersion: $facts->factsVersion,
                    classification: $facts->classification,
                );
            }
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
            // Cluster-scoped facts (e.g. the published report definition
            // catalog) are shared with every authenticated fixture principal.
            if ($facts->clusterId !== null && $facts->clusterId !== '') {
                return new AccessDecision(
                    decision: 'allow',
                    action: $capability,
                    resourceType: $facts->resourceType,
                    reasonCodes: ['cluster_scope_shared'],
                    policyVersion: self::POLICY_VERSION,
                    factsVersion: $facts->factsVersion,
                    classification: $facts->classification,
                );
            }

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
