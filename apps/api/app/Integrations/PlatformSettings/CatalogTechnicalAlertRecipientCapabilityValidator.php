<?php

namespace App\Integrations\PlatformSettings;

use InvalidArgumentException;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\PlatformSettings\Contracts\ValidateTechnicalAlertRecipientCapability;

final class CatalogTechnicalAlertRecipientCapabilityValidator implements ValidateTechnicalAlertRecipientCapability
{
    public function assertSupported(string $capability): void
    {
        if (! CapabilityCatalog::supports($capability)) {
            throw new InvalidArgumentException('alert_recipient_capability_invalid');
        }
    }
}
