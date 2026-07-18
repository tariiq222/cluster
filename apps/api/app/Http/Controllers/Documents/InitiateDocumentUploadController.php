<?php

namespace App\Http\Controllers\Documents;

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
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use RuntimeException;
use UnexpectedValueException;

final class InitiateDocumentUploadController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
        private readonly DocumentUploadHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = DocumentsApi::correlationId($request);
        if ($correlationId === null) {
            return DocumentsApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $idempotencyKey = DocumentsApi::idempotencyKey($request);
        if ($idempotencyKey === null) {
            return DocumentsApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $principal = DocumentsApi::principalOrProblem($request, $this->principals, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }
        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'classification' => ['required', 'string', 'in:public,internal,confidential,top_secret'],
            'file_name' => ['required', 'string', 'min:1', 'max:255'],
            'content_type' => ['required', 'string', 'regex:/\A[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*\z/'],
            'byte_size' => ['required', 'integer', 'min:1', 'max:1073741824'],
            'sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $allowed = ['name', 'description', 'classification', 'file_name', 'content_type', 'byte_size', 'sha256'];
        if ($validator->fails() || array_diff(array_keys($input), $allowed) !== []) {
            return DocumentsApi::problem(400, 'invalid-document-upload', 'Bad Request', 'The document upload payload is invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $facts = new DocumentAuthorizationFacts($principal['facility_id'], (string) $validated['classification']);
        $actor = DocumentsApi::actorOrProblem($principal, $this->access, $facts, DocumentUploadHandler::INITIATE_OPERATION, $correlationId);
        if ($actor instanceof JsonResponse) {
            return $actor;
        }
        $semantics = [
            'name' => (string) $validated['name'],
            'description' => $validated['description'] ?? null,
            'classification' => (string) $validated['classification'],
            'file_name' => (string) $validated['file_name'],
            'content_type' => (string) $validated['content_type'],
            'byte_size' => (int) $validated['byte_size'],
            'sha256' => (string) $validated['sha256'],
        ];

        try {
            $result = $this->handler->initiate(
                $actor,
                new InitiateDocumentUpload(
                    new DocumentMetadata($semantics['name'], $semantics['description'], $semantics['classification']),
                    new UploadFileMetadata($semantics['file_name'], $semantics['byte_size'], $semantics['content_type'], $semantics['sha256']),
                ),
                new IdempotencyContext(
                    $actor->principalId,
                    DocumentUploadHandler::INITIATE_OPERATION,
                    $idempotencyKey,
                    hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
                ),
            );
        } catch (InvalidArgumentException|JsonException) {
            return DocumentsApi::problem(400, 'invalid-document-upload', 'Bad Request', 'The document upload payload is invalid.', $correlationId);
        } catch (UnexpectedValueException $exception) {
            return DocumentsApi::stateProblem($exception, $correlationId);
        } catch (RuntimeException $exception) {
            return DocumentsApi::unavailableProblem($exception, $correlationId);
        } catch (\DomainException $exception) {
            return DocumentsApi::domainProblem($exception, $correlationId);
        }

        return DocumentsApi::response($result->toArray(), 201, $correlationId);
    }
}
