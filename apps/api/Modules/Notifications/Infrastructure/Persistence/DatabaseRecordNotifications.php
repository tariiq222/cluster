<?php

declare(strict_types=1);

namespace Modules\Notifications\Infrastructure\Persistence;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
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
    public function __construct(private readonly ConnectionInterface $database) {}

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

        foreach ($recipients as $recipient) {
            $this->insertOrAggregate($recipient, $type, $title, $sourceRecordId, $groupKey, $eventId, $payloadJson, $now);
        }
    }

    /**
     * Writes one recipient row, aggregating into an existing row for the
     * same (recipient, group key) instead of failing on the W25 unique
     * constraint: repeated task events for the same task collapse into one
     * notification that is re-surfaced as unread.
     */
    private function insertOrAggregate(
        string $recipient,
        string $type,
        string $title,
        ?string $sourceRecordId,
        ?string $groupKey,
        string $eventId,
        string $payloadJson,
        \Illuminate\Support\Carbon $now,
    ): void {
        if ($groupKey !== null) {
            $existing = $this->database->table('notifications')
                ->where('recipient_user_id', $recipient)
                ->where('notification_group_key', $groupKey)
                ->first();
            if ($existing instanceof \stdClass) {
                $this->database->table('notifications')->where('id', $existing->id)->update([
                    'aggregation_count' => $this->database->raw('COALESCE(aggregation_count, 0) + 1'),
                    'last_event_id' => $eventId,
                    'is_read' => false,
                    'status' => 'unread',
                    'updated_at' => $now,
                ]);

                return;
            }
        }

        try {
            $this->database->table('notifications')->insert([
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
            ]);
        } catch (QueryException $exception) {
            if ($groupKey === null || ! $this->isDuplicateGroupKey($exception)) {
                throw $exception;
            }

            $winner = $this->database->table('notifications')
                ->where('recipient_user_id', $recipient)
                ->where('notification_group_key', $groupKey)
                ->first();
            if ($winner === null) {
                throw $exception;
            }
            $this->database->table('notifications')->where('id', $winner->id)->update([
                'aggregation_count' => $this->database->raw('COALESCE(aggregation_count, 0) + 1'),
                'last_event_id' => $eventId,
                'is_read' => false,
                'status' => 'unread',
                'updated_at' => $now,
            ]);
        }
    }

    private function isDuplicateGroupKey(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;
        $message = strtolower($exception->getMessage());

        return $sqlState === '23505'
            || in_array($driverCode, [1062, '1062'], true)
            || (($driverCode === 19 || $driverCode === '19')
                && str_contains($message, 'notifications.recipient_user_id')
                && str_contains($message, 'notification_group_key'));
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
