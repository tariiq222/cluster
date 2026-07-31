<?php

namespace Modules\WorkRecords\Contracts;

/**
 * Exposes bounded, keyset-paginated work record rows to read-model consumers
 * (Search backfill). The WorkRecords module owns the underlying table; this
 * contract keeps the dependency edges flowing inward while letting higher
 * rank modules re-index work records idempotently without cross-module SQL.
 */
interface ProvideWorkRecordsForIndexing
{
    /**
     * Returns up to `$limit` work records ordered by id, starting after the
     * optional exclusive cursor. `next_id` is the id of the last returned
     * row (null when the batch is empty) and can be stored as a checkpoint
     * to resume later.
     *
     * @return array{rows: list<array{id: string, owner_facility_id: string, classification: string, lock_version: int, payload: array<string, mixed>}>, next_id: string|null}
     */
    public function nextBatch(?string $afterId, int $limit): array;
}
