<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Outbox;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Shared\Contracts\OutboxConflictException;
use Shared\Contracts\OutboxDuplicatePolicy;
use Shared\Contracts\TransactionalOutbox;
use Shared\Contracts\TransactionalOutboxEnvelope;
use Shared\Contracts\TransactionalOutboxReplayable;

/**
 * The sole writer for the shared `outbox_events` table.
 *
 * The base append method intentionally preserves its original
 * insert-or-ignore behaviour because existing Tasks and Workflow callers use
 * deterministic event IDs. Policy-aware writes use strict or replayable
 * duplicate handling, while envelope writes preserve producer-supplied
 * CloudEvent extensions such as `correlationid` and `time`.
 */
final class DatabaseTransactionalOutbox implements TransactionalOutbox, TransactionalOutboxEnvelope, TransactionalOutboxReplayable
{
    /** @param array<string, mixed> $payload */
    public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void
    {
        $occurredAt = now();
        $cloudEvent = $this->cloudEvent($eventId, $aggregateId, $eventType, $payload, $occurredAt);

        DB::table('outbox_events')->insertOrIgnore(
            $this->row($eventId, $aggregateId, $cloudEvent, $occurredAt, $occurredAt),
        );
    }

    /** @param array<string, mixed> $payload */
    public function appendWithPolicy(
        string $eventId,
        string $aggregateId,
        string $eventType,
        array $payload,
        OutboxDuplicatePolicy $policy = OutboxDuplicatePolicy::Strict,
    ): void {
        $occurredAt = now();
        $cloudEvent = $this->cloudEvent($eventId, $aggregateId, $eventType, $payload, $occurredAt);

        $this->writeRow(
            $this->row($eventId, $aggregateId, $cloudEvent, $occurredAt, $occurredAt),
            $policy,
        );
    }

    /** @param array<string, mixed> $cloudEvent */
    public function appendEnvelope(
        string $eventId,
        string $aggregateId,
        array $cloudEvent,
        string $occurredAt,
        ?string $auditAt = null,
        OutboxDuplicatePolicy $policy = OutboxDuplicatePolicy::Strict,
    ): void {
        if (! isset($cloudEvent['type']) || ! is_string($cloudEvent['type']) || $cloudEvent['type'] === '') {
            throw new \ValueError('appendEnvelope requires a non-empty CloudEvent `type` field.');
        }

        OutboxEventType::from($cloudEvent['type']);

        $this->writeRow(
            $this->row(
                $eventId,
                $aggregateId,
                $cloudEvent,
                Carbon::parse($occurredAt),
                $auditAt === null ? now() : Carbon::parse($auditAt),
            ),
            $policy,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function cloudEvent(
        string $eventId,
        string $aggregateId,
        string $eventType,
        array $payload,
        DateTimeInterface $occurredAt,
    ): array {
        return [
            'specversion' => '1.0',
            'id' => $eventId,
            'source' => '/'.$eventType,
            'type' => $eventType,
            'subject' => '/'.$aggregateId,
            'time' => $occurredAt->format('Y-m-d\TH:i:s.v\Z'),
            'datacontenttype' => 'application/json',
            'data' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $cloudEvent
     * @return array<string, mixed>
     */
    private function row(
        string $eventId,
        string $aggregateId,
        array $cloudEvent,
        DateTimeInterface $occurredAt,
        DateTimeInterface $auditAt,
    ): array {
        return [
            'event_id' => $eventId,
            'aggregate_id' => $aggregateId,
            'event_type' => (string) ($cloudEvent['type'] ?? ''),
            'cloud_event' => json_encode($cloudEvent, JSON_THROW_ON_ERROR),
            'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
            'published_at' => null,
            'delivery_attempts' => 0,
            'created_at' => $auditAt,
            'updated_at' => $auditAt,
        ];
    }

    /** @param array<string, mixed> $row */
    private function writeRow(array $row, OutboxDuplicatePolicy $policy): void
    {
        if ($policy === OutboxDuplicatePolicy::Strict) {
            DB::table('outbox_events')->insert($row);

            return;
        }

        $existing = DB::table('outbox_events')
            ->where('event_id', $row['event_id'])
            ->first(['aggregate_id', 'event_type', 'cloud_event']);

        if ($existing === null) {
            DB::table('outbox_events')->insert($row);

            return;
        }

        $existingEnvelope = json_decode((string) $existing->cloud_event, true, 512, JSON_THROW_ON_ERROR);
        $incomingEnvelope = json_decode((string) $row['cloud_event'], true, 512, JSON_THROW_ON_ERROR);

        if (
            is_array($existingEnvelope)
            && is_array($incomingEnvelope)
            && hash_equals(
                self::contentHash((string) $existing->aggregate_id, (string) $existing->event_type, $existingEnvelope),
                self::contentHash((string) $row['aggregate_id'], (string) $row['event_type'], $incomingEnvelope),
            )
        ) {
            return;
        }

        throw OutboxConflictException::forEvent((string) $row['event_id']);
    }

    /** @param array<string, mixed> $cloudEvent */
    private static function contentHash(string $aggregateId, string $eventType, array $cloudEvent): string
    {
        foreach (['id', 'time', 'source', 'specversion', 'datacontenttype'] as $volatileKey) {
            unset($cloudEvent[$volatileKey]);
        }

        $content = [
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'cloud_event' => self::canonicalize($cloudEvent),
        ];

        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
