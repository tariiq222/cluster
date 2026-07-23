<?php

namespace Modules\PlatformSettings\Domain;

use DateTimeImmutable;

final readonly class BackupStatus
{
    public function __construct(
        public string $status,
        public ?DateTimeImmutable $lastSuccessfulAt,
        public ?DateTimeImmutable $lastFailedAt,
        public ?DateTimeImmutable $lastValidationAt,
    ) {}
}
