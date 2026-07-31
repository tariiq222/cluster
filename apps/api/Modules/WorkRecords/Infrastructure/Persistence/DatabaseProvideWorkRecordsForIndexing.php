<?php

namespace Modules\WorkRecords\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\WorkRecords\Contracts\ProvideWorkRecordsForIndexing;
use stdClass;

final class DatabaseProvideWorkRecordsForIndexing implements ProvideWorkRecordsForIndexing
{
    /**
     * @return array{rows: list<array{id: string, owner_facility_id: string, classification: string, lock_version: int, payload: array<string, mixed>}>, next_id: string|null}
     */
    public function nextBatch(?string $afterId, int $limit): array
    {
        $query = DB::table('work_records')
            ->select(['id', 'owner_facility_id', 'classification', 'lock_version', 'payload'])
            ->orderBy('id')
            ->limit($limit);
        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        $rows = $query->get();
        $last = $rows->last();

        return [
            'rows' => $rows->map(static function (stdClass $row): array {
                $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);

                return [
                    'id' => $row->id,
                    'owner_facility_id' => $row->owner_facility_id,
                    'classification' => $row->classification,
                    'lock_version' => (int) $row->lock_version,
                    'payload' => is_array($payload) ? $payload : [],
                ];
            })->all(),
            'next_id' => $last instanceof stdClass ? $last->id : null,
        ];
    }
}
