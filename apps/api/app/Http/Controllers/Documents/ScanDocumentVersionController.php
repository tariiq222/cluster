<?php

namespace App\Http\Controllers\Documents;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Application\IdempotencyContext;
use Modules\Documents\Application\RetryableStorageException;
use Modules\Documents\Contracts\DocumentAuthorizationFactsReader;
use Modules\Documents\Contracts\WorkerPrincipalResolver;
use Modules\Documents\Features\Upload\DocumentUploadHandler;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use RuntimeException;
use UnexpectedValueException;

/** Internal-only action; route wiring must enforce a worker-only authentication boundary. */
final class ScanDocumentVersionController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
        private readonly DocumentAuthorizationFactsReader $authorizationFacts,
        private readonly DocumentUploadHandler $handler,
    ) {
        if (! $principals instanceof WorkerPrincipalResolver) {
            throw new DomainException('document_internal_endpoint_requires_worker_resolver');
        }
    }

    public function __invoke(Request $request, string $versionId): JsonResponse
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
        if (! DocumentsApi::isUuidV7($versionId) || $request->json()->all() !== []) {
            return DocumentsApi::problem(400, 'invalid-document-action', 'Bad Request', 'The internal document action payload is invalid.', $correlationId);
        }
        $semantics = ['version_id' => $versionId];

        try {
            $facts = $this->authorizationFacts->forVersion($versionId);
            $actor = DocumentsApi::actorOrProblem($principal, $this->access, $facts, DocumentUploadHandler::SCAN_OPERATION, $correlationId);
            if ($actor instanceof JsonResponse) {
                return $actor;
            }
            $result = $this->handler->scanVersion(
                $actor,
                $versionId,
                new IdempotencyContext($actor->principalId, DocumentUploadHandler::SCAN_OPERATION, $idempotencyKey, hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR))),
            );
        } catch (RetryableStorageException $exception) {
            return DocumentsApi::retryableStorageProblem($exception, $correlationId);
        } catch (InvalidArgumentException|JsonException) {
            return DocumentsApi::problem(400, 'invalid-document-action', 'Bad Request', 'The internal document action payload is invalid.', $correlationId);
        } catch (UnexpectedValueException $exception) {
            return DocumentsApi::stateProblem($exception, $correlationId);
        } catch (RuntimeException $exception) {
            return DocumentsApi::unavailableProblem($exception, $correlationId);
        } catch (DomainException $exception) {
            return DocumentsApi::domainProblem($exception, $correlationId);
        }

        return DocumentsApi::response($result->toArray(), 202, $correlationId);
    }
}
