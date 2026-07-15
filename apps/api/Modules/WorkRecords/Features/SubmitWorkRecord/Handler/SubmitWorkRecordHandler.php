<?php

namespace Modules\WorkRecords\Features\SubmitWorkRecord\Handler;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\WorkRecords\Domain\WorkRecord;

final class SubmitWorkRecordHandler
{
    /**
     * Persist the source envelope and its CloudEvent in one caller-owned transaction.
     *
     * @param array<string, mixed> $cloudEvent
     */
    public function persist(WorkRecord $record, array $cloudEvent): void
    {
        $envelope = $record->toEnvelope();
        $this->assertCloudEvent($cloudEvent, $envelope);

        DB::transaction(function () use ($cloudEvent, $envelope): void {
            if (DB::table('work_records')->where('id', $envelope['id'])->exists()) {
                $existingEvent = DB::table('outbox_events')
                    ->where('event_id', $cloudEvent['id'])
                    ->first();

                if ($existingEvent !== null) {
                    $this->assertIdempotentReplay($existingEvent->cloud_event, $cloudEvent);

                    return;
                }

                throw new LogicException('A work record may only be persisted with its original Outbox event.');
            }

            $submittedAt = $this->databaseTimestamp($envelope['submitted_at']);
            DB::table('work_records')->insert([
                'id' => $envelope['id'],
                'record_number' => $envelope['record_number'],
                'work_type_version_id' => $envelope['work_type_version_id'],
                'owner_facility_id' => $envelope['owner']['facility_id'],
                'creator_user_id' => $envelope['owner']['user_id'],
                'status' => $envelope['status'],
                'classification' => $envelope['classification'],
                'payload' => json_encode($envelope['payload'], JSON_THROW_ON_ERROR),
                'lock_version' => $envelope['lock_version'],
                'submitted_at' => $submittedAt,
                'created_at' => $submittedAt,
                'updated_at' => $submittedAt,
            ]);

            DB::table('outbox_events')->insert([
                'event_id' => $cloudEvent['id'],
                'aggregate_id' => $envelope['id'],
                'event_type' => $cloudEvent['type'],
                'cloud_event' => json_encode($cloudEvent, JSON_THROW_ON_ERROR),
                'occurred_at' => $this->databaseTimestamp($cloudEvent['time']),
                'published_at' => null,
                'delivery_attempts' => 0,
                'created_at' => $submittedAt,
                'updated_at' => $submittedAt,
            ]);
        });
    }

    /**
     * @param array<string, mixed> $cloudEvent
     * @param array{id: string, record_number: string, work_type_version_id: string, owner: array{facility_id: string, user_id: string}, status: string, classification: string, payload: array<string, mixed>, lock_version: int, submitted_at: string, created_at: string, updated_at: string} $envelope
     */
    private function assertCloudEvent(array $cloudEvent, array $envelope): void
    {
        foreach (['id', 'source', 'type', 'subject', 'time', 'correlationid'] as $field) {
            if (! isset($cloudEvent[$field]) || ! is_string($cloudEvent[$field]) || $cloudEvent[$field] === '') {
                throw new InvalidArgumentException("CloudEvent {$field} is required.");
            }
        }

        if (($cloudEvent['specversion'] ?? null) !== '1.0'
            || ($cloudEvent['datacontenttype'] ?? null) !== 'application/json'
            || ! is_array($cloudEvent['data'] ?? null)) {
            throw new InvalidArgumentException('Outbox events must be complete CloudEvents JSON envelopes.');
        }

        $this->assertUuidV7($cloudEvent['id'], 'CloudEvent id');
        $this->assertUuidV7($cloudEvent['correlationid'], 'CloudEvent correlation id');

        if ($cloudEvent['source'] !== '/work-records'
            || $cloudEvent['type'] !== 'com.cluster.workrecord.submitted.v1'
            || $cloudEvent['subject'] !== '/work-records/'.$envelope['id']
            || ($cloudEvent['data']['record']['id'] ?? null) !== $envelope['id']
            || ($cloudEvent['data']['classification'] ?? null) !== $envelope['classification']
            || ! array_key_exists('access_context', $cloudEvent['data'])) {
            throw new InvalidArgumentException('CloudEvent does not represent the submitted WorkRecord envelope.');
        }
    }

    /**
     * @param mixed $storedEvent
     * @param array<string, mixed> $replayedEvent
     */
    private function assertIdempotentReplay(mixed $storedEvent, array $replayedEvent): void
    {
        if (! is_string($storedEvent) || json_decode($storedEvent, true, 512, JSON_THROW_ON_ERROR) != $replayedEvent) {
            throw new LogicException('CloudEvent id was already persisted with different semantics.');
        }
    }

    private function databaseTimestamp(string $timestamp): string
    {
        return (new DateTimeImmutable($timestamp))->format('Y-m-d H:i:s');
    }

    private function assertUuidV7(string $value, string $field): void
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a lowercase UUIDv7.");
        }
    }
}
