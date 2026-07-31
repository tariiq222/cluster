<?php

namespace Modules\Notifications\Infrastructure\Persistence;

use Modules\Authorization\Contracts\ResolveActiveUsersByCapability;
use Modules\Notifications\Contracts\ResolveTechnicalAlertRecipients;

final class DatabaseTechnicalAlertRecipientResolver implements ResolveTechnicalAlertRecipients
{
    public function __construct(
        private readonly ResolveActiveUsersByCapability $usersByCapability,
    ) {}

    public function resolve(string $recipientCapability): array
    {
        return $this->usersByCapability->users($recipientCapability);
    }
}
