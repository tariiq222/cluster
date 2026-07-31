<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Outbox;

use Illuminate\Support\Facades\DB;
use Shared\Contracts\ClaimableOutboxRelayStore;
use Shared\Contracts\OutboxEventLookup;
use Shared\Contracts\OutboxRelayStore;
use Shared\Contracts\PendingOutboxEvent;

final class DatabaseOutboxRelayStore implements ClaimableOutboxRelayStore, OutboxEventLookup, OutboxRelayStore
{
    private const MAX_BATCH_SIZE = 100;

    public function claim(string $eventId, string $workerId, int $leaseSeconds): bool
    {
        $now = now();
        $leaseExpiresAt = $now->copy()->addSeconds(max(1, $leaseSeconds));

        $affected = DB::table('outbox_events')
            ->where('event_id', $eventId)
            ->whereNull('published_at')
            ->where(function ($query) use ($now): void {
                $query->whereNull('claim_owner')
                    ->orWhere('lease_expires_at', '<', $now);
            })
            ->update([
                'claim_owner' => $workerId,
                'lease_expires_at' => $leaseExpiresAt,
                'delivery_attempts' => DB::raw('delivery_attempts + 1'),
                'updated_at' => $now,
            ]);

        return $affected === 1;
    }

    public function release(string $eventId, string $workerId): void
    {
        $now = now();
        DB::table('outbox_events')
            ->where('event_id', $eventId)
            ->where('claim_owner', $workerId)
            ->whereNull('published_at')
            ->update([
                'claim_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => $now,
            ]);
    }

    public function reapAbandonedClaims(\DateTimeInterface $now): int
    {
        $nowString = $now->format('Y-m-d H:i:s');
        $rows = DB::table('outbox_events')
            ->whereNull('published_at')
            ->whereNotNull('claim_owner')
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', $nowString)
            ->get(['event_id', 'claim_owner']);

        $reaped = 0;
        foreach ($rows as $row) {
            $reaped += (int) DB::table('outbox_events')
                ->where('event_id', $row->event_id)
                ->where('claim_owner', $row->claim_owner)
                ->whereNull('published_at')
                ->whereNotNull('lease_expires_at')
                ->where('lease_expires_at', '<', $nowString)
                ->update([
                    'claim_owner' => null,
                    'lease_expires_at' => null,
                    'updated_at' => $nowString,
                ]);
        }

        return $reaped;
    }

    public function pending(array $eventTypes, int $limit): array
    {
        if ($eventTypes === []) {
            return [];
        }

        $rows = DB::table('outbox_events')
            ->select(['event_id', 'event_type', 'cloud_event'])
            ->whereNull('published_at')
            ->whereIn('event_type', $eventTypes)
            ->whereNull('claim_owner')
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->limit(max(1, min($limit, self::MAX_BATCH_SIZE)))
            ->get();

        $events = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->cloud_event, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new \JsonException(sprintf(
                    'outbox_events row %s has a non-array cloud_event payload.',
                    (string) $row->event_id,
                ));
            }

            $events[] = new PendingOutboxEvent(
                eventId: (string) $row->event_id,
                eventType: (string) $row->event_type,
                payload: $payload,
            );
        }

        return $events;
    }

    public function recordAttempt(string $eventId): void
    {
        DB::table('outbox_events')
            ->where('event_id', $eventId)
            ->whereNull('published_at')
            ->increment('delivery_attempts');
    }

    public function markPublished(string $eventId): bool
    {
        return DB::table('outbox_events')
            ->where('event_id', $eventId)
            ->whereNull('published_at')
            ->update([
                'published_at' => now(),
                'claim_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]) > 0;
    }

    public function findCloudEvent(string $aggregateId, string $eventType): ?array
    {
        $raw = DB::table('outbox_events')
            ->where('aggregate_id', $aggregateId)
            ->where('event_type', $eventType)
            ->orderBy('occurred_at')
            ->value('cloud_event');

        if (! is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
