<?php

namespace Modules\Notifications\Contracts;

interface ResolveTechnicalAlertRecipients
{
    /** @return list<string> user identifiers resolved locally by Notifications */
    public function resolve(string $recipientCapability): array;
}
