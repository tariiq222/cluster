<?php

namespace Modules\Authorization\Infrastructure;

use Modules\Authorization\Contracts\AccessDecision as AuthorizationAccessDecision;
use Modules\Authorization\Contracts\DecideAccess as AuthorizationDecideAccess;
use Modules\Authorization\Contracts\RecordFacts as AuthorizationRecordFacts;
use Modules\Organization\Contracts\AccessDecision as OrganizationAccessDecision;
use Modules\Organization\Contracts\DecideAccess as OrganizationDecideAccess;
use Modules\Organization\Contracts\RecordFacts as OrganizationRecordFacts;

/**
 * Adapts the Organization-owned DecideAccess contract onto the Authorization
 * engine. The Authorization module is free to import the Organization contract
 * (higher rank > lower rank) so we can translate the two type hierarchies
 * without leaking the Authorization types back into lower-ranked callers.
 */
final class OrganizationDecideAccessAdapter implements OrganizationDecideAccess
{
    public function __construct(private readonly AuthorizationDecideAccess $inner) {}

    public function decide(array $actor, string $capability, ?OrganizationRecordFacts $facts): OrganizationAccessDecision
    {
        $authorizationDecision = $this->inner->decide(
            $actor,
            $capability,
            $facts === null ? null : $this->toAuthorizationFacts($facts),
        );

        return $this->toOrganizationDecision($authorizationDecision);
    }

    private function toAuthorizationFacts(OrganizationRecordFacts $facts): AuthorizationRecordFacts
    {
        return new AuthorizationRecordFacts(
            ownerFacilityId: $facts->ownerFacilityId,
            resourceType: $facts->resourceType,
            classification: $facts->classification,
            factsVersion: $facts->factsVersion,
            organizationUnitId: $facts->organizationUnitId,
            recordId: $facts->recordId,
            sourceModule: $facts->sourceModule,
            clusterId: $facts->clusterId,
            createdByUserId: $facts->createdByUserId,
            ownerUserId: $facts->ownerUserId,
            responsibleUserId: $facts->responsibleUserId,
            sharedUnitIds: $facts->sharedUnitIds,
            sharedUserIds: $facts->sharedUserIds,
            participantIds: $facts->participantIds,
            lifecycleState: $facts->lifecycleState,
            workflowState: $facts->workflowState,
            fieldPolicyKey: $facts->fieldPolicyKey,
            workTypeVersionId: $facts->workTypeVersionId,
            legalHold: $facts->legalHold,
            lockVersion: $facts->lockVersion,
        );
    }

    private function toOrganizationDecision(AuthorizationAccessDecision $decision): OrganizationAccessDecision
    {
        return new OrganizationAccessDecision(
            decision: $decision->decision,
            action: $decision->action,
            resourceType: $decision->resourceType,
            reasonCodes: $decision->reasonCodes,
            policyVersion: $decision->policyVersion,
            factsVersion: $decision->factsVersion,
            classification: $decision->classification,
            decisionId: $decision->decisionId,
            allowedActions: $decision->allowedActions,
            fieldAccess: $decision->fieldAccess,
            obligations: $decision->obligations,
        );
    }
}
