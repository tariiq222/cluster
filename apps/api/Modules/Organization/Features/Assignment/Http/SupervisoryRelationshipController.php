<?php

namespace Modules\Organization\Features\Assignment\Http;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Organization\Contracts\AuthorizationIdempotencyKeyLookup;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Http\OrganizationApi;
use Modules\Organization\Infrastructure\Persistence\SupervisoryRelationshipHttpGateway;
use Throwable;

final class SupervisoryRelationshipController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly SupervisoryRelationshipHttpGateway $gateway,
        private readonly AuthorizationIdempotencyKeyLookup $idempotencyLookup,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        $mutation = $request->isMethod('post');
        $capability = $mutation ? 'organization.unit.manage' : 'organization.unit.read';
        if (! $this->access->decide($principal, $capability, new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'supervisory_relationship',
            classification: 'internal',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        if ($request->isMethod('get')) {
            return $this->list($request, $correlationId);
        }
        if ($request->isMethod('post')) {
            return $this->create($request, $principal['user_id'], $correlationId);
        }

        return OrganizationApi::problem(404, 'resource-not-found', 'Not Found', 'The supervisory relationship is not available.', $correlationId);
    }

    private function list(Request $request, string $correlationId): JsonResponse
    {
        $query = $request->query();
        $validator = Validator::make($query, [
            'cursor' => ['sometimes', 'string', 'min:1', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($validator->fails() || array_diff(array_keys($query), ['cursor', 'limit']) !== []) {
            return OrganizationApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 25);
        try {
            $page = $this->gateway->list($validated['cursor'] ?? null, $limit);
        } catch (\InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }
        $link = $page['next_cursor'] === null ? null : '/api/v1/organization/supervisory-relationships?'.http_build_query([
            'cursor' => $page['next_cursor'],
            'limit' => $limit,
        ], '', '&', PHP_QUERY_RFC3986).'; rel="next"';

        return OrganizationApi::collection($page, $correlationId, $link);
    }

    private function create(Request $request, string $principalId, string $correlationId): JsonResponse
    {
        $key = OrganizationApi::idempotencyKey($request);
        if ($key === null) {
            return OrganizationApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'source_unit_id' => ['required', 'string', 'uuid'],
            'target_unit_id' => ['required', 'string', 'uuid'],
            'relationship_type' => ['required', 'string', 'in:direct,functional,coordination,read_only'],
            'start_at' => ['required', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/'],
            'end_at' => ['required', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/'],
            'capability_codes' => ['required', 'array', 'min:1', 'max:100'],
            'capability_codes.*' => ['required', 'string', 'distinct:strict', 'max:64', 'regex:/\A[a-z][a-z0-9._-]*\z/'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['source_unit_id', 'target_unit_id', 'relationship_type', 'start_at', 'end_at', 'capability_codes']) !== []) {
            return OrganizationApi::problem(422, 'invalid-supervisory-relationship', 'Unprocessable Entity', 'The supervisory relationship payload is invalid.', $correlationId);
        }
        $input = $validator->validated();
        if ($input['source_unit_id'] === $input['target_unit_id']) {
            return OrganizationApi::problem(409, 'supervisory-relationship-conflict', 'Conflict', 'A supervisory relationship cannot target the same unit.', $correlationId);
        }
        if (DB::table('organization_units')->whereIn('id', [$input['source_unit_id'], $input['target_unit_id']])->count() !== 2) {
            return OrganizationApi::problem(404, 'organization-unit-not-found', 'Not Found', 'The organization unit is not available.', $correlationId);
        }
        $operation = 'create-supervisory-relationship';
        $requestHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $key);
        $existing = $this->idempotencyLookup->findExistingKey($principalId, $operation, $keyHash);
        if ($existing !== null) {
            if (! hash_equals($existing['request_hash'], $requestHash)) {
                return OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
            }
            $payload = json_decode($existing['response_payload'], true);

            return is_array($payload['data'] ?? null)
                ? OrganizationApi::resource($payload['data'], $existing['response_status'], $correlationId, 1)
                : OrganizationApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }

        try {
            return DB::transaction(function () use ($input, $principalId, $correlationId, $operation, $requestHash, $keyHash): JsonResponse {
                $entity = $this->gateway->create($input);
                $this->idempotencyLookup->recordKey([
                    'principal_id' => $principalId,
                    'operation' => $operation,
                    'key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                    'resource_id' => $entity['id'],
                    'response_status' => 201,
                    'response_payload' => json_encode(['data' => $entity], JSON_THROW_ON_ERROR),
                ]);

                return OrganizationApi::resource($entity, 201, $correlationId, 1);
            });
        } catch (QueryException $exception) {
            return (string) $exception->getCode() === '23000'
                ? OrganizationApi::problem(409, 'supervisory-relationship-conflict', 'Conflict', 'The supervisory relationship conflicts with existing state.', $correlationId)
                : OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        } catch (Throwable) {
            return OrganizationApi::problem(422, 'invalid-supervisory-relationship', 'Unprocessable Entity', 'The supervisory relationship payload is invalid.', $correlationId);
        }
    }
}