<?php

namespace Modules\Authorization\Infrastructure;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Domain\ClassificationLevel;
use Modules\Authorization\Domain\ExplicitDeny;
use stdClass;

final class RbacAbacDecideAccess implements DecideAccess
{
    private const POLICY_VERSION = 'rbac-abac-v1';

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        if ($facts === null) {
            return $this->deny($capability, 'unknown', 'unknown', 'unavailable', 'record_facts_unavailable');
        }

        $userId = $actor['user_id'] ?? null;
        if (is_string($userId) === false || trim($userId) === '') {
            return $this->deny(
                $capability,
                $facts->resourceType,
                $facts->classification,
                $facts->factsVersion,
                'actor_user_id_missing',
            );
        }

        if (CapabilityCatalog::supports($capability) === false) {
            return $this->deny(
                $capability,
                $facts->resourceType,
                $facts->classification,
                $facts->factsVersion,
                'capability_not_supported',
            );
        }

        if ($this->hasActiveExplicitDeny($userId, $capability, $facts)) {
            return $this->deny(
                $capability,
                $facts->resourceType,
                $facts->classification,
                $facts->factsVersion,
                'explicit_deny',
            );
        }

        $recordClassification = ClassificationLevel::tryFrom($facts->classification);
        if ($recordClassification === null) {
            return $this->deny(
                $capability,
                $facts->resourceType,
                $facts->classification,
                $facts->factsVersion,
                'classification_insufficient',
            );
        }

        $activeGrants = $this->activeGrants($userId, $capability);
        $grantReasonCode = 'role_capability_allowed';
        if ($activeGrants === []) {
            $activeGrants = $this->activeDelegationGrants($userId, $capability);
            $grantReasonCode = 'delegation_capability_allowed';

            if ($activeGrants !== []) {
                return $this->allowForGrants(
                    $activeGrants,
                    $grantReasonCode,
                    $capability,
                    $facts,
                    $recordClassification,
                );
            }

            $supervisoryRelationshipDecision = $this->supervisoryRelationshipDecision(
                $actor,
                $capability,
                $facts,
            );
            if ($supervisoryRelationshipDecision !== null) {
                return $supervisoryRelationshipDecision;
            }

            if ($this->hasExpiredGrant($userId, $capability)) {
                return $this->deny(
                    $capability,
                    $facts->resourceType,
                    $facts->classification,
                    $facts->factsVersion,
                    'role_assignment_expired',
                );
            }

            if ($this->hasExpiredDelegation($userId, $capability)) {
                return $this->deny(
                    $capability,
                    $facts->resourceType,
                    $facts->classification,
                    $facts->factsVersion,
                    'delegation_expired',
                );
            }

            if ($this->hasExpiredSupervisoryRelationship($actor, $capability, $facts)) {
                return $this->deny(
                    $capability,
                    $facts->resourceType,
                    $facts->classification,
                    $facts->factsVersion,
                    'supervisory_relationship_expired',
                );
            }

            return $this->deny(
                $capability,
                $facts->resourceType,
                $facts->classification,
                $facts->factsVersion,
                'active_role_assignment_not_found',
            );
        }

