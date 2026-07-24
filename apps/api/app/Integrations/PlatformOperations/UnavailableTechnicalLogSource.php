<?php

namespace App\Integrations\PlatformOperations;

use Modules\PlatformSettings\Contracts\TechnicalLogSource;
use Modules\PlatformSettings\Domain\TechnicalLogFilter;
use Modules\PlatformSettings\Domain\TechnicalLogPage;

/**
 * Production sentinel for the technical-logs surface.
 *
 * The technical-logs capability is DEFERRED. Until a real
 * `TechnicalLogSource` is configured, this class is bound in production
 * and any attempt to read or restore technical logs fails closed with a
 * typed `TechnicalLogSourceUnavailable` exception that the controller
 * maps to a 503 problem document. The deterministic mock source stays
 * in the test fixtures only.
 */
final readonly class UnavailableTechnicalLogSource implements TechnicalLogSource
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function search(TechnicalLogFilter $filter): TechnicalLogPage
    {
        throw new TechnicalLogSourceUnavailable('Technical log source is not configured.');
    }
}
