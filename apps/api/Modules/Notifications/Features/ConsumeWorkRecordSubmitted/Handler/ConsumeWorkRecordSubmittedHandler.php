<?php

namespace Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Handler;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConsumeWorkRecordSubmittedHandler
{
    private const EVENT_TYPE = 'com.cluster.workrecord.submitted.v1';

    /** @param array<string, mixed> $cloudEvent */
    public function handle(array $cloudEvent): bool
    {
        $this->validate($cloudEvent);

        return DB::transaction(function () use ($cloudEvent): bool {
            $now = now();
            if ($this->inboxContains($cloudEvent['id'])) {
                return false;
            }

            try {
                DB::table('notification_inbox')->insert([
                    'event_id' => $cloudEvent['id'],
                    ...(Schema::hasColumn('notification_inbox', 'consumer') ? ['consumer' => 'notifications.work-record-submitted.v1'] : []),
                    'processed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $exception) {
                if ($this->isDuplicateInboxEvent($exception)) {
                    return false;
                }

                throw $exception;
            }

            $recipient = $cloudEvent['data']['record']['owner']['user_id'];
            $sourceRecord = $cloudEvent['data']['record']['id'];
            $groupKey = 'work-record-submitted|'.$recipient.'|'.$sourceRecord;
            $existing = Schema::hasColumn('notifications', 'notification_group_key')
                ? DB::table('notifications')->where('recipient_user_id', $recipient)->where('notification_group_key', $groupKey)->first()
                : null;
            if ($existing !== null) {
                $notificationId = $this->incrementAggregation($existing->id, $cloudEvent['id'], $now);
            } else {
                $notificationId = Str::uuid7()->toString();
                try {
                    DB::table('notifications')->insert([
                        'id' => $notificationId,
                        'event_id' => $cloudEvent['id'],
                        'recipient_user_id' => $recipient,
                        'title' => 'تم تقديم سجل عمل',
                        'source_record_id' => $sourceRecord,
                        ...(Schema::hasColumn('notifications', 'source_owner_facility_id') ? [
                            'source_owner_facility_id' => $cloudEvent['data']['access_context']['owner_facility_id'] ?? null,
                            'source_classification' => $cloudEvent['data']['classification'] ?? null,
                        ] : []),
                        'is_read' => false,
                        ...(Schema::hasColumn('notifications', 'status') ? ['status' => 'unread'] : []),
                        ...(Schema::hasColumn('notifications', 'notification_group_key') ? [
                            'notification_group_key' => $groupKey,
                            'aggregation_count' => 1,
                            'last_event_id' => $cloudEvent['id'],
                        ] : []),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } catch (QueryException $exception) {
                    if (! $this->isDuplicateGroupKey($exception)) {
                        throw $exception;
                    }

                    $winner = Schema::hasColumn('notifications', 'notification_group_key')
                        ? DB::table('notifications')->where('recipient_user_id', $recipient)->where('notification_group_key', $groupKey)->first()
                        : null;
                    if ($winner === null) {
                        throw $exception;
                    }

                    $notificationId = $this->incrementAggregation($winner->id, $cloudEvent['id'], $now);
                }
            }
            if (Schema::hasTable('notification_recipients')) {
                DB::table('notification_recipients')->insertOrIgnore([
                    'id' => Str::uuid7()->toString(),
                    'notification_id' => $notificationId,
                    'recipient_user_id' => $recipient,
                    'status' => 'unread',
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return true;
        });
    }

    private function incrementAggregation(string $notificationId, string $eventId, \Illuminate\Support\Carbon $now): string
    {
        DB::table('notifications')->where('id', $notificationId)->update([
            'aggregation_count' => DB::raw('COALESCE(aggregation_count, 0) + 1'),
            'last_event_id' => $eventId,
            // A new submission for the same group must re-surface the
            // notification: a read inbox entry is not a reason to stay silent.
            'is_read' => false,
            'status' => 'unread',
            'updated_at' => $now,
        ]);

        return $notificationId;
    }

    private function inboxContains(mixed $eventId): bool
    {
        return DB::table('notification_inbox')->where('event_id', $eventId)->exists();
    }

    private function isDuplicateInboxEvent(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = $exception->errorInfo[1] ?? null;

        return $sqlState === '23505'
            || in_array($driverCode, [1062, '1062'], true)
            || (($driverCode === 19 || $driverCode === '19')
                && str_contains(strtolower($exception->getMessage()), 'notification_inbox.event_id'));
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

    /** @param array<string, mixed> $cloudEvent */
    private function validate(array $cloudEvent): void
    {
        $data = $cloudEvent['data'] ?? null;
        if (($cloudEvent['specversion'] ?? null) !== '1.0'
            || ($cloudEvent['type'] ?? null) !== self::EVENT_TYPE
            || ($cloudEvent['source'] ?? null) !== '/work-records'
            || ($cloudEvent['datacontenttype'] ?? null) !== 'application/json'
            || ! is_array($data)
            || ! is_array($data['record'] ?? null)
            || ! is_array($data['record']['owner'] ?? null)
            || ! is_array($data['access_context'] ?? null)
            || ! in_array($data['classification'] ?? null, ['public', 'internal', 'confidential', 'top_secret'], true)) {
            throw new InvalidArgumentException('Unsupported WorkRecord CloudEvent envelope.');
        }

        foreach (['id', 'correlationid'] as $uuidField) {
            if (! $this->isUuidV7($cloudEvent[$uuidField] ?? null)) {
                throw new InvalidArgumentException("CloudEvent {$uuidField} must be a lowercase UUIDv7.");
            }
        }

        $recordId = $data['record']['id'] ?? null;
        $recipientUserId = $data['record']['owner']['user_id'] ?? null;
        if (! $this->isUuidV7($recordId)
            || ! $this->isUuidV7($recipientUserId)
            || ($cloudEvent['subject'] ?? null) !== '/work-records/'.$recordId
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/', $cloudEvent['time'] ?? '') !== 1) {
            throw new InvalidArgumentException('WorkRecord CloudEvent projection fields are invalid.');
        }
    }

    private function isUuidV7(mixed $value): bool
    {
        return is_string($value)
            && preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
                $value,
            ) === 1;
    }
}
