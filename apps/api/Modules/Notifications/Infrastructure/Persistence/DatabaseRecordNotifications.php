<?php

declare(strict_types=1);

namespace Modules\Notifications\Infrastructure\Persistence;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Tasks\Contracts\RecordTaskNotifications;

/**
 * Notifications-table writer. Bound from NotificationsServiceProvider to the
 * Tasks-owned mirror contract RecordTaskNotifications (Tasks cannot import
 * Notifications directly — see ModuleBoundariesTest). Defensive dedupe of
 * recipients; caller participates in the active transaction.
 */
final class DatabaseRecordNotifications implements RecordTaskNotifications
{
    public function __construct(private readonly ConnectionInterface $database)
    {
    }

    public function record(array $recipientUserIds, string $type, array $payload): void
    {
        $recipients = array_values(array_unique(array_filter(
            $recipientUserIds,
            static fn (string $userId): bool => $userId !== '',
        )));
        if ($recipients === []) {
            return;
        }

        $now = now();
        $eventId = (string) Str::uuid7();
        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $title = $this->resolveTitle($type, $payload);
        $sourceRecordId = $this->resolveSourceRecordId($payload);
        $groupKey = $this->resolveGroupKey($type, $payload);

        $rows = array_map(static fn (string $recipient): array => [
            'id' => (string) Str::uuid7(),
            'event_id' => $eventId,
            'recipient_user_id' => $recipient,
            'type' => $type,
            'title' => $title,
            'source_record_id' => $sourceRecordId,
            'notification_group_key' => $groupKey,
            'aggregation_count' => 1,
            'last_event_id' => $eventId,
            'is_read' => false,
            'status' => 'unread',
            'payload' => $payloadJson,
            'created_at' => $now,
            'updated_at' => $now,
        ], $recipients);

        $this->database->table('notifications')->insert($rows);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveTitle(string $type, array $payload): string
    {
        $title = $payload['title'] ?? null;
        if (is_string($title) && $title !== '') {
            return $title;
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSourceRecordId(array $payload): ?string
    {
        $candidate = $payload['task_id'] ?? $payload['source_record_id'] ?? null;

        return is_string($candidate) && $candidate !== '' ? $candidate : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveGroupKey(string $type, array $payload): ?string
    {
        $subject = $payload['task_id'] ?? $payload['source_record_id'] ?? null;
        if (! is_string($subject) || $subject === '') {
            return null;
        }

        return $type.'|'.$subject;
    }
}
