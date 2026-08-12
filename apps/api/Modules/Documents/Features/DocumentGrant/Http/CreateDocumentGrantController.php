<?php

namespace Modules\Documents\Features\DocumentGrant\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Application\DocumentAuthorizationRecordFactsBuilder;
use Modules\Documents\Application\DocumentMutationHandler;
use Modules\Documents\Contracts\DocumentDownloadGrantIssuer;
use Modules\Documents\Http\DocumentsApi;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use RuntimeException;

/**
 * POST /api/v1/documents/{documentId}/{grantType}-grant — issues a one-time
 * preview/download grant only after the central decision passes for both the
 * document and the requested version, then records the access event.
 */
final class CreateDocumentGrantController
{
    use \Modules\Documents\Features\DocumentAccess\Http\DocumentAccessSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
        private readonly DocumentDownloadGrantIssuer $grants,
        private readonly DocumentMutationHandler $mutations,
        private readonly DocumentAuthorizationRecordFactsBuilder $documentFacts,
    ) {}

    public function __invoke(Request $request, string $documentId, string $documentGrantType): JsonResponse
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
        $versionId = $request->json('version_id');
        $purpose = $request->json('purpose');
        if (! is_string($versionId) || ! DocumentsApi::isUuidV7($versionId)
            || ($purpose !== null && (! is_string($purpose) || mb_strlen($purpose) > 500))) {
            return DocumentsApi::problem(400, 'invalid-document-grant', 'Bad Request', 'The document grant payload is invalid.', $correlationId);
        }

        $document = $this->findDocument($documentId);
        if ($document === null) {
            return DocumentsApi::problem(404, 'resource-not-found', 'Not Found', 'The document is not available.', $correlationId);
        }
        $capability = $documentGrantType === 'download' ? 'documents.download' : 'documents.grant';
        if (($deny = $this->decideOnDocument($principal, $this->access, $document, $capability, $correlationId)) instanceof JsonResponse) {
            return $deny;
        }

        $version = DB::table('document_versions')
            ->where('document_id', $document->id)
            ->where('public_id', $versionId)
            ->first();
        if ($version === null) {
            return DocumentsApi::problem(404, 'document-upload-not-found', 'Not Found', 'The document version is not available.', $correlationId);
        }
        if ($version->availability_status !== 'available') {
            return DocumentsApi::problem(409, 'document-upload-invalid-state', 'Conflict', 'The document version is not available for a grant.', $correlationId);
        }

        $operation = 'documents.'.$documentGrantType.'-grant';
        $keyHash = hash('sha256', $idempotencyKey);
        $requestHash = hash('sha256', json_encode(['version_id' => $versionId, 'purpose' => $purpose, 'type' => $documentGrantType], JSON_THROW_ON_ERROR));
        $existing = DB::table('document_idempotency_keys')
            ->where('principal_id', $principal['user_id'])
            ->where('operation', $operation)
            ->where('idempotency_key_hash', $keyHash)
            ->first();
        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash || ! is_string($existing->response_payload)) {
                return DocumentsApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
            }

            return response()->json(['data' => json_decode($existing->response_payload, true, 512, JSON_THROW_ON_ERROR)], 201)
                ->header('X-Correlation-ID', $correlationId);
        }

        try {
            $grant = $this->grants->issue((string) $document->id, (string) $version->id, $principal['user_id']);
        } catch (RuntimeException) {
            return DocumentsApi::problem(503, 'document-storage-unavailable', 'Service Unavailable', 'The document storage service is temporarily unavailable.', $correlationId);
        }

        $payload = [...$grant->toArray(), 'grant_type' => $documentGrantType, 'document_id' => $document->public_id, 'version_id' => $version->public_id];
        $this->mutations->recordGrant(
            $document,
            $version,
            $principal['user_id'],
            $documentGrantType,
            $operation,
            $keyHash,
            $requestHash,
            $payload,
            $correlationId,
        );

        return response()->json(['data' => $payload], 201)->header('X-Correlation-ID', $correlationId);
    }
}
