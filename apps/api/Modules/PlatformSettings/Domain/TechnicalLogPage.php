<?php

namespace Modules\PlatformSettings\Domain;

use InvalidArgumentException;

final readonly class TechnicalLogPage
{
    /** @param list<TechnicalLogEntry> $entries */
    public function __construct(public array $entries, public ?string $nextCursor)
    {
        foreach ($entries as $entry) {
            if (! $entry instanceof TechnicalLogEntry) {
                throw new InvalidArgumentException('Technical log pages contain only entries.');
            }
        }
    }
}
