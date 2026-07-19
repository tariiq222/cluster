<?php

namespace Modules\Reporting\Features\RebuildReportingProjection\Handler;

use Illuminate\Support\Facades\DB;
use Modules\Reporting\Features\RefreshReportingProjection\Handler\RefreshReportingProjectionHandler;

final class RebuildReportingProjectionHandler
{
    public function __construct(private readonly RefreshReportingProjectionHandler $refresher) {}

    /** @param iterable<array<string, mixed>> $events @return array{refreshed: int, projection_version: string} */
    public function handle(iterable $events): array
    {
        $events = iterator_to_array($events, false);
        usort($events, static fn (array $a, array $b): int => strcmp(
            implode('|', [(string) ($a['report_id'] ?? ''), (string) ($a['source_type'] ?? ''), (string) ($a['source_id'] ?? ''), (string) ($a['source_version'] ?? '')]),
            implode('|', [(string) ($b['report_id'] ?? ''), (string) ($b['source_type'] ?? ''), (string) ($b['source_id'] ?? ''), (string) ($b['source_version'] ?? '')]),
        ));

        return DB::transaction(function () use ($events): array {
            DB::table('report_read_models')
                ->where('projection_version', RefreshReportingProjectionHandler::PROJECTION_VERSION)
                ->delete();
            foreach ($events as $event) {
                $this->refresher->handle($event);
            }

            return ['refreshed' => count($events), 'projection_version' => RefreshReportingProjectionHandler::PROJECTION_VERSION];
        });
    }
}
