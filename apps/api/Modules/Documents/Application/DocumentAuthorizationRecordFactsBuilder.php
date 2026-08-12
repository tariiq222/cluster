<?php

declare(strict_types=1);

namespace Modules\Documents\Application;

use Modules\Authorization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use stdClass;

/** Builds authorization facts from the Documents-owned row, never the actor. */
final class DocumentAuthorizationRecordFactsBuilder
{
    public function __construct(private readonly ResolveOrganizationScopeAncestry $ancestry) {}

    public function forDocument(stdClass $document): RecordFacts
    {
        $ownerId = is_string($document->owner_organization_unit_id ?? null)
            ? $document->owner_organization_unit_id
            : null;
        $ownerAncestry = $ownerId === null
            ? null
            : ($this->ancestry->ancestry('unit', $ownerId)
                ?? $this->ancestry->ancestry('facility', $ownerId));

        return new RecordFacts(
            ownerFacilityId: $ownerAncestry['facility_id'] ?? null,
            resourceType: 'document',
            classification: (string) ($document->classification ?? ''),
            organizationUnitId: $ownerAncestry['unit_id'] ?? null,
            recordId: is_string($document->id ?? null) ? $document->id : null,
            sourceModule: 'documents',
            clusterId: $ownerAncestry['cluster_id'] ?? null,
            createdByUserId: is_string($document->created_by_user_id ?? null) ? $document->created_by_user_id : null,
            lifecycleState: is_string($document->status ?? null) ? $document->status : null,
            legalHold: (bool) ($document->legal_hold ?? false),
            lockVersion: isset($document->lock_version) ? (int) $document->lock_version : null,
        );
    }
}
