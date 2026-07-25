<?php

namespace Modules\Documents\Features\DocumentLifecycle\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Documents\Http\DocumentsApi;

/**
 * GET /api/v1/documents/{documentId} — authorized metadata read; a denied
 * resource returns no metadata (existence is not leaked beyond 403/404
 * convention used by sibling endpoints).
 */
final class GetDocumentController
{
    use \Modules\Documents\Features\DocumentAccess\Http\DocumentAccessSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
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

        return response()->json(['data' => $this->serializeDocument($document, $this->allowedActionsForDocument($principal, $document, $correlationId))])
            ->header('X-Correlation-ID', $correlationId)
            ->header('ETag', '"'.(int) $document->lock_version.'"');
    }
}
