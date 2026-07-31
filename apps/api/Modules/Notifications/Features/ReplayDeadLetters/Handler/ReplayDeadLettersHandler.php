<?php

namespace Modules\Notifications\Features\ReplayDeadLetters\Handler;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Modules\Notifications\Features\ConsumeTechnicalAlert\Handler\ConsumeTechnicalAlertHandler;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Handler\ConsumeWorkRecordSubmittedHandler;
use Throwable;

/**
 * Bounded DLQ replay: re-feeds durable dead-letter envelopes into the
 * notification aggregation pipeline through the owning handlers (both are
 * inbox-idempotent), then stamps `replayed_at`. Envelopes that no longer
 * validate (malformed raw payloads, invalid events) stay dead and are
 * counted as skipped.
 */
final class ReplayDeadLettersHandler
{
    private const MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly ConsumeWorkRecordSubmittedHandler $workRecords,
        private readonly ConsumeTechnicalAlertHandler $technicalAlerts,
    ) {}

    /** @return array{replayed: int, skipped: int, failed: int} */
    public function replayOnce(int $limit = 100): array
    {
        $limit = max(1, min($limit, self::MAX_BATCH_SIZE));
        $rows = DB::table('notification_dead_letters')
            ->whereNull('replayed_at')
            ->orderBy('failed_at')
            ->limit($limit)
            ->get(['id', 'original_event']);
        $counts = ['replayed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $event = $this->decodeEvent($row->original_event);
            $handler = $this->handlerFor($event);
            if ($handler === null) {
                $counts['skipped']++;

                continue;
            }

            try {
                $replayed = DB::transaction(function () use ($row, $handler, $event): bool {
                    $claimed = DB::table('notification_dead_letters')
                        ->where('id', $row->id)
                        ->whereNull('replayed_at')
                        ->lockForUpdate()
                        ->exists();
                    if (! $claimed) {
                        return false;
                    }
                    $handler->handle($event);
                    DB::table('notification_dead_letters')
                        ->where('id', $row->id)
                        ->whereNull('replayed_at')
                        ->update([
                            'replayed_at' => now('UTC'),
                            'updated_at' => now('UTC'),
                        ]);

                    return true;
                });
                if ($replayed) {
                    $counts['replayed']++;
                } else {
                    $counts['skipped']++;
                }
            } catch (InvalidArgumentException) {
                // The envelope no longer validates: it is not replayable.
                $counts['skipped']++;
            } catch (Throwable) {
                // Processing failed again: leave the row unmarked so a later
                // bounded cycle can retry it.
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /** @return array<string, mixed>|null */
    private function decodeEvent(mixed $payload): ?array
    {
        if (! is_string($payload)) {
            return null;
        }
        try {
            $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($event) && ! array_is_list($event) ? $event : null;
    }

    /** @param array<string, mixed>|null $event */
    private function handlerFor(?array $event): ?object
    {
        return match ($event['type'] ?? null) {
            'com.cluster.workrecord.submitted.v1' => $this->workRecords,
            'com.cluster.platform.technical-alert.v1' => $this->technicalAlerts,
            default => null,
        };
    }
}
