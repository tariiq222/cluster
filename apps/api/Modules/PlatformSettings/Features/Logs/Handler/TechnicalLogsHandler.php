<?php

namespace Modules\PlatformSettings\Features\Logs\Handler;

use DomainException;
use Modules\PlatformSettings\Contracts\TechnicalLogArchive;
use Modules\PlatformSettings\Contracts\TechnicalLogSource;
use Modules\PlatformSettings\Domain\TechnicalLogFilter;
use Modules\PlatformSettings\Domain\TechnicalLogPage;

final readonly class TechnicalLogsHandler
{
    public function __construct(private TechnicalLogSource $source, private TechnicalLogArchive $archive) {}

    public function search(TechnicalLogFilter $filter): TechnicalLogPage
    {
        return $this->source->search($filter);
    }

    /** @param list<string> $grantedCapabilities */
    public function requestRestore(string $manifestId, string $actorId, string $reason, array $grantedCapabilities): string
    {
        if (! in_array('platform_operations.logs.restore', $grantedCapabilities, true)) {
            throw new DomainException('platform_operations.logs.restore is required.');
        }
        if (trim($reason) === '') {
            throw new DomainException('Technical log restore reason is required.');
        }

        return $this->archive->requestRestore($manifestId, $actorId, $reason);
    }
}
