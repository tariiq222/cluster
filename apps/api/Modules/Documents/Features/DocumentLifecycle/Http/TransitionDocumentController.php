<?php

namespace Modules\Documents\Features\DocumentLifecycle\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder;
use Modules\Documents\Application\DocumentMutationHandler;
use Modules\Documents\Http\DocumentsApi;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

/**
 * POST /api/v1/documents/{documentId}/{documentAction} — lifecycle
 * transitions (archive | place-hold | release-hold) with If-Match optimistic
 * locking, Idempotency-Key semantics, central decision and an access event.
 */
final class TransitionDocumentController
{
    use \Modules\Documents\Features\DocumentAccess\Http\DocumentAccessSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
        private readonly DocumentMutationHandler $mutations,
        private readonly DocumentAuthorizationRecordFactsBuilder $documentFacts,
    ) {}

    public function __invoke(Request $request, string $documentId, string $documentAction): JsonResponse
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

        $idempotencyKey = DocumentsApi::idempotencyKey($request);
        if ($idempotencyKey === null) {
            return DocumentsApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $reason = $request->json('reason');
        if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 2000) {
            return DocumentsApi::problem(400, 'invalid-document-transition', 'Bad Request', 'A transition reason is required.', $correlationId);
        }

        $document = $this->findDocument($documentId);
        if ($document === null) {
            return DocumentsApi::problem(404, 'resource-not-found', 'Not Found', 'The document is not available.', $correlationId);
        }
        $capability = in_array($documentAction, ['archive', 'unarchive'], true) ? 'documents.archive' : 'documents.hold';
        if (($deny = $this->decideOnDocument($principal, $this->access, $document, $capability, $correlationId)) instanceof JsonResponse) {
            return $deny;
        }

        $expected = $request->header('If-Match');
        if (! is_string($expected) || preg_match('/\A"([0-9]+)"\z/', $expected, $matches) !== 1 || (int) $matches[1] !== (int) $document->lock_version) {
            return DocumentsApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current version.', $correlationId);
        }

        $operation = 'documents.'.$documentAction;
        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = hash('sha256', json_encode(['action' => $documentAction, 'reason' => $reason], JSON_THROW_ON_ERROR));
        $existing = DB::table('document_idempotency_keys')
            ->where('principal_id', $principal['user_id'])
            ->where('operation', $operation)
            ->where('idempotency_key_hash', $keyHash)
            ->first();
        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash || ! is_string($existing->response_payload)) {
                return DocumentsApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
            }
            $stored = json_decode($existing->response_payload, false, 512, JSON_THROW_ON_ERROR);
            if (! $stored instanceof \stdClass) {
                return DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'The stored transition response is invalid.', $correlationId);
            }

            return response()->json(['data' => $this->serializeDocument($stored)])
                ->header('X-Correlation-ID', $correlationId)
                ->header('ETag', '"'.(int) $stored->lock_version.'"');
        }
        $changes = match ($documentAction) {
            'archive' => ['status' => 'archived'],
            // Unarchive restores a sane status instead of unconditionally
            // resurrecting to active: a document with no current version
            // (draft, rejected) must not be promoted, and a version-less
            // expired document must not be immediately re-archived by the
            // next expiry cycle.
            'unarchive' => ['status' => $document->current_version_id !== null ? 'active' : 'draft'],
            'place-hold' => ['legal_hold' => true, 'legal_hold_reason' => trim($reason), 'legal_hold_at' => now()],
            'release-hold' => ['legal_hold' => false, 'legal_hold_reason' => null, 'legal_hold_at' => null],
            default => null,
        };
        if ($changes === null) {
            return DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'The document operation cannot be completed.', $correlationId);
        }
        if ($documentAction === 'archive') {
            if ((bool) $document->legal_hold) {
                return DocumentsApi::problem(409, 'document-legal-hold-active', 'Conflict', 'The document is under a legal hold and cannot be archived.', $correlationId);
            }
            if ($document->status === 'archived') {
                return DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'The document is not in a state for this action.', $correlationId);
            }
        }
        if ($documentAction === 'unarchive' && $document->status !== 'archived') {
            return DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'Only an archived document can be unarchived.', $correlationId);
        }
        if (in_array($documentAction, ['place-hold', 'release-hold'], true) && $document->status === 'archived') {
            return DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'An archived document cannot be placed on or released from a legal hold.', $correlationId);
        }

        try {
            $fresh = $this->mutations->transition(
                $document,
                $principal,
                $documentAction,
                $changes,
                $operation,
                $keyHash,
                $requestHash,
                $correlationId,
            );
        } catch (\DomainException $exception) {
            if ($exception->getMessage() === 'precondition_failed') {
                return DocumentsApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current version.', $correlationId);
            }

            throw $exception;
        }

        return response()->json(['data' => $this->serializeDocument($fresh)])
            ->header('X-Correlation-ID', $correlationId)
            ->header('ETag', '"'.((int) $fresh->lock_version).'"');
    }
}
