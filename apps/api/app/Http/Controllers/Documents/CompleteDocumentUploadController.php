<?php

namespace App\Http\Controllers\Documents;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use JsonException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Application\CompleteDocumentUpload;
use Modules\Documents\Application\IdempotencyContext;
use Modules\Documents\Application\RetryableStorageException;
use Modules\Documents\Contracts\DocumentAuthorizationFactsReader;
use Modules\Documents\Features\Upload\DocumentUploadHandler;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use RuntimeException;
use UnexpectedValueException;

final class CompleteDocumentUploadController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
        private readonly DocumentAuthorizationFactsReader $authorizationFacts,
        private readonly DocumentUploadHandler $handler,
    ) {}

    public function __invoke(Request $request, string $uploadId): JsonResponse
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
            'sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'byte_size' => ['required', 'integer', 'min:1', 'max:1073741824'],
        ]);
        if (! DocumentsApi::isUuidV7($uploadId)
            || $validator->fails()
            || array_diff(array_keys($input), ['sha256', 'byte_size']) !== []) {
            return DocumentsApi::problem(400, 'invalid-document-upload', 'Bad Request', 'The document upload completion payload is invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $semantics = [
            'upload_id' => $uploadId,
            'sha256' => (string) $validated['sha256'],
            'byte_size' => (int) $validated['byte_size'],
        ];

        try {
            $facts = $this->authorizationFacts->forUploadIntent($uploadId);
            $actor = DocumentsApi::actorOrProblem($principal, $this->access, $facts, DocumentUploadHandler::COMPLETE_OPERATION, $correlationId);
            if ($actor instanceof JsonResponse) {
                return $actor;
            }
            $result = $this->handler->complete(
                $actor,
                $uploadId,
                new CompleteDocumentUpload($semantics['sha256'], $semantics['byte_size']),
                new IdempotencyContext(
                    $actor->principalId,
                    DocumentUploadHandler::COMPLETE_OPERATION,
                    $idempotencyKey,
                    hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
                ),
            );
        } catch (RetryableStorageException $exception) {
            return DocumentsApi::retryableStorageProblem($exception, $correlationId);
        } catch (InvalidArgumentException|JsonException) {
            return DocumentsApi::problem(400, 'invalid-document-upload', 'Bad Request', 'The document upload completion payload is invalid.', $correlationId);
        } catch (UnexpectedValueException $exception) {
            return DocumentsApi::stateProblem($exception, $correlationId);
        } catch (RuntimeException $exception) {
            return DocumentsApi::unavailableProblem($exception, $correlationId);
        } catch (\DomainException $exception) {
            return DocumentsApi::domainProblem($exception, $correlationId);
        }

        return DocumentsApi::response($result->toArray(), 202, $correlationId);
    }
}
