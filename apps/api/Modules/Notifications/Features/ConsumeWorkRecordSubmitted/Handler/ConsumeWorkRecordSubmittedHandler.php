<?php

namespace Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Handler;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
            if (DB::table('notification_inbox')->where('event_id', $cloudEvent['id'])->exists()) {
                return false;
            }

            try {
                DB::table('notification_inbox')->insert([
                    'event_id' => $cloudEvent['id'],
                    'processed_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (QueryException $exception) {
                if (DB::table('notification_inbox')->where('event_id', $cloudEvent['id'])->exists()) {
                    return false;
                }

                throw $exception;
            }

            DB::table('notifications')->insert([
                'id' => Str::uuid7()->toString(),
                'event_id' => $cloudEvent['id'],
                'recipient_user_id' => $cloudEvent['data']['record']['owner']['user_id'],
                'title' => 'تم تقديم سجل عمل',
                'source_record_id' => $cloudEvent['data']['record']['id'],
                'is_read' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return true;
        });
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
