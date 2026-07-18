<?php

namespace Modules\Documents\Application;

use Modules\Documents\Domain\UuidV7;

/** Internal, controller-neutral facts used to obtain an Authorization decision. */
final readonly class DocumentAuthorizationFacts
{
    public function __construct(
        public string $ownerOrganizationUnitId,
        public string $classification,
    ) {
        UuidV7::assert($this->ownerOrganizationUnitId, 'Document owner organization unit id');
    }
}
