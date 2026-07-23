<?php

namespace Modules\PlatformSettings\Contracts;

use DateTimeImmutable;

/** Emits a minimal, recipient-selector-only technical alert for Notifications. */
interface PublishTechnicalAlert
{
    public function publish(
        string $alertCode,
        string $severity,
        string $recipientCapability,
        DateTimeImmutable $occurredAt,
        string $correlationId,
    ): void;
}
