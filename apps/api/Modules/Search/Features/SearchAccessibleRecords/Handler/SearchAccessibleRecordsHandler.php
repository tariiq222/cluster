<?php

namespace Modules\Search\Features\SearchAccessibleRecords\Handler;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\AccessProjection;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;

final class SearchAccessibleRecordsHandler
{
    /**
     * Per-row authorization can reject candidate rows (denied scope, classification
     * mismatch, etc.), so we over-fetch the projection before authorization to keep
     * the items window full even when many rows are denied. The hard ceiling stops a
     * single query from blowing up if the projection table grows large.
     */
    private const CANDIDATE_OVER_FETCH_FACTOR = 5;

    private const CANDIDATE_HARD_CEILING = 500;

    public function __construct(private readonly DecideAccess $access) {}

    /**
     * @param  array{user_id?: string, facility_id?: string}  $actor
     * @return array{items: list<array<string, mixed>>, total: int, next_cursor: null}
     */
    public function handle(array $actor, string $query, ?string $scopeId = null, int $limit = 25): array
    {
        $query = trim($query);
        $limit = max(1, min($limit, 100));
        $candidateLimit = min($limit * self::CANDIDATE_OVER_FETCH_FACTOR, self::CANDIDATE_HARD_CEILING);
        $builder = DB::table('search_index_entries')
            ->where('visibility', 'eligible')
            ->orderBy('id')
            ->limit($candidateLimit);

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
                'search.query',
                new RecordFacts($row->scope_id, $row->source_type, $row->classification),
            );
            if (! $decision->isAllowed()) {
                continue;
            }

            $total++;
            if (count($items) < $limit) {
                $items[] = AccessProjection::fromDecision($decision)->compose([
                    'id' => $row->id,
                    'source_type' => $row->source_type,
                    'source_id' => $row->source_id,
                    'title' => $row->title,
                    'excerpt' => $row->excerpt,
                    'scope_id' => $row->scope_id,
                ]);
            }
        }

        return ['items' => $items, 'total' => $total, 'next_cursor' => null];
    }
}
