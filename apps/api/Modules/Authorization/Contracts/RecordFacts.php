<?php

namespace Modules\Authorization\Contracts;

final readonly class RecordFacts
{
    public function __construct(
        public ?string $ownerFacilityId,
        public string $resourceType,
        public string $classification,
        public string $factsVersion = 'development-fixture-facts-v1',
    ) {
    }
}
