<?php

namespace Modules\Documents\Features\DocumentVersion\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use JsonException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Application\DocumentAuthorizationFacts;
use Modules\Documents\Application\DocumentMetadata;
use Modules\Documents\Application\IdempotencyContext;
use Modules\Documents\Application\InitiateDocumentUpload;
use Modules\Documents\Application\UploadFileMetadata;
use Modules\Documents\Features\Upload\DocumentUploadHandler;
use Modules\Documents\Http\DocumentsApi;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use RuntimeException;
use UnexpectedValueException;

/** POST /api/v1/documents/{documentId}/versions — starts a secure quarantine upload. */
final class AddDocumentVersionController
{
    use \Modules\Documents\Features\DocumentAccess\Http\DocumentAccessSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
        private readonly DocumentUploadHandler $handler,
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
        $idempotencyKey = DocumentsApi::idempotencyKey($request);
        if ($idempotencyKey === null) {
            return DocumentsApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $principal = DocumentsApi::principalOrProblem($request, $this->principals, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }
        $document = $this->findDocument($documentId);
        if ($document === null) {
            return DocumentsApi::problem(404, 'resource-not-found', 'Not Found', 'The document is not available.', $correlationId);
        }
        if (($deny = $this->decideOnDocument($principal, $this->access, $document, DocumentUploadHandler::INITIATE_OPERATION, $correlationId)) instanceof JsonResponse) {
            return $deny;
        }
        if ((string) $principal['facility_id'] !== (string) $document->owner_organization_unit_id) {
            return DocumentsApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'file_name' => ['required', 'string', 'min:1', 'max:255'],
            'content_type' => ['required', 'string', 'regex:/\A[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*\z/'],
            'byte_size' => ['required', 'integer', 'min:1', 'max:1073741824'],
            'sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['file_name', 'content_type', 'byte_size', 'sha256']) !== []) {
            return DocumentsApi::problem(400, 'invalid-document-version', 'Bad Request', 'The document version payload is invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $semantics = [
            'document_id' => $documentId,
            'file_name' => (string) $validated['file_name'],
            'content_type' => (string) $validated['content_type'],
            'byte_size' => (int) $validated['byte_size'],
            'sha256' => (string) $validated['sha256'],
        ];
        try {
            $actor = DocumentsApi::actorOrProblem(
                $principal,
                $this->access,
                new DocumentAuthorizationFacts((string) $document->owner_organization_unit_id, (string) $document->classification),
                DocumentUploadHandler::INITIATE_OPERATION,
                $correlationId,
            );
            if ($actor instanceof JsonResponse) {
                return $actor;
            }
            $result = $this->handler->initiate(
                $actor,
                new InitiateDocumentUpload(
                    'document_version',
                    new DocumentMetadata((string) $document->name, $document->description, (string) $document->classification),
                    new UploadFileMetadata($semantics['file_name'], $semantics['byte_size'], $semantics['content_type'], $semantics['sha256']),
                    $documentId,
                ),
                new IdempotencyContext(
                    $principal['user_id'],
                    DocumentUploadHandler::INITIATE_OPERATION,
                    $idempotencyKey,
                    hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
                ),
            );
        } catch (JsonException|InvalidArgumentException) {
            return DocumentsApi::problem(400, 'invalid-document-version', 'Bad Request', 'The document version payload is invalid.', $correlationId);
        } catch (UnexpectedValueException $exception) {
            return DocumentsApi::stateProblem($exception, $correlationId);
        } catch (RuntimeException $exception) {
            return DocumentsApi::unavailableProblem($exception, $correlationId);
        } catch (\DomainException $exception) {
            return DocumentsApi::domainProblem($exception, $correlationId);
        }

        return DocumentsApi::response([
            'upload_id' => $result->uploadIntent->id,
            'quarantine_object_id' => $result->quarantineObjectId,
            'purpose' => $result->purpose,
            'method' => $result->uploadIntent->method,
            'upload_url' => $result->uploadIntent->url,
            'required_headers' => $result->uploadIntent->requiredHeaders,
            'expires_at' => $result->uploadIntent->expiresAt->format('Y-m-d\TH:i:s.v\Z'),
            'max_size_bytes' => $result->maxSizeBytes,
        ], 201, $correlationId);
    }
}
