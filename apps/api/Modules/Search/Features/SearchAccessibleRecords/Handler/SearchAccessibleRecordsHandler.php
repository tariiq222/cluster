<?php

namespace Modules\Search\Features\SearchAccessibleRecords\Handler;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;

final class SearchAccessibleRecordsHandler
{
    public function __construct(private readonly DecideAccess $access) {}

    /**
     * @param  array{user_id?: string, facility_id?: string}  $actor
     * @return array{items: list<array<string, mixed>>, total: int, next_cursor: null}
     */
    public function handle(array $actor, string $query, ?string $scopeId = null, int $limit = 25): array
    {
        $query = trim($query);
        $limit = max(1, min($limit, 100));
        $builder = DB::table('search_index_entries')
            ->where('visibility', 'eligible')
            ->orderBy('id');

        if ($scopeId !== null) {
            $builder->where('scope_id', $scopeId);
        }
        if ($query !== '') {
            $builder->where('search_text', 'like', '%'.$query.'%');
        }

        $items = [];
        $total = 0;
        foreach ($builder->get() as $row) {
            $decision = $this->access->decide(
                $actor,
                'work_record.read',
                new RecordFacts($row->scope_id, $row->source_type, $row->classification),
            );
            if (! $decision->isAllowed()) {
                continue;
            }

            $total++;
            if (count($items) < $limit) {
                $items[] = [
                    'id' => $row->id,
                    'source_type' => $row->source_type,
                    'source_id' => $row->source_id,
                    'title' => $row->title,
                    'excerpt' => $row->excerpt,
                    'scope_id' => $row->scope_id,
                ];
            }
        }

        return ['items' => $items, 'total' => $total, 'next_cursor' => null];
    }
}
