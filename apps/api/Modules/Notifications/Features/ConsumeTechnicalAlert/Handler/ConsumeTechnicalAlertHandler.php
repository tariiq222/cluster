<?php

namespace Modules\Notifications\Features\ConsumeTechnicalAlert\Handler;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Notifications\Contracts\ResolveTechnicalAlertRecipients;

final class ConsumeTechnicalAlertHandler
{
    private const EVENT_TYPE = 'com.cluster.platform.technical-alert.v1';

    public function __construct(private readonly ResolveTechnicalAlertRecipients $recipients) {}

    /** @param array<string, mixed> $cloudEvent */
    public function handle(array $cloudEvent): bool
    {
        $this->validate($cloudEvent);

        return DB::transaction(function () use ($cloudEvent): bool {
            if (DB::table('notification_inbox')->where('event_id', $cloudEvent['id'])->exists()) {
                return false;
            }

            try {
                DB::table('notification_inbox')->insert([
                    'event_id' => $cloudEvent['id'],
                    ...(Schema::hasColumn('notification_inbox', 'consumer') ? ['consumer' => 'notifications.technical-alert.v1'] : []),
                    ...(Schema::hasColumn('notification_inbox', 'recipient_capability') ? ['recipient_capability' => $cloudEvent['data']['recipient_capability']] : []),
                    'processed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $exception) {
                if ($this->isDuplicateInboxEvent($exception)) {
                    return false;
                }

                throw $exception;
            }

            // The selector is resolved inside Notifications; no user identifier crosses the event boundary.
            $recipients = array_values(array_unique(array_filter(
                $this->recipients->resolve($cloudEvent['data']['recipient_capability']),
                fn (mixed $recipient): bool => $this->isUuidV7($recipient),
            )));
            foreach ($recipients as $recipient) {
                $this->insertNotification($cloudEvent, $recipient);
            }

            return true;
        });
    }

    /** @param array<string, mixed> $cloudEvent */
    private function validate(array $cloudEvent): void
    {
        $data = $cloudEvent['data'] ?? null;
        if (($cloudEvent['specversion'] ?? null) !== '1.0'
            || ($cloudEvent['source'] ?? null) !== '/platform-settings'
            || ($cloudEvent['type'] ?? null) !== self::EVENT_TYPE
            || ($cloudEvent['datacontenttype'] ?? null) !== 'application/json'
            || ! is_array($data)
            || array_keys($data) !== ['alert_code', 'severity', 'recipient_capability', 'occurred_at', 'correlation_id']
            || ! $this->isUuidV7($cloudEvent['id'] ?? null)
            || ! $this->isUuidV7($data['correlation_id'] ?? null)
            || ! is_string($data['alert_code'] ?? null)
            || ! in_array($data['severity'] ?? null, ['info', 'warning', 'critical'], true)
            || ! is_string($data['recipient_capability'] ?? null)
            || preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $data['recipient_capability']) !== 1
            || ! is_string($data['occurred_at'] ?? null)) {
            throw new InvalidArgumentException('Unsupported technical alert CloudEvent envelope.');
        }
    }

    private function isDuplicateInboxEvent(QueryException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? null;
        $code = $exception->errorInfo[1] ?? null;

        return $state === '23505'
            || in_array($code, [1062, '1062'], true)
            || (($code === 19 || $code === '19') && str_contains(strtolower($exception->getMessage()), 'notification_inbox.event_id'));
    }

    private function isUuidV7(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }

    /** @param array<string, mixed> $cloudEvent */
    private function insertNotification(array $cloudEvent, string $recipient): void
    {
        $now = now();
        $notificationId = Str::uuid7()->toString();
        DB::table('notifications')->insertOrIgnore([
            'id' => $notificationId,
            'event_id' => $cloudEvent['id'],
            'recipient_user_id' => $recipient,
            'title' => 'تنبيه تقني: '.$cloudEvent['data']['severity'],
            // A technical alert has no business source record; the CloudEvent id is its immutable source reference.
            'source_record_id' => $cloudEvent['id'],
            'is_read' => false,
            ...(Schema::hasColumn('notifications', 'status') ? ['status' => 'unread'] : []),
            ...(Schema::hasColumn('notifications', 'notification_group_key') ? [
                'notification_group_key' => 'technical-alert|'.$cloudEvent['id'].'|'.$recipient,
                'aggregation_count' => 1,
                'last_event_id' => $cloudEvent['id'],
            ] : []),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
    }
}
