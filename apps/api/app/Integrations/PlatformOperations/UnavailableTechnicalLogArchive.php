<?php

namespace App\Integrations\PlatformOperations;

use Modules\PlatformSettings\Contracts\TechnicalLogArchive;
use Modules\PlatformSettings\Domain\ArchiveBatch;
use Modules\PlatformSettings\Domain\ArchiveManifest;
use Throwable;

/** Fail-closed archive adapter used when the configured archive disk cannot resolve. */
final readonly class UnavailableTechnicalLogArchive implements TechnicalLogArchive
{
    public function __construct(private ?Throwable $cause = null) {}

    public function archive(ArchiveBatch $batch): ArchiveManifest
    {
        throw new TechnicalLogSourceUnavailable('Technical log archive is not configured.', 0, $this->cause);
    }

    public function requestRestore(string $manifestId, string $actorId, string $reason): string
    {
        throw new TechnicalLogSourceUnavailable('Technical log archive is not configured.', 0, $this->cause);
    }
}
