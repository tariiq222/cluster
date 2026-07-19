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
                DB::table('notifications')->where('id', $existing->id)->update([
                    'aggregation_count' => ((int) ($existing->aggregation_count ?? 1)) + 1,
                    'last_event_id' => $cloudEvent['id'],
                    'updated_at' => $now,
                ]);
                $notificationId = (string) $existing->id;
            } else {
                $notificationId = Str::uuid7()->toString();
                DB::table('notifications')->insert([
                    'id' => $notificationId,
                    'event_id' => $cloudEvent['id'],
                    'recipient_user_id' => $recipient,
                    'title' => 'تم تقديم سجل عمل',
                    'source_record_id' => $sourceRecord,
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
