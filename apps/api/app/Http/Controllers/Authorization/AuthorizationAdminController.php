<?php

namespace App\Http\Controllers\Authorization;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Http\AuthorizationApi;
use Modules\Authorization\Infrastructure\Persistence\AuthorizationHttpGateway;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Throwable;

final class AuthorizationAdminController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly AuthorizationHttpGateway $gateway,
    ) {}

    public function __invoke(Request $request, string $adminResource, ?string $resourceId = null, ?string $authorizationAction = null): JsonResponse
    {
        $correlationId = AuthorizationApi::correlationId($request);
        if ($correlationId === null) {
            return AuthorizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! in_array($adminResource, AuthorizationHttpGateway::resources(), true)) {
            return AuthorizationApi::problem(404, 'resource-not-found', 'Not Found', 'The authorization resource is not available.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return AuthorizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        $mutation = $request->isMethod('post') || $request->isMethod('patch');
        $capability = $mutation ? 'identity.account.manage' : 'identity.account.read';
        if (! $this->access->decide($principal, $capability, new RecordFacts(
            ownerFacilityId: null,
            resourceType: 'authorization_'.$adminResource,
            classification: 'internal',
        ))->isAllowed()) {
            return AuthorizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        try {
            if ($request->isMethod('get') && $resourceId === null) {
                return $this->list($request, $adminResource, $correlationId);
            }
            if ($request->isMethod('get') && $resourceId !== null) {
                return $this->show($adminResource, $resourceId, $correlationId);
            }
            if ($request->isMethod('post') && $resourceId === null) {
                return $this->create($request, $adminResource, $principal['user_id'], $correlationId);
            }
            if ($request->isMethod('patch') && $resourceId !== null && $authorizationAction === null) {
                return $this->patch($request, $adminResource, $resourceId, $correlationId);
            }
            if ($request->isMethod('post') && $resourceId !== null && $authorizationAction !== null) {
                return $this->transition($request, $adminResource, $resourceId, $authorizationAction, $correlationId, $principal['user_id']);
            }
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return AuthorizationApi::problem(409, 'authorization-conflict', 'Conflict', 'The authorization resource conflicts with existing state.', $correlationId);
            }

            return AuthorizationApi::problem(500, 'authorization-write-failed', 'Internal Server Error', 'The authorization change could not be saved.', $correlationId);
        } catch (\InvalidArgumentException $exception) {
            return match ($exception->getMessage()) {
                'authorization_precondition_failed' => AuthorizationApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current version.', $correlationId),
                'authorization_resource_not_found' => AuthorizationApi::problem(404, 'resource-not-found', 'Not Found', 'The authorization resource is not available.', $correlationId),
                default => AuthorizationApi::problem(422, 'invalid-authorization-resource', 'Unprocessable Entity', 'The authorization payload is invalid.', $correlationId),
            };
        } catch (Throwable) {
            return AuthorizationApi::problem(500, 'authorization-write-failed', 'Internal Server Error', 'The authorization change could not be saved.', $correlationId);
        }

        return AuthorizationApi::problem(404, 'resource-not-found', 'Not Found', 'The authorization resource is not available.', $correlationId);
    }

    private function list(Request $request, string $resource, string $correlationId): JsonResponse
    {
        $query = $request->query();
        $validator = Validator::make($query, [
            'cursor' => ['sometimes', 'string', 'min:1', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($validator->fails() || array_diff(array_keys($query), ['cursor', 'limit']) !== []) {
            return AuthorizationApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 25);
        $page = $this->gateway->list($resource, $validated['cursor'] ?? null, $limit);
        $link = $page['next_cursor'] === null ? null : '/api/v1/authorization/'.$resource.'?'.http_build_query([
            'cursor' => $page['next_cursor'],
            'limit' => $limit,
        ], '', '&', PHP_QUERY_RFC3986).'; rel="next"';

        return AuthorizationApi::collection($page, $correlationId, $link);
    }

    private function show(string $resource, string $resourceId, string $correlationId): JsonResponse
    {
        $entity = $this->gateway->find($resource, $resourceId);
        if ($entity === null) {
            return AuthorizationApi::problem(404, 'resource-not-found', 'Not Found', 'The authorization resource is not available.', $correlationId);
        }

        return AuthorizationApi::resource($entity, 200, $correlationId, $this->version($entity));
    }

    private function create(Request $request, string $resource, string $principalId, string $correlationId): JsonResponse
    {
        $key = AuthorizationApi::idempotencyKey($request);
        if ($key === null) {
            return AuthorizationApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $input = $request->json()->all();
        if (! $this->validCreatePayload($resource, $input)) {
            return AuthorizationApi::problem(422, 'invalid-authorization-resource', 'Unprocessable Entity', 'The authorization payload is invalid.', $correlationId);
        }
        $operation = 'create-'.$resource;
        $requestHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $existing = $this->idempotentResponse($principalId, $operation, $key, $requestHash, $correlationId);
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($resource, $input, $principalId, $correlationId, $key, $operation, $requestHash): JsonResponse {
            $entity = $this->gateway->create($resource, $input, $principalId);
            $payload = ['data' => $entity];
            DB::table('authorization_idempotency_keys')->insert([
                'principal_id' => $principalId,
                'operation' => $operation,
                'key_hash' => hash('sha256', $key),
                'request_hash' => $requestHash,
                'resource_id' => (string) $entity['id'],
                'response_status' => 201,
                'response_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return AuthorizationApi::resource($entity, 201, $correlationId, $this->version($entity));
        });
    }

    private function patch(Request $request, string $resource, string $resourceId, string $correlationId): JsonResponse
    {
        $version = AuthorizationApi::ifMatch($request);
        if ($version === null) {
            return AuthorizationApi::problem(400, 'invalid-if-match', 'Bad Request', 'If-Match must contain one current strong ETag.', $correlationId);
        }
        if (! AuthorizationApi::isMergePatch($request)) {
            return AuthorizationApi::problem(400, 'invalid-content-type', 'Bad Request', 'Content-Type must be application/merge-patch+json.', $correlationId);
        }
        $input = $request->json()->all();
        if ($input === []) {
            return AuthorizationApi::problem(422, 'invalid-authorization-patch', 'Unprocessable Entity', 'The authorization patch is invalid.', $correlationId);
        }
        $entity = $this->gateway->update($resource, $resourceId, $input, $version);
        if ($entity === null) {
            return AuthorizationApi::problem(404, 'resource-not-found', 'Not Found', 'The authorization resource is not available.', $correlationId);
        }

        return AuthorizationApi::resource($entity, 200, $correlationId, $this->version($entity));
    }

    private function transition(Request $request, string $resource, string $resourceId, string $action, string $correlationId, string $principalId): JsonResponse
    {
        $version = AuthorizationApi::ifMatch($request);
        if ($version === null) {
            return AuthorizationApi::problem(400, 'invalid-if-match', 'Bad Request', 'If-Match must contain one current strong ETag.', $correlationId);
        }
        $key = AuthorizationApi::idempotencyKey($request);
        if ($key === null) {
            return AuthorizationApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $current = $this->gateway->find($resource, $resourceId);
        if ($current === null) {
            return AuthorizationApi::problem(404, 'resource-not-found', 'Not Found', 'The authorization resource is not available.', $correlationId);
        }
        $operation = 'transition-'.$resource.'-'.$resourceId.'-'.$action;
        $input = $request->json()->all();
        $requestHash = hash('sha256', json_encode([...$input, 'if_match' => $version], JSON_THROW_ON_ERROR));
        $existing = $this->idempotentResponse($principalId, $operation, $key, $requestHash, $correlationId);
        if ($existing !== null) {
            return $existing;
        }
        $entity = $this->gateway->transition($resource, $resourceId, $action, $version);
        if ($entity === null) {
            return AuthorizationApi::problem(404, 'resource-not-found', 'Not Found', 'The authorization resource is not available.', $correlationId);
        }

        DB::table('authorization_idempotency_keys')->insert([
            'principal_id' => $principalId,
            'operation' => $operation,
            'key_hash' => hash('sha256', $key),
            'request_hash' => $requestHash,
            'resource_id' => $resourceId,
            'response_status' => 200,
            'response_payload' => json_encode(['data' => $entity], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return AuthorizationApi::resource($entity, 200, $correlationId, $this->version($entity));
    }

    /** @param array<string,mixed> $input */
    private function validCreatePayload(string $resource, array $input): bool
    {
        $allowed = [
            'resource_type', 'code', 'name', 'name_ar', 'name_en', 'role_type', 'is_system_role',
            'module_code', 'capability_code', 'action', 'sensitivity', 'subject_user_id', 'user_id',
            'role_id', 'scope_type', 'scope_id', 'start_at', 'end_at', 'delegator_user_id', 'delegate_user_id',
            'capability_codes', 'policy_document', 'field_policy_key', 'policy_version', 'classification_code',
            'minimum_capability', 'export_policy', 'download_policy', 'is_active',
        ];
        if (array_diff(array_keys($input), $allowed) !== []) {
            return false;
        }
        $expected = match ($resource) {
            'roles' => 'role',
            'capabilities' => 'capability',
            'role-assignments' => 'role_assignment',
            'delegations' => 'delegation',
            'classification-policies' => 'classification_policy',
            'field-access-templates' => 'field_access_template',
            default => null,
        };

        return ($input['resource_type'] ?? null) === $expected;
    }

    /** @param array<string,mixed> $entity */
    private function version(array $entity): ?int
    {
        return isset($entity['lock_version']) ? (int) $entity['lock_version'] : null;
    }

    private function idempotentResponse(string $principalId, string $operation, string $key, string $requestHash, string $correlationId): ?JsonResponse
    {
        $entry = DB::table('authorization_idempotency_keys')
            ->where('principal_id', $principalId)
            ->where('operation', $operation)
            ->where('key_hash', hash('sha256', $key))
            ->first();
        if ($entry === null) {
            return null;
        }
        if (! hash_equals((string) $entry->request_hash, $requestHash)) {
            return AuthorizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }
        $payload = json_decode((string) $entry->response_payload, true);
        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            return AuthorizationApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }

        return AuthorizationApi::resource($payload['data'], (int) $entry->response_status, $correlationId, $this->version($payload['data']));
    }
}
