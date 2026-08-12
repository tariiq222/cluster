<?php

namespace Modules\Documents\Features\DocumentLifecycle\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder;
use Modules\Documents\Http\DocumentsApi;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

/**
 * GET /api/v1/documents — lists document metadata inside the principal's
 * organizational scope; the scope predicate applies before pagination and a
 * per-row decision still runs before any row is returned.
 */
final class ListDocumentsController
{
    use \Modules\Documents\Features\DocumentAccess\Http\DocumentAccessSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
        private readonly DocumentAuthorizationRecordFactsBuilder $documentFacts,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = DocumentsApi::correlationId($request);
        if ($correlationId === null) {
            return DocumentsApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = DocumentsApi::principalOrProblem($request, $this->principals, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }

        $limit = (int) $request->query('limit', 25);
        if ($limit < 1 || $limit > 100) {
            return DocumentsApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }
        $classification = $request->query('classification');
        if ($classification !== null && ! in_array($classification, ['public', 'internal', 'confidential', 'top_secret'], true)) {
            return DocumentsApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }

        $scopeIds = array_filter([$principal['facility_id']]);
        $query = DB::table('documents')->orderBy('id');
        if ($scopeIds !== []) {
            $query->whereIn('owner_organization_unit_id', $scopeIds);
        } else {
            $query->whereRaw('1 = 0');
        }
        if ($classification !== null) {
            $query->where('classification', $classification);
        }
        $cursor = $request->query('cursor');
        if (is_string($cursor) && $cursor !== '') {
            $query->where('id', '>', $cursor);
        }

        $allowed = [];
        $scanCursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
        $exhausted = false;
        while (count($allowed) <= $limit && ! $exhausted) {
            $batchQuery = clone $query;
            if ($scanCursor !== null) {
                $batchQuery->where('id', '>', $scanCursor);
            }
            $rows = $batchQuery->limit(max($limit + 1, 25))->get()->all();
            $exhausted = count($rows) < max($limit + 1, 25);
            foreach ($rows as $row) {
                $scanCursor = (string) $row->id;
                $actions = $this->allowedActionsForDocument($principal, $row, $correlationId);
                if ($actions !== []) {
                    $allowed[] = [$row, $actions];
                }
            }
        }

        $hasNextPage = count($allowed) > $limit;
        if ($hasNextPage) {
            array_pop($allowed);
        }
        $lastAllowed = $allowed === [] ? null : $allowed[count($allowed) - 1];
        $nextCursor = $hasNextPage && is_array($lastAllowed) ? (string) $lastAllowed[0]->id : null;

        return response()->json([
            'items' => array_map(fn (array $item): array => $this->serializeDocument($item[0], $item[1]), $allowed),
            'next_cursor' => $nextCursor,
        ])->header('X-Correlation-ID', $correlationId);
    }
}
