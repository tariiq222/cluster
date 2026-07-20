<?php

namespace App\Http\Controllers\Documents;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

/**
 * POST /api/v1/documents/{documentId}/{documentAction} — lifecycle
 * transitions (archive | place-hold | release-hold) with If-Match optimistic
 * locking, Idempotency-Key semantics, central decision and an access event.
 */
final class TransitionDocumentController
{
    use DocumentAccessSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
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
        $capability = $documentAction === 'archive' ? 'documents.archive' : 'documents.hold';
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
            if ($existing->request_hash !== $requestHash) {
                return DocumentsApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
            }

            $current = DB::table('documents')->where('id', $document->id)->first();

            return response()->json(['data' => $this->serializeDocument($current ?? $document)])
                ->header('X-Correlation-ID', $correlationId)
                ->header('ETag', '"'.(int) ($current->lock_version ?? $document->lock_version).'"');
        }

        $changes = match ($documentAction) {
            'archive' => ['status' => 'archived'],
            'place-hold' => ['legal_hold' => true, 'legal_hold_reason' => trim($reason), 'legal_hold_at' => now()],
            'release-hold' => ['legal_hold' => false, 'legal_hold_reason' => null, 'legal_hold_at' => null],
            default => null,
        };
        if ($changes === null) {
            return DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'The document operation cannot be completed.', $correlationId);
        }
        if ($documentAction === 'archive' && $document->status === 'archived') {
            return DocumentsApi::problem(409, 'document-upload-invalid-state', 'Conflict', 'The document is not in a state for this action.', $correlationId);
        }

        $updated = DB::table('documents')
            ->where('id', $document->id)
            ->where('lock_version', (int) $document->lock_version)
            ->update([...$changes, 'lock_version' => (int) $document->lock_version + 1, 'updated_at' => now()]);
        if ($updated !== 1) {
            return DocumentsApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current version.', $correlationId);
        }

        DB::table('document_idempotency_keys')->insert([
            'id' => Str::uuid7()->toString(),
            'principal_id' => $principal['user_id'],
            'operation' => $operation,
            'idempotency_key_hash' => $keyHash,
            'request_hash' => $requestHash,
            'resource_type' => 'document',
            'resource_id' => $document->id,
            'response_payload' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->recordAccessEvent($document, $principal['user_id'], $documentAction, 'allow', 'document_transition_allowed');

        $fresh = DB::table('documents')->where('id', $document->id)->first();

        return response()->json(['data' => $this->serializeDocument($fresh ?? $document)])
            ->header('X-Correlation-ID', $correlationId)
            ->header('ETag', '"'.((int) $document->lock_version + 1).'"');
    }
}
