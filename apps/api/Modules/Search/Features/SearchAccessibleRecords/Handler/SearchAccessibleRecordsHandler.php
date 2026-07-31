<?php

namespace Modules\Search\Features\SearchAccessibleRecords\Handler;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
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

    private const CURSOR_VERSION = 1;

    public function __construct(private readonly DecideAccess $access) {}

    /**
     * @param  array{user_id?: string, facility_id?: string}  $actor
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function handle(
        array $actor,
        string $query,
        ?string $scopeId = null,
        int $limit = 25,
        ?string $cursor = null,
        ?string $type = null,
        ?string $status = null,
    ): array {
        $query = trim($query);
        $limit = max(1, min($limit, 100));
        $afterId = $cursor === null
            ? null
            : $this->decodeCursor($cursor, $actor, $limit, $query, $scopeId, $type, $status);
        $candidateLimit = min($limit * self::CANDIDATE_OVER_FETCH_FACTOR, self::CANDIDATE_HARD_CEILING);
        $builder = DB::table('search_index_entries')
            ->where('visibility', 'eligible')
            ->orderBy('id')
            ->limit($candidateLimit);

        if ($scopeId !== null) {
            $builder->where('scope_id', $scopeId);
        }
        if ($type !== null && $type !== '') {
            $builder->where('source_type', $type);
        }
        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }
        if ($query !== '') {
            $builder->whereRaw('search_text LIKE ? ESCAPE ?', ['%'.$this->escapeLike($query).'%', '\\']);
        }
        if ($afterId !== null) {
            $builder->where('id', '>', $afterId);
        }

        $authorized = [];
        foreach ($builder->get() as $row) {
            $decision = $this->access->decide(
                $actor,
                'search.query',
                new RecordFacts($row->scope_id, $row->source_type, $row->classification),
            );
            if (! $decision->isAllowed()) {
                continue;
            }

            $authorized[] = AccessProjection::fromDecision($decision)->compose([
                'id' => $row->id,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'title' => $row->title,
                'excerpt' => $row->excerpt,
                'scope_id' => $row->scope_id,
            ]);
            if (count($authorized) > $limit) {
                break;
            }
        }

        $hasNextPage = count($authorized) > $limit;
        if ($hasNextPage) {
            array_pop($authorized);
        }

        return [
            'items' => $authorized,
            'next_cursor' => $hasNextPage
                ? $this->encodeCursor(
                    $authorized[array_key_last($authorized)]['id'],
                    $actor,
                    $limit,
                    $query,
                    $scopeId,
                    $type,
                    $status,
                )
                : null,
        ];
    }

    private function escapeLike(string $query): string
    {
        return addcslashes($query, '%_\\');
    }

    /** @param array{user_id?: string, facility_id?: string} $actor */
    private function encodeCursor(
        string $entryId,
        array $actor,
        int $limit,
        string $query,
        ?string $scopeId,
        ?string $type,
        ?string $status,
    ): string {
        return Crypt::encryptString(json_encode([
            'version' => self::CURSOR_VERSION,
            'after_id' => $entryId,
            'query' => [
                'limit' => $limit,
                'q' => $query,
                'scope_id' => $scopeId,
                'type' => $type,
                'status' => $status,
            ],
            'scope' => [
                'principal_id' => $actor['user_id'] ?? '',
                'facility_id' => $actor['facility_id'] ?? '',
            ],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array{user_id?: string, facility_id?: string} $actor */
    private function decodeCursor(
        string $cursor,
        array $actor,
        int $limit,
        string $query,
        ?string $scopeId,
        ?string $type,
        ?string $status,
    ): string {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('The pagination cursor is invalid.');
        }

        if (! is_array($payload)
            || array_keys($payload) !== ['version', 'after_id', 'query', 'scope']
            || $payload['version'] !== self::CURSOR_VERSION
            || ! is_array($payload['query'])
            || array_keys($payload['query']) !== ['limit', 'q', 'scope_id', 'type', 'status']
            || $payload['query']['limit'] !== $limit
            || $payload['query']['q'] !== $query
            || $payload['query']['scope_id'] !== $scopeId
            || $payload['query']['type'] !== $type
            || $payload['query']['status'] !== $status
            || ! is_array($payload['scope'])
            || array_keys($payload['scope']) !== ['principal_id', 'facility_id']
            || ! is_string($payload['scope']['principal_id'])
            || ! hash_equals((string) $actor['user_id'], $payload['scope']['principal_id'])
            || ! is_string($payload['scope']['facility_id'])
            || ! hash_equals((string) $actor['facility_id'], $payload['scope']['facility_id'])
            || ! is_string($payload['after_id'])
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $payload['after_id']) !== 1) {
            throw new InvalidArgumentException('The pagination cursor is invalid.');
        }

        return $payload['after_id'];
    }
}
