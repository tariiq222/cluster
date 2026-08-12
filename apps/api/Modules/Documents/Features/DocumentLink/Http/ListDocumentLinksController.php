<?php

namespace Modules\Documents\Features\DocumentLink\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder;
use Modules\Documents\Http\DocumentsApi;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

/** GET /api/v1/documents/{documentId}/links — lists active source links. */
final class ListDocumentLinksController
{
    use \Modules\Documents\Features\DocumentAccess\Http\DocumentAccessSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
        private readonly DocumentAuthorizationRecordFactsBuilder $documentFacts,
    ) {}

    public function __invoke(Request $request, string $documentId): JsonResponse
    {
        $correlationId = DocumentsApi::correlationId($request);
        if ($correlationId === null) {
            return DocumentsApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! DocumentsApi::isUuidV7($documentId)) {
            return DocumentsApi::problem(400, 'invalid-document', 'Bad Request', 'The document id is invalid.', $correlationId);
        }
        $principal = DocumentsApi::principalOrProblem($request, $this->principals, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }
        $document = $this->findDocument($documentId);
        if ($document === null) {
            return DocumentsApi::problem(404, 'resource-not-found', 'Not Found', 'The document is not available.', $correlationId);
        }
        if (($deny = $this->decideOnDocument($principal, $this->access, $document, 'documents.read', $correlationId)) instanceof JsonResponse) {
            return $deny;
        }

        $limit = (int) $request->query('limit', 25);
        if ($limit < 1 || $limit > 100) {
            return DocumentsApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }
        $query = DB::table('document_links')
            ->where('document_id', $document->id)
            ->where('status', 'active')
            ->orderBy('id');
        $cursor = $request->query('cursor');
        if (is_string($cursor) && $cursor !== '') {
            $query->where('id', '>', $cursor);
        }
        $rows = $query->limit($limit + 1)->get()->all();
        $hasNextPage = count($rows) > $limit;
        if ($hasNextPage) {
            array_pop($rows);
        }

        return response()->json([
            'items' => array_map(static fn (\stdClass $row): array => [
                'id' => $row->id,
                'resource_type' => 'document_link',
                'document_id' => $document->public_id,
                'status' => $row->status,
                'classification' => $row->link_classification,
                'source' => [
                    'source_module' => $row->source_module,
                    'record_type' => $row->source_type,
                    'record_id' => $row->source_id,
                ],
                'relation_type' => $row->relation_type,
                'constraint_policy_key' => $row->constraint_policy_key ?? null,
                'lock_version' => 1,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ], $rows),
            'next_cursor' => $hasNextPage && $rows !== [] ? (string) end($rows)->id : null,
        ])->header('X-Correlation-ID', $correlationId);
    }
}