        return $this->allowForGrants(
            $activeGrants,
            $grantReasonCode,
            $capability,
            $facts,
            $recordClassification,
        );
    }

    private function supervisoryRelationshipDecision(
        array $actor,
        string $capability,
        RecordFacts $facts,
    ): ?AccessDecision {
        if (! is_string($facts->organizationUnitId) || trim($facts->organizationUnitId) === '') {
            return null;
        }

        $relationships = $this->activeSupervisoryRelationships($capability);
        if ($relationships === []) {
            return null;
        }

        $actorOrganizationUnitIds = $this->actorOrganizationUnitIds($actor);
        if ($actorOrganizationUnitIds === null) {
            return $this->deny(
                $capability,
                $facts->resourceType,
                $facts->classification,
                $facts->factsVersion,
                'actor_organization_unit_scope_unavailable',
            );
        }

        $scopeMatchedRelationships = array_values(array_filter(
            $relationships,
            function (array $relationship) use ($actorOrganizationUnitIds, $facts): bool {
                return hash_equals($relationship['target_organization_unit_id'], $facts->organizationUnitId)
                    && in_array($relationship['source_organization_unit_id'], $actorOrganizationUnitIds, true);
            },
        ));
        if ($scopeMatchedRelationships === []) {
            return $this->deny(
                $capability,
                $facts->resourceType,
                $facts->classification,
                $facts->factsVersion,
                'supervisory_relationship_scope_mismatch',
            );
        }

        $permittedRelationships = array_values(array_filter(
            $scopeMatchedRelationships,
            fn (array $relationship): bool => $relationship['relationship_type'] !== 'read_only'
                || $this->isReadOnlyCapability($capability),
        ));
        if ($permittedRelationships === []) {
            return $this->deny(
                $capability,
                $facts->resourceType,
                $facts->classification,
                $facts->factsVersion,
                'supervisory_relationship_read_only_restricted',
            );
        }

        return new AccessDecision(
            decision: 'allow',
            action: $capability,
            resourceType: $facts->resourceType,
            reasonCodes: [
                'supervisory_relationship_capability_allowed',
                'supervisory_relationship_source_scope_matched',
                'supervisory_relationship_target_scope_matched',
            ],
            policyVersion: self::POLICY_VERSION,
            factsVersion: $facts->factsVersion,
            classification: $facts->classification,
        );
    }

    /** @param list<array{scope_id: ?string, sensitivity: string}> $activeGrants */
    private function allowForGrants(
        array $activeGrants,
        string $grantReasonCode,
        string $capability,
        RecordFacts $facts,
        ClassificationLevel $recordClassification,
    ): AccessDecision {
        $scopeMatchedGrants = array_values(array_filter($activeGrants, function (array $grant) use ($facts): bool {
            if ($grant['scope_id'] === null) {
                return true;
            }

            return is_string($facts->organizationUnitId)
                && hash_equals($grant['scope_id'], $facts->organizationUnitId);
        }));
        if ($scopeMatchedGrants === []) {
            return $this->deny(
                $capability,
                $facts->resourceType,
                $facts->classification,
                $facts->factsVersion,
                'organization_unit_scope_mismatch',
            );
        }

        $classificationMatched = false;
        foreach ($scopeMatchedGrants as $grant) {
            if ($this->permitsClassification($grant['sensitivity'], $recordClassification)) {
                $classificationMatched = true;
                break;
            }
        }
        if ($classificationMatched === false) {
            return $this->deny(
                $capability,
                $facts->resourceType,
                $facts->classification,
                $facts->factsVersion,
                'classification_insufficient',
            );
        }

        return new AccessDecision(
            decision: 'allow',
            action: $capability,
            resourceType: $facts->resourceType,
            reasonCodes: [
                $grantReasonCode,
                'organization_unit_scope_matched',
                'classification_sufficient',
            ],
            policyVersion: self::POLICY_VERSION,
            factsVersion: $facts->factsVersion,
            classification: $facts->classification,
        );
    }

    private function hasActiveExplicitDeny(string $userId, string $capability, RecordFacts $facts): bool
    {
        $now = now()->utc();

        return DB::table('explicit_denies')
            ->where('capability_code', $capability)
            ->where('issued_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            })
            ->where(function ($query) use ($userId): void {
                $query->whereNull('user_id')
                    ->orWhere('user_id', $userId);
            })
            ->where(function ($query) use ($facts): void {
                $organizationUnitId = $facts->organizationUnitId;
                if (! is_string($organizationUnitId) || trim($organizationUnitId) === '') {
                    $query->whereNull('organization_unit_id');

                    return;
                }

                $query->whereNull('organization_unit_id')
                    ->orWhere('organization_unit_id', $organizationUnitId);
            })
            ->where(function ($query) use ($facts): void {
                $query->whereNull('classification')
                    ->orWhere('classification', $facts->classification);
            })
            ->select('resource_pattern')
            ->get()
            ->contains(static function (stdClass $deny) use ($facts): bool {
                $resourcePattern = $deny->resource_pattern ?? null;
                if ($resourcePattern === null) {
                    return true;
                }
                if (! is_string($resourcePattern)) {
                    return true;
                }
                if (! ExplicitDeny::isValidResourcePattern($resourcePattern)) {
                    return true;
                }

                return ExplicitDeny::matchesResourceType($resourcePattern, $facts->resourceType);
            });
    }

    /** @return list<array{scope_id: ?string, sensitivity: string}> */
    private function activeGrants(string $userId, string $capability): array
    {
        $now = now()->utc();

        return $this->grantQuery($userId, $capability)
            ->where('role_assignments.status', 'active')
            ->where('role_assignments.start_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('role_assignments.end_at')
                    ->orWhere('role_assignments.end_at', '>', $now);
            })
            ->get()
            ->map(static function (stdClass $grant): ?array {
                $scopeId = $grant->scope_id ?? null;
                $sensitivity = $grant->sensitivity ?? null;

                if (($scopeId !== null && ! is_string($scopeId)) || ! is_string($sensitivity)) {
                    return null;
                }

                return [
                    'scope_id' => $scopeId,
                    'sensitivity' => $sensitivity,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function hasExpiredGrant(string $userId, string $capability): bool
    {
        return $this->grantQuery($userId, $capability)
            ->where('role_assignments.status', 'active')
            ->whereNotNull('role_assignments.end_at')
            ->where('role_assignments.end_at', '<=', now()->utc())
            ->exists();
    }

    /** @return list<array{scope_id: ?string, sensitivity: string}> */
    private function activeDelegationGrants(string $delegateUserId, string $capability): array
    {
        $now = now()->utc();

        return $this->delegationQuery($delegateUserId, $capability)
            ->where('delegations.status', 'active')
            ->where('delegations.start_at', '<=', $now)
            ->where('delegations.end_at', '>', $now)
            ->get()
            ->map(static function (stdClass $grant): ?array {
                $scopeId = $grant->scope_id ?? null;
                $sensitivity = $grant->sensitivity ?? null;

                if (($scopeId !== null && ! is_string($scopeId)) || ! is_string($sensitivity)) {
                    return null;
                }

                return [
                    'scope_id' => $scopeId,
                    'sensitivity' => $sensitivity,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function hasExpiredDelegation(string $delegateUserId, string $capability): bool
    {
        return $this->delegationQuery($delegateUserId, $capability)
            ->where('delegations.status', 'active')
            ->where('delegations.end_at', '<=', now()->utc())
            ->exists();
    }

    /** @return list<array{source_organization_unit_id: string, target_organization_unit_id: string, relationship_type: string}> */
    private function activeSupervisoryRelationships(string $capability): array
    {
        $now = now()->utc();
        $moduleCode = explode('.', $capability, 2)[0];

        return DB::table('supervisory_relationships')
            ->join(
                'relationship_capabilities',
                'relationship_capabilities.supervisory_relationship_id',
                '=',
                'supervisory_relationships.id',
            )
            ->where('relationship_capabilities.module_code', $moduleCode)
            ->where('relationship_capabilities.capability_code', $capability)
            ->where('supervisory_relationships.valid_from', '<=', $now)
            ->where('supervisory_relationships.valid_until', '>', $now)
            ->select(
                'supervisory_relationships.source_organization_unit_id',
                'supervisory_relationships.target_organization_unit_id',
                'supervisory_relationships.relationship_type',
            )
            ->get()
            ->map(static function (stdClass $relationship): ?array {
                $sourceOrganizationUnitId = $relationship->source_organization_unit_id ?? null;
                $targetOrganizationUnitId = $relationship->target_organization_unit_id ?? null;
                $relationshipType = $relationship->relationship_type ?? null;

                if (! is_string($sourceOrganizationUnitId)
                    || ! is_string($targetOrganizationUnitId)
                    || ! is_string($relationshipType)) {
                    return null;
                }

                return [
                    'source_organization_unit_id' => $sourceOrganizationUnitId,
                    'target_organization_unit_id' => $targetOrganizationUnitId,
                    'relationship_type' => $relationshipType,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function hasExpiredSupervisoryRelationship(array $actor, string $capability, RecordFacts $facts): bool
    {
        if (! is_string($facts->organizationUnitId) || trim($facts->organizationUnitId) === '') {
            return false;
        }

        $actorOrganizationUnitIds = $this->actorOrganizationUnitIds($actor);
        if ($actorOrganizationUnitIds === null) {
            return false;
        }

        $moduleCode = explode('.', $capability, 2)[0];

        return DB::table('supervisory_relationships')
            ->join(
                'relationship_capabilities',
                'relationship_capabilities.supervisory_relationship_id',
                '=',
                'supervisory_relationships.id',
            )
            ->where('relationship_capabilities.module_code', $moduleCode)
            ->where('relationship_capabilities.capability_code', $capability)
            ->where('supervisory_relationships.target_organization_unit_id', $facts->organizationUnitId)
            ->whereIn('supervisory_relationships.source_organization_unit_id', $actorOrganizationUnitIds)
            ->where('supervisory_relationships.valid_until', '<=', now()->utc())
            ->exists();
    }

    private function grantQuery(string $userId, string $capability): Builder
    {
        return DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'roles.id')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_assignments.user_id', $userId)
            ->where('roles.status', 'active')
            ->where('role_capabilities.effect', 'allow')
            ->where('capabilities.status', 'active')
            ->where('capabilities.capability_code', $capability)
            ->select('role_assignments.scope_id', 'capabilities.sensitivity');
    }

    private function delegationQuery(string $delegateUserId, string $capability): Builder
    {
        $moduleCode = explode('.', $capability, 2)[0];

        return DB::table('delegations')
            ->join('delegation_capabilities', 'delegation_capabilities.delegation_id', '=', 'delegations.id')
            ->join('capabilities', 'capabilities.capability_code', '=', 'delegation_capabilities.capability_code')
            ->where('delegations.delegate_user_id', $delegateUserId)
            ->where('delegations.module_code', $moduleCode)
            ->where('delegation_capabilities.capability_code', $capability)
            ->where('capabilities.module_code', $moduleCode)
            ->where('capabilities.status', 'active')
            ->select('delegations.scope_id', 'capabilities.sensitivity');
    }

    private function permitsClassification(string $sensitivity, ClassificationLevel $recordClassification): bool
    {
        $clearance = match ($sensitivity) {
            'normal' => ClassificationLevel::INTERNAL,
            'sensitive' => ClassificationLevel::CONFIDENTIAL,
            'critical' => ClassificationLevel::TOP_SECRET,
            default => null,
        };

        return $clearance !== null && $clearance->isAtLeast($recordClassification);
    }

    /** @return list<string>|null */
    private function actorOrganizationUnitIds(array $actor): ?array
    {
        $organizationUnitIds = $actor['organization_unit_ids'] ?? null;
        if (! is_array($organizationUnitIds) || $organizationUnitIds === []) {
            return null;
        }

        $validOrganizationUnitIds = [];
        foreach ($organizationUnitIds as $organizationUnitId) {
            if (! is_string($organizationUnitId) || trim($organizationUnitId) === '') {
                return null;
            }

            $validOrganizationUnitIds[] = $organizationUnitId;
        }

        return array_values(array_unique($validOrganizationUnitIds));
    }

    private function isReadOnlyCapability(string $capability): bool
    {
        return str_ends_with($capability, '.read')
            || str_ends_with($capability, '.list')
            || str_ends_with($capability, '.reference')
            || str_ends_with($capability, '.get-upload-status');
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
