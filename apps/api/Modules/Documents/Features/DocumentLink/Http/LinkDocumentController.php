<?php

namespace Modules\Documents\Features\DocumentLink\Http;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Application\DocumentLinkService;
use Modules\Documents\Application\DocumentSourceReference;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Documents\Http\DocumentsApi;

/** POST /api/v1/documents/{documentId}/links — links a document to a source. */
final class LinkDocumentController
{
    use \Modules\Documents\Features\DocumentAccess\Http\DocumentAccessSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
        private readonly DocumentLinkService $links,
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

        $now = now();
        try {
            $resource = DB::transaction(function () use ($document, $expectedVersion, $principal, $source, $validated, $constraintPolicyKey, $requestHash, $keyHash, $operation, $correlationId, $now): array {
                $locked = DB::table('documents')->where('id', $document->id)->lockForUpdate()->first();
                if ($locked === null || (int) $locked->lock_version !== $expectedVersion) {
                    throw new DomainException('precondition_failed');
                }
                $sourceModule = $source['source_module'] === 'work_records' ? 'work-records' : $source['source_module'];
                $linkId = $this->links->link(
                    $document->public_id,
                    new DocumentSourceReference($sourceModule, $source['record_type'], $source['record_id']),
                    $validated['relation_type'],
                    $principal['user_id'],
                    $principal['facility_id'],
                    $constraintPolicyKey,
                );
                DB::table('documents')->where('id', $document->id)->update(['lock_version' => $expectedVersion + 1, 'updated_at' => $now]);
                $resource = [
                    'id' => $linkId,
                    'resource_type' => 'document_link',
                    'document_id' => $document->public_id,
                    'status' => 'active',
                    'source' => ['source_module' => $source['source_module'], 'record_type' => $source['record_type'], 'record_id' => $source['record_id']],
                    'relation_type' => $validated['relation_type'],
                    'constraint_policy_key' => $constraintPolicyKey,
                    'lock_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                DB::table('document_idempotency_keys')->insert([
                    'id' => Str::uuid7()->toString(), 'principal_id' => $principal['user_id'], 'operation' => $operation,
                    'idempotency_key_hash' => $keyHash, 'request_hash' => $requestHash, 'resource_type' => 'document_link',
                    'resource_id' => $linkId, 'response_payload' => json_encode($resource, JSON_THROW_ON_ERROR),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('document_outbox_events')->insert([
                    'id' => Str::uuid7()->toString(), 'aggregate_id' => $document->id, 'event_type' => 'com.cluster.documents.linked.v1',
                    'payload' => json_encode(['document_id' => $document->public_id, 'link_id' => $linkId, 'relation_type' => $validated['relation_type'], 'constraint_policy_key' => $constraintPolicyKey, 'correlation_id' => $correlationId], JSON_THROW_ON_ERROR),
                    'occurred_at' => $now, 'published_at' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);

                return $resource;
            });
        } catch (DomainException $exception) {
            if ($exception->getMessage() === 'precondition_failed') {
                return DocumentsApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current version.', $correlationId);
            }

            return DocumentsApi::domainProblem($exception, $correlationId);
        } catch (QueryException) {
            return DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'The document link cannot be created.', $correlationId);
        }

        $this->recordAccessEvent($document, $principal['user_id'], 'link', 'allow', 'document_link_created');

        return response()->json(['data' => $resource], 201)
            ->header('X-Correlation-ID', $correlationId)
            ->header('ETag', '"'.($expectedVersion + 1).'"');
    }
}
