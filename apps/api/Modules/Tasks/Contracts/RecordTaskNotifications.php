<?php

namespace Modules\Tasks\Contracts;

/**
 * Tasks-owned mirror of the Notifications recording capability. Tasks is a
 * lower-ranked module and must not import Modules\Notifications directly;
 * the Notifications module provides the implementation and binds this
 * contract in its own service provider (same pattern as Organization's
 * mirror of ResolveDevelopmentFixturePrincipal).
 *
 * Writes one notifications row per recipient inside the CALLER's
 * transaction, deduplicated; an empty recipient list writes nothing.
 */
interface RecordTaskNotifications
{
    /**
     * @param  list<string>  $recipientUserIds
     * @param  array<string, mixed>  $payload  safe task metadata only (task_id, title, actor_user_id, action)
     */
    public function record(array $recipientUserIds, string $type, array $payload): void;
}
