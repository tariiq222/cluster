<?php

namespace Modules\PlatformSettings\Domain;

use DateTimeImmutable;

final readonly class ArchiveManifest
{
    public function __construct(
        public string $id,
        public int $count,
        public DateTimeImmutable $firstOccurredAt,
        public DateTimeImmutable $lastOccurredAt,
        public string $sha256,
        public string $storageReference,
        public string $manifestReference,
        public string $status = 'verified',
    ) {}
}
