<?php

namespace Modules\PlatformSettings\Contracts;

use DateTimeImmutable;
use Modules\PlatformSettings\Domain\ArchiveBatch;
use Modules\PlatformSettings\Domain\ArchiveManifest;

interface TechnicalLogArchiveStore
{
    public function manifest(string $manifestId): ?ArchiveManifest;

    public function recordVerifiedArchive(ArchiveBatch $batch, ArchiveManifest $manifest): ArchiveManifest;

    /** @param array<string, mixed> $readModel */
    public function createRestoreRequest(string $jobId, string $manifestId, string $actorId, string $reason, array $readModel, DateTimeImmutable $expiresAt): void;

    /** @return array<string, mixed>|null */
    public function activeRestoreReadModel(string $jobId, DateTimeImmutable $now): ?array;

    public function restoreStatus(string $jobId): ?string;
}
