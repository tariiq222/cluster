<?php

namespace Modules\Authorization\Contracts;

final readonly class RecordFacts
{
    /**
     * @param  list<string>  $sharedUnitIds
     * @param  list<string>  $sharedUserIds
     * @param  list<string>  $participantIds
     */
    public function __construct(
        public ?string $ownerFacilityId,
        public string $resourceType,
        public string $classification,
        public string $factsVersion = 'development-fixture-facts-v1',
        public ?string $organizationUnitId = null,
        public ?string $recordId = null,
        public ?string $sourceModule = null,
        public ?string $clusterId = null,
        public ?string $createdByUserId = null,
        public ?string $ownerUserId = null,
        public ?string $responsibleUserId = null,
        public array $sharedUnitIds = [],
        public array $sharedUserIds = [],
        public array $participantIds = [],
        public ?string $lifecycleState = null,
        public ?string $workflowState = null,
        public ?string $fieldPolicyKey = null,
        public ?string $workTypeVersionId = null,
        public bool $legalHold = false,
        public ?int $lockVersion = null,
    ) {}
}
