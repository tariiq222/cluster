<?php

namespace Modules\Search\Features\BackfillSearchProjection\Handler;

use Illuminate\Support\Facades\DB;
use Modules\Search\Features\IndexSourceEvent\Handler\IndexSourceEventHandler;
use Modules\WorkRecords\Contracts\ProvideWorkRecordsForIndexing;

/**
 * Re-indexes one bounded batch of work records into the search projection.
 * The batch boundary is checkpointed in `search_checkpoints` (consumer
 * `work_records.backfill`):
 *
 *  - a full batch (exactly `limit` rows) advances the checkpoint to the last
 *    processed record id so a crash mid-scan resumes after it;
 *  - a partial or empty batch means the scan reached the end of the source,
 *    so the checkpoint is reset to null and the next invocation re-scans
 *    from the beginning. Because indexing is an idempotent upsert keyed on
 *    (source_type, source_id, projection_version), re-scans reconcile
 *    updated lock_versions and repair a wiped projection without duplicates.
 */
final class BackfillSearchProjectionHandler
{
    public const CHECKPOINT_CONSUMER = 'work_records.backfill';

    public function __construct(
        private readonly ProvideWorkRecordsForIndexing $records,
        private readonly IndexSourceEventHandler $indexer,
    ) {}

    /**
     * @return array{indexed: int, complete: bool, projection_version: string}
     */
    public function handle(int $limit): array
    {
        $limit = max(1, min($limit, 100));

        return DB::transaction(function () use ($limit): array {
            $afterId = $this->checkpoint();
            $batch = $this->records->nextBatch($afterId, $limit);

            foreach ($batch['rows'] as $row) {
                $this->indexer->handle([
                    'source_module' => 'work-records',
                    'source_type' => 'work_record',
                    'source_id' => $row['id'],
                    'source_version' => (string) $row['lock_version'],
                    'scope_id' => $row['owner_facility_id'],
                    'classification' => $row['classification'],
                    'indexable' => [
                        'title' => is_string($row['payload']['title'] ?? null) ? $row['payload']['title'] : null,
                        'excerpt' => is_string($row['payload']['description'] ?? null) ? $row['payload']['description'] : null,
                    ],
                ]);
            }

            $scanComplete = count($batch['rows']) < $limit;
            if ($batch['rows'] !== []) {
                $this->advanceCheckpoint($scanComplete ? null : $batch['next_id']);
            }

            return [
                'indexed' => count($batch['rows']),
                'complete' => $scanComplete,
                'projection_version' => IndexSourceEventHandler::PROJECTION_VERSION,
            ];
        });
    }

    private function checkpoint(): ?string
    {
        $checkpoint = DB::table('search_checkpoints')
            ->where('consumer', self::CHECKPOINT_CONSUMER)
            ->value('checkpoint');

        return is_string($checkpoint) && $checkpoint !== '' ? $checkpoint : null;
    }

    private function advanceCheckpoint(?string $lastRecordId): void
    {
        $now = now();
        DB::table('search_checkpoints')->upsert(
            [[
                'consumer' => self::CHECKPOINT_CONSUMER,
                'checkpoint' => $lastRecordId,
                'projection_version' => IndexSourceEventHandler::PROJECTION_VERSION,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['consumer'],
            ['checkpoint', 'projection_version', 'updated_at'],
        );
    }
}
