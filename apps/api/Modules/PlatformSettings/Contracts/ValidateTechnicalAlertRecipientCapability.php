<?php

namespace Modules\PlatformSettings\Contracts;

interface ValidateTechnicalAlertRecipientCapability
{
    public function assertSupported(string $capability): void;
}
