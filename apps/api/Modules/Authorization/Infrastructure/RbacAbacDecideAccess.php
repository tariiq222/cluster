<?php

namespace Modules\Authorization\Infrastructure;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessDecision;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Domain\AuthorizationScope;
use Modules\Authorization\Domain\ClassificationLevel;
use Modules\Authorization\Domain\ExplicitDeny;
use Modules\Authorization\Domain\UuidV7;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use stdClass;
use Throwable;

final class RbacAbacDecideAccess implements DecideAccess
{
    private const POLICY_VERSION = 'rbac-abac-v2';

    public function __construct(
        private readonly GetActiveSupervisoryRelationships $supervisoryRelationships,
    ) {}

    public function decide(array $actor, string $capability, ?RecordFacts $facts): AccessDecision
    {
        $outcome = $this->evaluate($actor, $capability, $facts);

        $resourceType = $facts->resourceType ?? 'unknown';
        $classification = $facts->classification ?? 'unknown';
        $factsVersion = $facts->factsVersion ?? 'unavailable';

        $userId = $this->actorUserId($actor);
        $allowedActions = [];
        if ($outcome['decision'] === 'allow' && $facts !== null && $userId !== null) {
            $recordClassification = ClassificationLevel::tryFrom($facts->classification);
            if ($recordClassification !== null) {
                $allowedActions = $this->projectAllowedActions(
                    $userId,
                    $capability,
                    $facts,
                    $recordClassification,
                    $this->activeClassificationPolicy($facts->classification),
                );
            }
        }

        $fieldAccess = $this->resolveFieldAccess($facts, $capability);
        $decisionId = UuidV7::generate();
        $persisted = $this->persistDecision(
            $decisionId,
            $outcome['decision'],
            $capability,
            $facts,
            $outcome['reasonCodes'],
            $factsVersion,
            $classification,
            $userId,
            $actor,
        );

        if (! $persisted && $outcome['decision'] === 'allow' && $this->requiresSensitiveAudit($facts)) {
            return new AccessDecision(
                decision: 'deny',
                action: $capability,
                resourceType: $resourceType,
                reasonCodes: ['sensitive_audit_unavailable'],
                policyVersion: self::POLICY_VERSION,
                factsVersion: $factsVersion,
                classification: $classification,
                decisionId: $decisionId,
                fieldAccess: $fieldAccess,
            );
        }

        return new AccessDecision(
            decision: $outcome['decision'],
            action: $capability,
            resourceType: $resourceType,
            reasonCodes: $outcome['reasonCodes'],
            policyVersion: self::POLICY_VERSION,
            factsVersion: $factsVersion,
            classification: $classification,
            decisionId: $decisionId,
            allowedActions: $allowedActions,
            fieldAccess: $fieldAccess,
            obligations: $outcome['obligations'],
        );
    }

    /** @return array{decision: 'allow'|'deny', reasonCodes: list<string>, obligations: list<string>} */
    private function evaluate(array $actor, string $capability, ?RecordFacts $facts): array
    {
        if ($facts === null) {
            return $this->denyOutcome(['record_facts_unavailable']);
        }

        $userId = $this->actorUserId($actor);
        if ($userId === null) {
            return $this->denyOutcome(['actor_user_id_missing']);
        }

        if (CapabilityCatalog::supports($capability) === false) {
            return $this->denyOutcome(['capability_not_supported']);
        }

        if ($this->hasActiveExplicitDeny($userId, $capability, $facts)) {
            return $this->denyOutcome(['explicit_deny']);
        }

        $recordClassification = ClassificationLevel::tryFrom($facts->classification);
        if ($recordClassification === null) {
            return $this->denyOutcome(['classification_insufficient']);
        }

        if ($this->hasCoveringRoleDeny($userId, $capability, $facts)) {
            return $this->denyOutcome(['role_capability_denied']);
        }

        $classificationPolicy = $this->activeClassificationPolicy($facts->classification);

        $activeGrants = $this->activeGrants($userId, $capability);
        if ($activeGrants !== []) {
            return $this->grantOutcome(
                $activeGrants,
                'role_capability_allowed',
                $capability,
                $facts,
                $recordClassification,
                $classificationPolicy,
            );
        }

        $activeDelegationGrants = $this->activeDelegationGrants($userId, $capability);
        if ($activeDelegationGrants !== []) {
            return $this->grantOutcome(
                $activeDelegationGrants,
                'delegation_capability_allowed',
                $capability,
                $facts,
                $recordClassification,
                $classificationPolicy,
            );
        }

        $supervisoryRelationshipOutcome = $this->supervisoryRelationshipOutcome($actor, $capability, $facts);
        if ($supervisoryRelationshipOutcome !== null) {
            return $supervisoryRelationshipOutcome;
        }

        if ($this->hasExpiredGrant($userId, $capability)) {
            return $this->denyOutcome(['role_assignment_expired']);
        }

        if ($this->hasExpiredDelegation($userId, $capability)) {
            return $this->denyOutcome(['delegation_expired']);
        }

        if ($this->hasExpiredSupervisoryRelationship($actor, $capability, $facts)) {
            return $this->denyOutcome(['supervisory_relationship_expired']);
        }

        return $this->denyOutcome(['active_role_assignment_not_found']);
    }

