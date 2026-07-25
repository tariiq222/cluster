<?php

namespace Modules\Documents\Features\Upload\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Contracts\DocumentAuthorizationFactsReader;
use Modules\Documents\Contracts\DocumentUploadStatusReader;
use Modules\Documents\Http\DocumentsApi;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use RuntimeException;
use UnexpectedValueException;

final class GetDocumentUploadStatusController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
        private readonly DocumentAuthorizationFactsReader $authorizationFacts,
        private readonly DocumentUploadStatusReader $status,
    ) {}

    public function __invoke(Request $request, string $uploadId): JsonResponse
    {
        $correlationId = DocumentsApi::correlationId($request);
        if ($correlationId === null) {
            return DocumentsApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = DocumentsApi::principalOrProblem($request, $this->principals, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }
        if (! DocumentsApi::isUuidV7($uploadId)) {
            return DocumentsApi::problem(400, 'invalid-document-upload', 'Bad Request', 'The document upload id is invalid.', $correlationId);
        }

        try {
            $facts = $this->authorizationFacts->forUploadIntent($uploadId);
            $actor = DocumentsApi::actorOrProblem($principal, $this->access, $facts, DocumentUploadStatusReader::OPERATION, $correlationId);
            if ($actor instanceof JsonResponse) {
                return $actor;
            }
            $result = $this->status->get($actor, $uploadId);
        } catch (InvalidArgumentException) {
            return DocumentsApi::problem(400, 'invalid-document-upload', 'Bad Request', 'The document upload id is invalid.', $correlationId);
        } catch (UnexpectedValueException $exception) {
            return DocumentsApi::stateProblem($exception, $correlationId);
        } catch (RuntimeException $exception) {
            return DocumentsApi::unavailableProblem($exception, $correlationId);
        } catch (\DomainException $exception) {
            return DocumentsApi::domainProblem($exception, $correlationId);
        }

        return DocumentsApi::response($result->toArray(), 200, $correlationId);
    }
}
