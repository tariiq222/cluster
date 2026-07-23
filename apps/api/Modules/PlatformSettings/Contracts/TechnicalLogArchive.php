<?php

namespace Modules\PlatformSettings\Contracts;

use Modules\PlatformSettings\Domain\ArchiveBatch;
use Modules\PlatformSettings\Domain\ArchiveManifest;

interface TechnicalLogArchive
{
    public function archive(ArchiveBatch $batch): ArchiveManifest;

    public function requestRestore(string $manifestId, string $actorId, string $reason): string;
}
