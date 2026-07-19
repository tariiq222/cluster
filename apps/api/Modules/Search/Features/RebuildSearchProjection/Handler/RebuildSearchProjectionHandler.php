<?php

namespace Modules\Search\Features\RebuildSearchProjection\Handler;

use Illuminate\Support\Facades\DB;
use Modules\Search\Features\IndexSourceEvent\Handler\IndexSourceEventHandler;

final class RebuildSearchProjectionHandler
{
    public function __construct(private readonly IndexSourceEventHandler $indexer) {}

    /**
     * @param  iterable<array<string, mixed>>  $events
     * @return array{indexed: int, projection_version: string}
     */
    public function handle(iterable $events): array
    {
        $events = iterator_to_array($events, false);
        usort($events, static fn (array $a, array $b): int => strcmp(
            implode('|', [(string) ($a['source_type'] ?? ''), (string) ($a['source_id'] ?? ''), (string) ($a['source_version'] ?? '')]),
            implode('|', [(string) ($b['source_type'] ?? ''), (string) ($b['source_id'] ?? ''), (string) ($b['source_version'] ?? '')]),
        ));

        return DB::transaction(function () use ($events): array {
            DB::table('search_index_entries')
                ->where('projection_version', IndexSourceEventHandler::PROJECTION_VERSION)
                ->delete();
            $count = 0;
            foreach ($events as $event) {
                $this->indexer->handle($event);
                $count++;
            }

            return ['indexed' => $count, 'projection_version' => IndexSourceEventHandler::PROJECTION_VERSION];
        });
    }
}
