<?php

namespace Modules\Documents\Domain;

use DateTimeImmutable;

final readonly class ResolvedDocumentRetention
{
    public function __construct(
        public string $policyKey,
        public DateTimeImmutable $retentionUntil,
        public bool $legalHold,
        public ?string $legalHoldReason,
    ) {}
}
