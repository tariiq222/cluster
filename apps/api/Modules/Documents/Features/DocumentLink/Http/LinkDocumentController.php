<?php

namespace Modules\Documents\Features\DocumentLink\Http;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Application\DocumentMutationHandler;
use Modules\Documents\Http\DocumentsApi;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

/** POST /api/v1/documents/{documentId}/links — links a document to a source. */
final class LinkDocumentController
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
        if (($deny = $this->decideOnDocument($principal, $this->access, $document, 'documents.link', $correlationId)) instanceof JsonResponse) {
            return $deny;
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'source' => ['required', 'array'],
            'source.source_module' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]{1,63}\z/'],
            'source.record_type' => ['required', 'string', 'min:1', 'max:128'],
            'source.record_id' => ['required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'relation_type' => ['required', 'string', 'min:1', 'max:64'],
            'constraint_policy_key' => ['sometimes', 'nullable', 'string', 'min:1', 'max:128'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['source', 'relation_type', 'constraint_policy_key']) !== []) {
            return DocumentsApi::problem(400, 'invalid-document-link', 'Bad Request', 'The document link payload is invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $source = $validated['source'];
        $constraintPolicyKey = isset($validated['constraint_policy_key']) && is_string($validated['constraint_policy_key'])
            ? trim($validated['constraint_policy_key'])
            : null;
        if ($constraintPolicyKey === '') {
            return DocumentsApi::problem(400, 'invalid-document-link', 'Bad Request', 'The document link payload is invalid.', $correlationId);
        }
        $semantics = ['source' => $source, 'relation_type' => $validated['relation_type'], 'constraint_policy_key' => $constraintPolicyKey];
        $requestHash = hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $idempotencyKey);
        $operation = 'documents.link';
        $existing = DB::table('document_idempotency_keys')->where('principal_id', $principal['user_id'])->where('operation', $operation)->where('idempotency_key_hash', $keyHash)->first();
        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash || ! is_string($existing->response_payload)) {
                return DocumentsApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
            }

            return response()->json(['data' => json_decode($existing->response_payload, true, 512, JSON_THROW_ON_ERROR)], 201)
                ->header('X-Correlation-ID', $correlationId)
                ->header('ETag', '"'.(int) $document->lock_version.'"');
        }

        try {
            $resource = $this->mutations->link(
                $document,
                $expectedVersion,
                $principal,
                [
                    'source_module' => $source['source_module'],
                    'record_type' => $source['record_type'],
                    'record_id' => $source['record_id'],
                ],
                $validated['relation_type'],
                $constraintPolicyKey,
                $requestHash,
                $keyHash,
                $operation,
                $correlationId,
            );
        } catch (DomainException $exception) {
            if ($exception->getMessage() === 'precondition_failed') {
                return DocumentsApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current version.', $correlationId);
            }

            return DocumentsApi::domainProblem($exception, $correlationId);
        } catch (QueryException) {
            return DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'The document link cannot be created.', $correlationId);
        }

        return response()->json(['data' => $resource], 201)
            ->header('X-Correlation-ID', $correlationId)
            ->header('ETag', '"'.($expectedVersion + 1).'"');
    }
}
