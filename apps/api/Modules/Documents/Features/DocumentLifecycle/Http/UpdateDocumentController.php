<?php

namespace Modules\Documents\Features\DocumentLifecycle\Http;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Application\DocumentMutationHandler;
use Modules\Documents\Http\DocumentsApi;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

/** PATCH /api/v1/documents/{documentId} — governed metadata update. */
final class UpdateDocumentController
{
    use \Modules\Documents\Features\DocumentAccess\Http\DocumentAccessSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
        private readonly DocumentMutationHandler $mutations,
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
        $expectedVersion = DocumentsApi::ifMatch($request);
        if ($expectedVersion === null) {
            return DocumentsApi::problem(400, 'invalid-if-match', 'Bad Request', 'If-Match must contain one current strong ETag.', $correlationId);
        }
        if (! DocumentsApi::isMergePatch($request)) {
            return DocumentsApi::problem(400, 'invalid-content-type', 'Bad Request', 'Content-Type must be application/merge-patch+json.', $correlationId);
        }
        $principal = DocumentsApi::principalOrProblem($request, $this->principals, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }

        $document = $this->findDocument($documentId);
        if ($document === null) {
            return DocumentsApi::problem(404, 'resource-not-found', 'Not Found', 'The document is not available.', $correlationId);
        }
        if (($deny = $this->decideOnDocument($principal, $this->access, $document, 'documents.update', $correlationId)) instanceof JsonResponse) {
            return $deny;
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:2000'],
            'classification' => ['sometimes', 'required', 'string', 'in:public,internal,confidential,top_secret'],
            'classification_change_reason' => ['sometimes', 'required', 'string', 'min:1', 'max:2000'],
        ]);
        if ($validator->fails() || $input === [] || array_diff(array_keys($input), ['title', 'description', 'classification', 'classification_change_reason']) !== []) {
            return DocumentsApi::problem(400, 'invalid-document', 'Bad Request', 'The document patch is invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $changes = array_intersect_key($validated, array_flip(['title', 'description', 'classification']));
        if ($changes === []) {
            return DocumentsApi::problem(400, 'invalid-document', 'Bad Request', 'The document patch is invalid.', $correlationId);
        }
        if (array_key_exists('title', $changes)) {
            $changes['name'] = trim((string) $changes['title']);
            unset($changes['title']);
        }
        if (array_key_exists('classification', $changes)
            && $changes['classification'] !== $document->classification
            && (! isset($validated['classification_change_reason']) || trim((string) $validated['classification_change_reason']) === '')) {
            return DocumentsApi::problem(400, 'invalid-document', 'Bad Request', 'A classification change reason is required.', $correlationId);
        }

        try {
            $fresh = $this->mutations->update($document, $expectedVersion, $changes, $validated, $principal, $correlationId);
        } catch (\DomainException $exception) {
            if ($exception->getMessage() === 'precondition_failed') {
                return DocumentsApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current version.', $correlationId);
            }

            return DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'The document cannot be updated.', $correlationId);
        } catch (QueryException) {
            return DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'The document cannot be updated.', $correlationId);
        }

        return response()->json(['data' => $this->serializeDocument($fresh)])
            ->header('X-Correlation-ID', $correlationId)
            ->header('ETag', '"'.(int) $fresh->lock_version.'"');
    }
}
