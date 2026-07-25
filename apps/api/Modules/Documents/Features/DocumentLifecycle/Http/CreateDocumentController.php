<?php

namespace Modules\Documents\Features\DocumentLifecycle\Http;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Documents\Http\DocumentsApi;

/**
 * POST /api/v1/documents — creates document metadata (no bytes) after a
 * central decision; idempotent on Idempotency-Key.
 */
final class CreateDocumentController
{
    use \Modules\Documents\Features\DocumentAccess\Http\DocumentAccessSupport;

    private const OPERATION = 'documents.create';

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principals,
        private readonly DecideAccess $access,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = DocumentsApi::correlationId($request);
        if ($correlationId === null) {
            return DocumentsApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = DocumentsApi::principalOrProblem($request, $this->principals, $correlationId);
        if ($principal instanceof JsonResponse) {
            return $principal;
        }

        $idempotencyKey = DocumentsApi::idempotencyKey($request);
        if ($idempotencyKey === null) {
            return DocumentsApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }

        $body = $request->json()->all();
        $title = $body['title'] ?? null;
        $classification = $body['classification'] ?? null;
        $ownerUnitId = $body['owner_organization_unit_id'] ?? null;
        $restrictionPolicyKey = $body['restriction_policy_key'] ?? null;
        $description = $body['description'] ?? null;
        if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 255
            || ! in_array($classification, ['public', 'internal', 'confidential', 'top_secret'], true)
            || ! is_string($ownerUnitId) || ! DocumentsApi::isUuidV7($ownerUnitId)
            || ! is_string($restrictionPolicyKey) || trim($restrictionPolicyKey) === '' || mb_strlen($restrictionPolicyKey) > 128
            || ($description !== null && (! is_string($description) || mb_strlen($description) > 2000))) {
            return DocumentsApi::problem(400, 'invalid-document', 'Bad Request', 'The document payload is invalid.', $correlationId);
        }

        $decision = $this->access->decide(
            [
                'user_id' => $principal['user_id'],
                'facility_id' => $principal['facility_id'],
                'organization_unit_ids' => array_filter([$principal['facility_id']]),
                'correlation_id' => $correlationId,
            ],
            'documents.create',
            new RecordFacts(
                ownerFacilityId: $ownerUnitId,
                resourceType: 'document',
                classification: $classification,
                organizationUnitId: $ownerUnitId,
            ),
        );
        if (! $decision->isAllowed()) {
            return DocumentsApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $requestHash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $idempotencyKey);
        $existing = DB::table('document_idempotency_keys')
            ->where('principal_id', $principal['user_id'])
            ->where('operation', self::OPERATION)
            ->where('idempotency_key_hash', $keyHash)
            ->first();
        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                return DocumentsApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
            }

            $document = DB::table('documents')->where('id', $existing->resource_id)->first();

            return $document === null
                ? DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'The document operation cannot be completed.', $correlationId)
                : $this->createdResponse($document, $correlationId);
        }

        $id = Str::uuid7()->toString();
        $publicId = Str::uuid7()->toString();
        $now = now();
        try {
            DB::transaction(function () use ($id, $publicId, $title, $description, $classification, $ownerUnitId, $restrictionPolicyKey, $principal, $now, $keyHash, $requestHash): void {
                DB::table('documents')->insert([
                    'id' => $id,
                    'public_id' => $publicId,
                    'owner_organization_unit_id' => $ownerUnitId,
                    'created_by_user_id' => $principal['user_id'],
                    'name' => trim($title),
                    'description' => $description,
                    'classification' => $classification,
                    'status' => 'active',
                    'current_version_id' => null,
                    'retention_until' => null,
                    'retention_policy_key' => trim($restrictionPolicyKey),
                    'legal_hold' => false,
                    'lock_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('document_idempotency_keys')->insert([
                    'id' => Str::uuid7()->toString(),
                    'principal_id' => $principal['user_id'],
                    'operation' => self::OPERATION,
                    'idempotency_key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                    'resource_type' => 'document',
                    'resource_id' => $id,
                    'response_payload' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (QueryException) {
            return DocumentsApi::problem(409, 'document-operation-conflict', 'Conflict', 'The document operation cannot be completed.', $correlationId);
        }

        return $this->createdResponse(DB::table('documents')->where('id', $id)->first(), $correlationId);
    }

    private function createdResponse(?\stdClass $document, string $correlationId): JsonResponse
    {
        return response()->json(['data' => $this->serializeDocument($document)], 201)
            ->header('X-Correlation-ID', $correlationId)
            ->header('ETag', '"1"');
    }
}
