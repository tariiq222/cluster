<?php

declare(strict_types=1);

namespace Modules\Notifications\Contracts;

interface RecordNotifications
{
    /**
     * Persist one unread notification row per unique recipient using the same
     * column layout as `ConsumeWorkRecordSubmittedHandler` (id, event_id,
     * recipient_user_id, title, source_record_id, status, is_read,
     * notification_group_key, aggregation_count, last_event_id, type, payload).
     * Writes happen inside the caller's transaction — the implementation MUST
     * NOT open its own `DB::transaction`.
     *
     * @param list<string>            $recipientUserIds User IDs that will receive a notification.
     *                                            The implementation MUST dedupe defensively.
     * @param string                  $type            Notification type (e.g. "task.created").
     * @param array<string, mixed>    $payload         Safe metadata to surface in the row payload.
     */
    public function record(array $recipientUserIds, string $type, array $payload): void;
}
