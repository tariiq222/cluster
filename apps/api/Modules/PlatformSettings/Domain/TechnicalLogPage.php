<?php

namespace Modules\PlatformSettings\Domain;

final readonly class TechnicalLogPage
{
    /** @param list<TechnicalLogEntry> $entries */
    public function __construct(public array $entries, public ?string $nextCursor) {}
}