    /**
     * @param  list<array{scope: ?AuthorizationScope, sensitivity: string}>  $activeGrants
     * @return array{decision: 'allow'|'deny', reasonCodes: list<string>, obligations: list<string>}
     */
    private function grantOutcome(
        array $activeGrants,
        string $grantReasonCode,
        string $capability,
        RecordFacts $facts,
        ClassificationLevel $recordClassification,
        ?stdClass $classificationPolicy,
    ): array {
        $scopeMatchedGrants = array_values(array_filter(
            $activeGrants,
            static fn (array $grant): bool => $grant['scope']?->covers($facts) ?? false,
        ));
        if ($scopeMatchedGrants === []) {
            return $this->denyOutcome(['organization_unit_scope_mismatch']);
        }

        $requiredClearance = $this->requiredClearance($recordClassification, $classificationPolicy);
        $classificationMatched = false;
        foreach ($scopeMatchedGrants as $grant) {
            $clearance = $this->clearanceForSensitivity($grant['sensitivity']);
            if ($clearance !== null && $clearance->isAtLeast($requiredClearance)) {
                $classificationMatched = true;
                break;
            }
        }
        if ($classificationMatched === false) {
            return $this->denyOutcome(['classification_insufficient']);
        }

        $transferDeny = $this->policyTransferDeny($capability, $classificationPolicy);
        if ($transferDeny !== null) {
            return $this->denyOutcome([$transferDeny]);
        }

        return [
            'decision' => 'allow',
            'reasonCodes' => [
                $grantReasonCode,
                'organization_unit_scope_matched',
                'classification_sufficient',
            ],
            'obligations' => $this->policyObligations($capability, $classificationPolicy),
        ];
    }

    /** @return array{decision: 'allow'|'deny', reasonCodes: list<string>, obligations: list<string>}|null */
    private function supervisoryRelationshipOutcome(
        array $actor,
        string $capability,
        RecordFacts $facts,
    ): ?array {
        if (! is_string($facts->organizationUnitId) || trim($facts->organizationUnitId) === '') {
            return null;
        }

        $actorOrganizationUnitIds = $this->actorOrganizationUnitIds($actor);
        if ($actorOrganizationUnitIds === null) {
            $providedUnitIds = $actor['organization_unit_ids'] ?? null;
            if (is_array($providedUnitIds) && $providedUnitIds !== []) {
                return $this->denyOutcome(['actor_organization_unit_scope_unavailable']);
            }

            return null;
        }

        $relationships = $this->relationshipsWithCapability($actorOrganizationUnitIds, $capability);
        if ($relationships === []) {
            return null;
        }

        $now = now()->utc();
        $activeRelationships = array_values(array_filter(
            $relationships,
            function (array $relationship) use ($now): bool {
                $validFrom = $relationship['valid_from'] ?? null;
                $validUntil = $relationship['valid_until'] ?? null;

                return is_string($validFrom) && is_string($validUntil)
                    && $this->windowContains($validFrom, $validUntil, $now);
            },
        ));
        if ($activeRelationships === []) {
            return null;
        }

        $scopeMatchedRelationships = array_values(array_filter(
            $activeRelationships,
            static function (array $relationship) use ($facts): bool {
                $targetOrganizationUnitId = $relationship['target_organization_unit_id'] ?? null;

                return is_string($targetOrganizationUnitId)
                    && hash_equals($facts->organizationUnitId, $targetOrganizationUnitId);
            },
        ));
        if ($scopeMatchedRelationships === []) {
            return $this->denyOutcome(['supervisory_relationship_scope_mismatch']);
        }

        $permittedRelationships = array_values(array_filter(
            $scopeMatchedRelationships,
            fn (array $relationship): bool => ($relationship['relationship_type'] ?? null) !== 'read_only'
                || $this->isReadOnlyCapability($capability),
        ));
        if ($permittedRelationships === []) {
            return $this->denyOutcome(['supervisory_relationship_read_only_restricted']);
        }

        return [
            'decision' => 'allow',
            'reasonCodes' => [
                'supervisory_relationship_capability_allowed',
                'supervisory_relationship_source_scope_matched',
                'supervisory_relationship_target_scope_matched',
            ],
            'obligations' => [],
        ];
    }

