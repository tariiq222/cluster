<?php

declare(strict_types=1);

namespace Modules\PlatformSettings\Infrastructure\Outbox;

use Illuminate\Support\Facades\DB;
use Shared\Contracts\PendingOutboxEvent;
use Shared\Contracts\PendingOutboxStore;

final class PlatformSettingsOutboxStore implements PendingOutboxStore
{
    private const MAX_BATCH_SIZE = 100;

    public function pending(array $eventTypes, int $limit): array
    {
        if ($eventTypes === []) {
            return [];
        }

        $rows = DB::table('platform_settings_outbox')
            ->select(['id', 'event_type', 'payload'])
            ->whereNull('published_at')
            ->whereIn('event_type', $eventTypes)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, self::MAX_BATCH_SIZE)))
            ->get();

        $events = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new \JsonException(sprintf(
                    'platform_settings_outbox row %s has a non-array payload.',
                    (string) $row->id,
                ));
            }

            $events[] = new PendingOutboxEvent(
                eventId: (string) $row->id,
                eventType: (string) $row->event_type,
                payload: $payload,
            );
        }

        return $events;
    }

    public function markPublished(string $eventId): bool
    {
        return DB::table('platform_settings_outbox')
            ->where('id', $eventId)
            ->whereNull('published_at')
            ->update([
                'published_at' => now(),
                'updated_at' => now(),
            ]) > 0;
    }
}
