<?php

namespace Modules\Authorization\Features\Administration\Http;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Authorization\Contracts\CapabilityCatalog;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Features\Administration\Application\AuthorizationAdminService;
use Modules\Authorization\Http\AuthorizationApi;
use Modules\Authorization\Infrastructure\BootstrapGatedDecideAccess;
use Modules\Authorization\Infrastructure\Persistence\AuthorizationHttpGateway;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Contracts\GetDefaultClusterId;
use Throwable;

final class AuthorizationAdminController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly AuthorizationHttpGateway $gateway,
        private readonly GetDefaultClusterId $defaultClusterId,
        private readonly AuthorizationAdminService $adminService,
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
        $capability = $mutation
            ? CapabilityCatalog::adminManage($adminResource)
            : CapabilityCatalog::adminRead($adminResource);
        if ($capability === null) {
            return AuthorizationApi::problem(404, 'resource-not-found', 'Not Found', 'The authorization resource is not available.', $correlationId);
        }
        $clusterId = $this->defaultClusterId->resolve();
        $facts = new RecordFacts(
            ownerFacilityId: $this->principalFacilityId($principal),
            resourceType: 'authorization_'.$adminResource,
            classification: 'internal',
            clusterId: is_string($clusterId) ? $clusterId : null,
        );
        $decision = $this->access instanceof RbacAbacDecideAccess || $this->access instanceof BootstrapGatedDecideAccess
            ? $this->access->evaluateOnly($principal, $capability, $facts)
            : $this->access->decide($principal, $capability, $facts);
        if (! $decision->isAllowed()) {
            return AuthorizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        $canManage = $mutation || (($manageCapability = CapabilityCatalog::adminManage($adminResource)) !== null
            && ($this->access instanceof RbacAbacDecideAccess || $this->access instanceof BootstrapGatedDecideAccess
                ? $this->access->evaluateOnly($principal, $manageCapability, $facts)
                : $this->access->decide($principal, $manageCapability, $facts)
            )->isAllowed());

        try {
            if ($request->isMethod('get') && $resourceId === null) {
                return $this->list($request, $adminResource, $correlationId, $principal['user_id']);
            }
            if ($request->isMethod('get') && $resourceId !== null) {
                return $this->show($adminResource, $resourceId, $correlationId, $principal['user_id'], $canManage);
            }
            if ($request->isMethod('post') && $resourceId === null) {
                return $this->create($request, $adminResource, $principal['user_id'], $correlationId);
            }
            if ($request->isMethod('patch') && $resourceId !== null && $authorizationAction === null) {
                return $this->patch($request, $adminResource, $resourceId, $correlationId, $principal['user_id']);
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
                'authorization_idempotency_conflict' => AuthorizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId),
                'authorization_system_role_immutable' => AuthorizationApi::problem(409, 'urn:cluster:problem:system-role-immutable', 'Conflict', 'System roles cannot be changed.', $correlationId),
                'authorization_scope_denied' => AuthorizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId),
                'authorization_resource_not_found' => AuthorizationApi::problem(404, 'resource-not-found', 'Not Found', 'The authorization resource is not available.', $correlationId),
                'authorization_scope_type_not_catalogued' => AuthorizationApi::problem(422, 'urn:cluster:problem:scope_type_not_catalogued', 'Unprocessable Entity', 'The requested scope_type is not part of the catalog.', $correlationId),
                'capability_code_not_in_catalog' => AuthorizationApi::problem(422, 'urn:cluster:problem:capability_code_not_in_catalog', 'Unprocessable Entity', 'The requested capability_code is not part of the catalog.', $correlationId),
                'invalid_scope_query' => AuthorizationApi::problem(400, 'invalid_scope_query', 'Bad Request', 'The scope_type/parent_scope combination is not supported.', $correlationId),
                'explicit_deny_subject_required' => AuthorizationApi::problem(422, 'urn:cluster:problem:explicit-deny-subject-required', 'Unprocessable Entity', 'An explicit deny must target a user or an organization unit.', $correlationId),
                'explicit_deny_classification_invalid' => AuthorizationApi::problem(422, 'urn:cluster:problem:explicit-deny-classification-invalid', 'Unprocessable Entity', 'The explicit deny classification is not part of the catalog.', $correlationId),
                'explicit_deny_resource_pattern_invalid' => AuthorizationApi::problem(422, 'urn:cluster:problem:explicit-deny-resource-pattern-invalid', 'Unprocessable Entity', 'The explicit deny resource pattern is invalid.', $correlationId),
                'explicit_deny_reason_required' => AuthorizationApi::problem(422, 'urn:cluster:problem:explicit-deny-reason-required', 'Unprocessable Entity', 'The explicit deny reason is required and must be 1-2000 characters.', $correlationId),
                'explicit_deny_window_invalid' => AuthorizationApi::problem(422, 'urn:cluster:problem:explicit-deny-window-invalid', 'Unprocessable Entity', 'The explicit deny expires_at must be after issued_at.', $correlationId),
                'explicit_deny_not_revocable' => AuthorizationApi::problem(422, 'urn:cluster:problem:explicit-deny-not-revocable', 'Unprocessable Entity', 'The explicit deny is not revocable.', $correlationId),
                default => AuthorizationApi::problem(422, 'invalid-authorization-resource', 'Unprocessable Entity', 'The authorization payload is invalid.', $correlationId),
            };
        } catch (Throwable) {
            return AuthorizationApi::problem(500, 'authorization-write-failed', 'Internal Server Error', 'The authorization change could not be saved.', $correlationId);
        }

        return AuthorizationApi::problem(404, 'resource-not-found', 'Not Found', 'The authorization resource is not available.', $correlationId);
    }

    /** @param array<string, mixed> $principal */
    private function principalFacilityId(array $principal): ?string
    {
        $facilityIds = $principal['facility_ids'] ?? null;
        if (is_array($facilityIds) && is_string($facilityIds[0] ?? null)) {
            return $facilityIds[0];
        }

        return is_string($principal['facility_id'] ?? null) ? $principal['facility_id'] : null;
    }

    private static function basePath(): string
    {
        return '/api/v1/authorization/';
    }

    private function list(Request $request, string $resource, string $correlationId, string $principalId): JsonResponse
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
        $page = $this->gateway->list($resource, $validated['cursor'] ?? null, $limit, $principalId);
        $link = $page['next_cursor'] === null ? null : '<'.self::basePath().$resource.'?'.http_build_query([
            'cursor' => $page['next_cursor'],
            'limit' => $limit,
        ], '', '&', PHP_QUERY_RFC3986).'>; rel="next"';

        return AuthorizationApi::collection($page, $correlationId, $link);
    }

    private function show(string $resource, string $resourceId, string $correlationId, string $principalId, bool $canManage): JsonResponse
    {
        $entity = $this->gateway->find($resource, $resourceId, $principalId);
        if ($entity === null) {
            return AuthorizationApi::problem(404, 'resource-not-found', 'Not Found', 'The authorization resource is not available.', $correlationId);
        }

        return AuthorizationApi::resource(
            $entity,
            200,
            $correlationId,
            $this->version($entity),
            $this->allowedActions($resource, $entity, $canManage),
        );
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
        $serviceOwned = in_array($resource, ['roles', 'role-assignments'], true);
        $operation = 'create-'.$resource;
        $requestHash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        if (! $serviceOwned) {
            $existing = $this->idempotentResponse($principalId, $operation, $key, $requestHash, $resource, $correlationId);
            if ($existing !== null) {
                return $existing;
            }
        }
        $result = $this->dispatchCreate($resource, $input, $principalId, $correlationId, $key);
        $entity = $result['entity'];
        if (! $serviceOwned) {
            $this->storeIdempotencyResponse($principalId, $operation, $key, $requestHash, (string) $entity['id'], 201, ['data' => $entity]);
        }

        return AuthorizationApi::resource(
            $entity,
            201,
            $correlationId,
            $this->version($entity),
            $this->allowedActions($resource, $entity),
        );
    }

    private function patch(Request $request, string $resource, string $resourceId, string $correlationId, string $principalId): JsonResponse
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

        $result = $this->dispatchUpdate($resource, $resourceId, $input, $version, $principalId, $correlationId);
        $entity = $result['entity'];
        if ($entity === []) {
            return AuthorizationApi::problem(404, 'resource-not-found', 'Not Found', 'The authorization resource is not available.', $correlationId);
        }

        return AuthorizationApi::resource(
            $entity,
            200,
            $correlationId,
            $this->version($entity),
            $this->allowedActions($resource, $entity),
        );
    }

    private function transition(Request $request, string $resource, string $resourceId, string $action, string $correlationId, string $principalId): JsonResponse
    {
        $version = AuthorizationApi::ifMatch($request);
        if ($version === null) {
            return AuthorizationApi::problem(400, 'invalid-if-match', 'Bad Request', 'If-Match must contain one current strong ETag.', $correlationId);
        }

        if ($resource === 'roles' && $action === 'clone') {
            $input = $request->json()->all();
            $key = AuthorizationApi::idempotencyKey($request);
            if ($key === null) {
                return AuthorizationApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
            }
            if (! $this->validClonePayload($input)) {
                return AuthorizationApi::problem(422, 'invalid-authorization-resource', 'Unprocessable Entity', 'The authorization payload is invalid.', $correlationId);
            }
            $result = $this->adminService->cloneRole($resourceId, $version, $input, $principalId, $correlationId, $key);
            $entity = $result['entity'];

            return AuthorizationApi::resource(
                $entity,
                200,
                $correlationId,
                $this->version($entity),
                $this->allowedActions($resource, $entity),
            );
        }

        $key = AuthorizationApi::idempotencyKey($request);
        if ($key === null) {
            return AuthorizationApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $serviceOwned = in_array($resource, ['roles', 'role-assignments', 'role-capabilities'], true);
        if (! $serviceOwned) {
            $current = $this->gateway->find($resource, $resourceId, $principalId);
            if ($current === null) {
                return AuthorizationApi::problem(404, 'resource-not-found', 'Not Found', 'The authorization resource is not available.', $correlationId);
            }
        }
        $operation = 'transition-'.$resource.'-'.$resourceId.'-'.$action;
        $input = $request->json()->all();
        $requestHash = hash('sha256', json_encode([...$input, 'if_match' => $version], JSON_THROW_ON_ERROR));
        if (! $serviceOwned) {
            $existing = $this->idempotentResponse($principalId, $operation, $key, $requestHash, $resource, $correlationId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $result = $this->dispatchTransition($resource, $resourceId, $action, $version, $principalId, $correlationId, $serviceOwned ? $key : null);
        $entity = $result['entity'];

        if (! $serviceOwned) {
            $this->storeIdempotencyResponse($principalId, $operation, $key, $requestHash, $resourceId, 200, ['data' => $entity]);
        }

        return AuthorizationApi::resource(
            $entity,
            200,
            $correlationId,
            $this->version($entity),
            $this->allowedActions($resource, $entity),
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{entity: array<string, mixed>, audit: array<string, mixed>}
     */
    private function dispatchCreate(string $resource, array $input, string $principalId, string $correlationId, ?string $idempotencyKey = null): array
    {
        if ($resource === 'roles') {
            return $this->adminService->createRole($input, $principalId, $correlationId, $idempotencyKey);
        }
        if ($resource === 'role-assignments') {
            return $this->adminService->createAssignment($input, $principalId, $correlationId, $idempotencyKey);
        }

        $entity = $this->gateway->create($resource, $input, $principalId);

        return ['entity' => $entity, 'audit' => []];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{entity: array<string, mixed>, audit: array<string, mixed>}
     */
    private function dispatchUpdate(string $resource, string $resourceId, array $input, int $version, string $principalId, string $correlationId): array
    {
        if ($resource === 'roles') {
            if ($input === ['status' => 'archived']) {
                return $this->adminService->archiveRole($resourceId, $version, $principalId, $correlationId);
            }

            return $this->adminService->editRole($resourceId, $input, $version, $principalId, $correlationId);
        }
        if ($resource === 'role-assignments') {
            return $this->adminService->updateAssignment($resourceId, $input, $version, $principalId, $correlationId);
        }

        $entity = $this->gateway->update($resource, $resourceId, $input, $version, $principalId);

        return ['entity' => $entity ?? [], 'audit' => []];
    }

    /**
     * @return array{entity: array<string, mixed>, audit: array<string, mixed>}
     */
    private function dispatchTransition(string $resource, string $resourceId, string $action, int $version, string $principalId, string $correlationId, ?string $idempotencyKey = null): array
    {
        if ($resource === 'role-assignments') {
            if ($action === 'revoke') {
                return $this->adminService->revokeAssignment($resourceId, $version, $principalId, $correlationId, $idempotencyKey);
            }
            if ($action === 'expire') {
                return $this->adminService->expireAssignment($resourceId, $version, $principalId, $correlationId, $idempotencyKey);
            }
        }
        if ($resource === 'role-capabilities' && $action === 'revoke') {
            return $this->adminService->revokeRoleCapability($resourceId, $version, $principalId, $correlationId, $idempotencyKey);
        }

        $entity = $this->gateway->transition($resource, $resourceId, $action, $version, $principalId);

        return ['entity' => $entity ?? [], 'audit' => []];
    }

    /**
     * Authoritative allowlist for POST /api/v1/authorization/roles/{id}/clone.
     *
     * Mirrors `RoleCloneInput` in docs/contracts/api/openapi.yaml: code,
     * name_ar, name_en, description_ar, description_en — all optional,
     * and every key that is present must be a string. Undocumented `name`
     * is rejected (HTTP 422) so callers cannot smuggle the legacy create
     * payload into the clone endpoint.
     *
     * @param  array<string, mixed>  $input
     */
    private function validClonePayload(array $input): bool
    {
        $allowed = ['code', 'name_ar', 'name_en', 'description_ar', 'description_en'];
        if (array_diff(array_keys($input), $allowed) !== []) {
            return false;
        }
        foreach ($allowed as $key) {
            if (array_key_exists($key, $input) && ! is_string($input[$key])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $input */
    private function validCreatePayload(string $resource, array $input): bool
    {
        $allowed = [
            'resource_type', 'code', 'name', 'name_ar', 'name_en', 'role_type', 'is_system_role',
            'module_code', 'capability_code', 'capability_id', 'action', 'sensitivity', 'subject_user_id', 'user_id',
            'role_id', 'scope_type', 'scope_id', 'start_at', 'end_at', 'delegator_user_id', 'delegate_user_id',
            'capability_codes', 'policy_document', 'field_policy_key', 'policy_version', 'classification_code',
            'minimum_capability', 'export_policy', 'download_policy', 'is_active',
            'effect', 'reason', 'classification', 'organization_unit_id', 'resource_pattern', 'issued_at', 'expires_at', 'revocable',
        ];
        if (array_diff(array_keys($input), $allowed) !== []) {
            return false;
        }
        $expected = match ($resource) {
            'roles' => 'role',
            'capabilities' => 'capability',
            'role-capabilities' => 'role_capability',
            'role-assignments' => 'role_assignment',
            'delegations' => 'delegation',
            'explicit-denies' => 'explicit_deny',
            'classification-policies' => 'classification_policy',
            'field-access-templates' => 'field_access_template',
            default => null,
        };
        if (($input['resource_type'] ?? null) !== $expected) {
            return false;
        }
        if ($resource === 'delegations' && array_key_exists('capability_codes', $input) && ! is_array($input['capability_codes'])) {
            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $entity */
    private function version(array $entity): ?int
    {
        return isset($entity['lock_version']) ? (int) $entity['lock_version'] : null;
    }

    /**
     * Per-resource allowed_actions matrix. Returned with every response so
     * the web UI can render the right action buttons without a second
     * round-trip. The matrix is intentionally scoped to the resources
     * Task 4 owns: role, role_assignment, role_capability.
     *
     * @param  array<string, mixed>  $entity
     * @return list<string>|null
     */
    private function allowedActions(string $resource, array $entity, bool $canManage = true): ?array
    {
        if (! $canManage) {
            return [];
        }

        return match ($resource) {
            'roles' => $this->roleAllowedActions($entity),
            'role-assignments' => $this->roleAssignmentAllowedActions($entity),
            'role-capabilities' => $this->roleCapabilityAllowedActions($entity),
            default => null,
        };
    }

    /** @param array<string, mixed> $entity */
    private function roleAllowedActions(array $entity): array
    {
        $isSystem = (bool) ($entity['is_system_role'] ?? false);
        if ($isSystem) {
            return ['clone'];
        }
        $actions = ['edit', 'clone'];
        if (($entity['status'] ?? null) === 'active') {
            $actions[] = 'archive';
        }

        return $actions;
    }

    /** @param array<string, mixed> $entity */
    private function roleAssignmentAllowedActions(array $entity): array
    {
        $status = (string) ($entity['status'] ?? 'active');
        if (in_array($status, ['revoked', 'expired'], true)) {
            return [];
        }

        return ['edit', 'revoke', 'expire'];
    }

    /** @param array<string, mixed> $entity */
    private function roleCapabilityAllowedActions(array $entity): array
    {
        $status = (string) ($entity['status'] ?? 'allowed');
        if (in_array($status, ['revoked', 'expired'], true)) {
            return [];
        }

        return ['edit', 'revoke'];
    }

    /** @param array<string, mixed> $payload */
    private function storeIdempotencyResponse(string $principalId, string $operation, string $key, string $requestHash, string $resourceId, int $status, array $payload): void
    {
        DB::table('authorization_idempotency_keys')->insert([
            'principal_id' => $principalId,
            'operation' => $operation,
            'key_hash' => hash('sha256', $key),
            'request_hash' => $requestHash,
            'resource_id' => mb_strlen($resourceId) > 64 ? md5($resourceId) : $resourceId,
            'response_status' => $status,
            'response_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function idempotentResponse(string $principalId, string $operation, string $key, string $requestHash, string $resource, string $correlationId): ?JsonResponse
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

        return AuthorizationApi::resource(
            $payload['data'],
            (int) $entry->response_status,
            $correlationId,
            $this->version($payload['data']),
            $this->allowedActions($resource, $payload['data']),
        );
    }
}