    private function hasCoveringRoleDeny(string $userId, string $capability, RecordFacts $facts): bool
    {
        $now = now()->utc();

        return DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'roles.id')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_assignments.user_id', $userId)
            ->where('role_assignments.status', 'active')
            ->where('role_assignments.start_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('role_assignments.end_at')
                    ->orWhere('role_assignments.end_at', '>', $now);
            })
            ->where('roles.status', 'active')
            ->where('role_capabilities.effect', 'deny')
            ->where('capabilities.status', 'active')
            ->where('capabilities.capability_code', $capability)
            ->select('role_assignments.scope_type', 'role_assignments.scope_id')
            ->get()
            ->contains(static function (stdClass $grant) use ($facts): bool {
                $scopeType = $grant->scope_type ?? null;
                $scopeId = $grant->scope_id ?? null;

                return AuthorizationScope::fromStorage(
                    is_string($scopeType) ? $scopeType : null,
                    is_string($scopeId) ? $scopeId : null,
                )?->covers($facts) ?? false;
            });
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

    /** @return list<array{scope: ?AuthorizationScope, sensitivity: string}> */
    private function activeGrants(string $userId, string $capability): array
    {
        $now = now()->utc();

        return $this->grantQuery($userId, $capability, 'allow')
            ->where('role_assignments.status', 'active')
            ->where('role_assignments.start_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('role_assignments.end_at')
                    ->orWhere('role_assignments.end_at', '>', $now);
            })
            ->select('role_assignments.scope_type', 'role_assignments.scope_id', 'capabilities.sensitivity')
            ->get()
            ->map(static function (stdClass $grant): ?array {
                return self::grantScopeAndSensitivity($grant);
            })
            ->filter()
            ->values()
            ->all();
    }

    private function hasExpiredGrant(string $userId, string $capability): bool
    {
        return $this->grantQuery($userId, $capability, 'allow')
            ->where('role_assignments.status', 'active')
            ->whereNotNull('role_assignments.end_at')
            ->where('role_assignments.end_at', '<=', now()->utc())
            ->exists();
    }

    /** @return list<array{scope: ?AuthorizationScope, sensitivity: string}> */
    private function activeDelegationGrants(string $delegateUserId, string $capability): array
    {
        $now = now()->utc();

        return $this->delegationQuery($delegateUserId, $capability)
            ->where('delegations.status', 'active')
            ->where('delegations.start_at', '<=', $now)
            ->where('delegations.end_at', '>', $now)
            ->select('delegations.scope_type', 'delegations.scope_id', 'capabilities.sensitivity')
            ->get()
            ->map(static function (stdClass $grant): ?array {
                return self::grantScopeAndSensitivity($grant);
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

    private function hasExpiredSupervisoryRelationship(array $actor, string $capability, RecordFacts $facts): bool
    {
        if (! is_string($facts->organizationUnitId) || trim($facts->organizationUnitId) === '') {
            return false;
        }

        $actorOrganizationUnitIds = $this->actorOrganizationUnitIds($actor);
        if ($actorOrganizationUnitIds === null) {
            return false;
        }

        $now = now()->utc();
        foreach ($this->relationshipsWithCapability($actorOrganizationUnitIds, $capability) as $relationship) {
            $targetOrganizationUnitId = $relationship['target_organization_unit_id'] ?? null;
            $validUntil = $relationship['valid_until'] ?? null;
            if (is_string($targetOrganizationUnitId)
                && hash_equals($facts->organizationUnitId, $targetOrganizationUnitId)
                && is_string($validUntil)
                && $this->windowExpired($validUntil, $now)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> */
    private function relationshipsWithCapability(array $actorOrganizationUnitIds, string $capability): array
    {
        $moduleCode = explode('.', $capability, 2)[0];

        $matched = [];
        foreach ($actorOrganizationUnitIds as $sourceOrganizationUnitId) {
            foreach ($this->supervisoryRelationships->forSourceOrganizationUnit($sourceOrganizationUnitId) as $relationship) {
                foreach ($relationship['relationship_capabilities'] as $relationshipCapability) {
                    if ($relationshipCapability['module_code'] === $moduleCode
                        && $relationshipCapability['capability_code'] === $capability) {
                        $matched[] = $relationship;
                        break;
                    }
                }
            }
        }

        return $matched;
    }

    private function grantQuery(string $userId, string $capability, string $effect): Builder
    {
        return DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'roles.id')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_assignments.user_id', $userId)
            ->where('roles.status', 'active')
            ->where('role_capabilities.effect', $effect)
            ->where('capabilities.status', 'active')
            ->where('capabilities.capability_code', $capability);
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
            ->where('capabilities.status', 'active');
    }

    /** @return array{scope: ?AuthorizationScope, sensitivity: string}|null */
    private static function grantScopeAndSensitivity(stdClass $grant): ?array
    {
        $scopeType = $grant->scope_type ?? null;
        $scopeId = $grant->scope_id ?? null;
        $sensitivity = $grant->sensitivity ?? null;

        if (($scopeType !== null && ! is_string($scopeType))
            || ($scopeId !== null && ! is_string($scopeId))
            || ! is_string($sensitivity)) {
            return null;
        }

        return [
            'scope' => AuthorizationScope::fromStorage($scopeType, $scopeId),
            'sensitivity' => $sensitivity,
        ];
    }

    /** @return list<string> */
    private function projectAllowedActions(
        string $userId,
        string $capability,
        RecordFacts $facts,
        ClassificationLevel $recordClassification,
        ?stdClass $classificationPolicy,
    ): array {
        $moduleCode = explode('.', $capability, 2)[0];
        $now = now()->utc();
        $requiredClearance = $this->requiredClearance($recordClassification, $classificationPolicy);

        $roleGrants = DB::table('role_assignments')
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->join('role_capabilities', 'role_capabilities.role_id', '=', 'roles.id')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_assignments.user_id', $userId)
            ->where('role_assignments.status', 'active')
            ->where('role_assignments.start_at', '<=', $now)
            ->where(function ($query) use ($now): void {
                $query->whereNull('role_assignments.end_at')
                    ->orWhere('role_assignments.end_at', '>', $now);
            })
            ->where('roles.status', 'active')
            ->where('role_capabilities.effect', 'allow')
            ->where('capabilities.status', 'active')
            ->where('capabilities.module_code', $moduleCode)
            ->select('capabilities.capability_code', 'capabilities.sensitivity', 'role_assignments.scope_type', 'role_assignments.scope_id')
            ->get();

        $delegationGrants = DB::table('delegations')
            ->join('delegation_capabilities', 'delegation_capabilities.delegation_id', '=', 'delegations.id')
            ->join('capabilities', 'capabilities.capability_code', '=', 'delegation_capabilities.capability_code')
            ->where('delegations.delegate_user_id', $userId)
            ->where('delegations.module_code', $moduleCode)
            ->where('delegations.status', 'active')
            ->where('delegations.start_at', '<=', $now)
            ->where('delegations.end_at', '>', $now)
            ->where('capabilities.module_code', $moduleCode)
            ->where('capabilities.status', 'active')
            ->select('capabilities.capability_code', 'capabilities.sensitivity', 'delegations.scope_type', 'delegations.scope_id')
            ->get();

        $actions = [];
        foreach ($roleGrants->concat($delegationGrants) as $grant) {
            $capabilityCode = $grant->capability_code ?? null;
            $sensitivity = $grant->sensitivity ?? null;
            $scopeType = $grant->scope_type ?? null;
            $scopeId = $grant->scope_id ?? null;
            if (! is_string($capabilityCode) || ! is_string($sensitivity)) {
                continue;
            }
            $scope = AuthorizationScope::fromStorage(
                is_string($scopeType) ? $scopeType : null,
                is_string($scopeId) ? $scopeId : null,
            );
            if ($scope === null || ! $scope->covers($facts)) {
                continue;
            }
            $clearance = $this->clearanceForSensitivity($sensitivity);
            if ($clearance === null || ! $clearance->isAtLeast($requiredClearance)) {
                continue;
            }
            $actions[] = explode('.', $capabilityCode, 2)[1] ?? $capabilityCode;
        }

        $actions = array_values(array_unique($actions));
        sort($actions, SORT_STRING);

        return $actions;
    }

    /** @return array<string, 'hidden'|'masked'|'readonly'|'editable'> */
    private function resolveFieldAccess(?RecordFacts $facts, string $capability): array
    {
        if ($facts === null || ! is_string($facts->fieldPolicyKey) || trim($facts->fieldPolicyKey) === '') {
            return [];
        }

        $template = DB::table('field_access_templates')
            ->where('field_policy_key', $facts->fieldPolicyKey)
            ->where('is_active', true)
            ->first(['policy_definition']);

        if ($template === null) {
            return ['*' => 'hidden'];
        }

        $policyDefinition = $template->policy_definition ?? null;
        $decoded = is_string($policyDefinition) ? json_decode($policyDefinition, true) : null;
        $fields = is_array($decoded) && isset($decoded['fields']) && is_array($decoded['fields'])
            ? $decoded['fields']
            : [];

        $readOnlyCapability = $this->isReadOnlyCapability($capability);
        $fieldAccess = [];
        foreach ($fields as $fieldPath => $fieldRule) {
            if (! is_string($fieldPath) || trim($fieldPath) === '') {
                continue;
            }
            $access = match (is_string($fieldRule) ? $fieldRule : 'hide') {
                'edit' => 'editable',
                'read' => 'readonly',
                'mask' => 'masked',
                default => 'hidden',
            };
            if ($readOnlyCapability && $access === 'editable') {
                $access = 'readonly';
            }
            $fieldAccess[$fieldPath] = $access;
        }

        return $fieldAccess === [] ? ['*' => 'hidden'] : $fieldAccess;
    }

    private function activeClassificationPolicy(string $classification): ?stdClass
    {
        return DB::table('classification_policies')
            ->where('classification_code', $classification)
            ->where('is_active', true)
            ->first(['minimum_capability', 'export_policy', 'download_policy']);
    }

    private function requiredClearance(ClassificationLevel $recordClassification, ?stdClass $classificationPolicy): ClassificationLevel
    {
        if ($classificationPolicy === null) {
            return $recordClassification;
        }

        $minimumCapability = $classificationPolicy->minimum_capability ?? null;
        if (! is_string($minimumCapability) || trim($minimumCapability) === '') {
            return $recordClassification;
        }

        $sensitivity = DB::table('capabilities')
            ->where('capability_code', $minimumCapability)
            ->where('status', 'active')
            ->value('sensitivity');
        $floor = is_string($sensitivity) ? $this->clearanceForSensitivity($sensitivity) : null;

        return $floor !== null && $floor->compare($recordClassification) > 0
            ? $floor
            : $recordClassification;
    }

    private function policyTransferDeny(string $capability, ?stdClass $classificationPolicy): ?string
    {
        if ($classificationPolicy === null) {
            return null;
        }
        if (str_ends_with($capability, '.export') && ($classificationPolicy->export_policy ?? null) === 'deny') {
            return 'classification_export_denied';
        }
        if (str_ends_with($capability, '.download') && ($classificationPolicy->download_policy ?? null) === 'deny') {
            return 'classification_download_denied';
        }

        return null;
    }

    /** @return list<string> */
    private function policyObligations(string $capability, ?stdClass $classificationPolicy): array
    {
        if ($classificationPolicy === null) {
            return [];
        }

        $obligations = [];
        if (str_ends_with($capability, '.export') && ($classificationPolicy->export_policy ?? null) === 'audit') {
            $obligations[] = 'audit';
        }
        if (str_ends_with($capability, '.download') && ($classificationPolicy->download_policy ?? null) === 'audit') {
            $obligations[] = 'audit';
        }

        return array_values(array_unique($obligations));
    }

    private function clearanceForSensitivity(string $sensitivity): ?ClassificationLevel
    {
        return match ($sensitivity) {
            'normal' => ClassificationLevel::INTERNAL,
            'sensitive' => ClassificationLevel::CONFIDENTIAL,
            'critical' => ClassificationLevel::TOP_SECRET,
            default => null,
        };
    }

    /** @param  list<string>  $reasonCodes */
    private function persistDecision(
        string $decisionId,
        string $decision,
        string $capability,
        ?RecordFacts $facts,
        array $reasonCodes,
        string $factsVersion,
        string $classification,
        ?string $userId,
        array $actor,
    ): bool {
        if ($userId === null) {
            return true;
        }

        $now = now()->utc();
        $correlationId = $actor['correlation_id'] ?? null;
        $correlationId = is_string($correlationId) && trim($correlationId) !== ''
            ? $correlationId
            : UuidV7::generate();
        $recordSensitiveEvent = $decision === 'allow'
            && $this->requiresSensitiveAudit($facts)
            && $facts !== null
            && is_string($facts->recordId)
            && trim($facts->recordId) !== '';

        try {
            DB::transaction(function () use (
                $decisionId,
                $decision,
                $capability,
                $facts,
                $reasonCodes,
                $factsVersion,
                $classification,
                $userId,
                $actor,
                $now,
                $correlationId,
                $recordSensitiveEvent,
            ): void {
                DB::table('access_decisions')->insert([
                    'id' => $decisionId,
                    'decision' => $decision,
                    'action' => $capability,
                    'resource_type' => $facts->resourceType ?? 'unknown',
                    'resource_id' => $facts?->recordId,
                    'reason_codes' => json_encode($reasonCodes, JSON_THROW_ON_ERROR),
                    'policy_version' => self::POLICY_VERSION,
                    'facts_version' => $factsVersion,
                    'authorization_trace_id' => UuidV7::generate(),
                    'evaluated_at' => $now,
                    'correlation_id' => $correlationId,
                    'classification' => $classification,
                    'access_context' => json_encode($this->sanitizedAccessContext($userId, $actor), JSON_THROW_ON_ERROR),
                    'actor_user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($recordSensitiveEvent && is_string($facts->recordId)) {
                    $originalUserId = $actor['original_user_id'] ?? null;
                    DB::table('sensitive_access_events')->insert([
                        'id' => UuidV7::generate(),
                        'access_decision_id' => $decisionId,
                        'actor_user_id' => $userId,
                        'original_actor_user_id' => is_string($originalUserId) && trim($originalUserId) !== ''
                            ? $originalUserId
                            : $userId,
                        'resource_type' => $facts->resourceType,
                        'resource_id' => $facts->recordId,
                        'action' => $capability,
                        'classification_code' => $facts->classification,
                        'correlation_id' => $correlationId,
                        'idempotency_key_hash' => hash('sha256', $decisionId),
                        'occurred_at' => $now,
                        'recorded_at' => $now,
                    ]);
                }
            });
        } catch (Throwable $exception) {
            logger()->error('authorization.access_decision_persist_failed', [
                'decision_id' => $decisionId,
                'action' => $capability,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /** @return array<string, string|list<string>> */
    private function sanitizedAccessContext(string $userId, array $actor): array
    {
        $context = ['user_id' => $userId];

        $facilityId = $actor['facility_id'] ?? null;
        if (is_string($facilityId) && trim($facilityId) !== '') {
            $context['facility_id'] = $facilityId;
        }

        $organizationUnitIds = $this->actorOrganizationUnitIds($actor);
        if ($organizationUnitIds !== null) {
            $context['organization_unit_ids'] = $organizationUnitIds;
        }

        return $context;
    }

    private function requiresSensitiveAudit(?RecordFacts $facts): bool
    {
        return $facts !== null
            && (ClassificationLevel::tryFrom($facts->classification)?->requiresSensitiveAccessAudit() ?? false);
    }

    private function windowContains(string $validFrom, string $validUntil, DateTimeInterface $now): bool
    {
        try {
            $from = new DateTimeImmutable($validFrom);
            $until = new DateTimeImmutable($validUntil);
        } catch (Exception) {
            return false;
        }

        return $from <= $now && $until > $now;
    }

    private function windowExpired(string $validUntil, DateTimeInterface $now): bool
    {
        try {
            $until = new DateTimeImmutable($validUntil);
        } catch (Exception) {
            return false;
        }

        return $until <= $now;
    }

    private function actorUserId(array $actor): ?string
    {
        $userId = $actor['user_id'] ?? null;

        return is_string($userId) && trim($userId) !== '' ? $userId : null;
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

    /**
     * @param  list<string>  $reasonCodes
     * @return array{decision: 'deny', reasonCodes: list<string>, obligations: list<string>}
     */
    private function denyOutcome(array $reasonCodes): array
    {
        return [
            'decision' => 'deny',
            'reasonCodes' => $reasonCodes,
            'obligations' => [],
        ];
    }
}
