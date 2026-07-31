<?php

declare(strict_types=1);

namespace Modules\Authorization\Features\Administration\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Features\Administration\Contracts\ListAssignmentScopeTargets;
use Modules\Authorization\Http\AuthorizationApi;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Contracts\GetDefaultClusterId;

/**
 * Task 1B — Dedicated controller for GET
 * /api/v1/authorization/assignment-scope-targets.
 *
 * Mounted on the dedicated route registered in apps/api/routes/web.php
 * immediately before the generic authorization/{adminResource} route.
 * Inherits the IdentitySessionMiddleware + RequireIdentitySessionPrincipal
 * route group; the principal resolver reads the cookie session, not a
 * bearer token.
 *
 * Authorisation: the actor must hold authorization.assignment.manage for
 * some non-empty scope; otherwise the catalog returns 403 (the actor is
 * forbidden from probing the catalog they cannot manage).
 */
final class ListAssignmentScopeTargetsController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly GetDefaultClusterId $defaultClusterId,
        private readonly ListAssignmentScopeTargets $port,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = AuthorizationApi::correlationId($request);
        if ($correlationId === null) {
            return AuthorizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }

        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return AuthorizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        $query = $request->query();
        $allowedKeys = ['scope_type', 'parent_scope_type', 'parent_scope_id', 'search', 'cursor', 'limit'];
        if (array_diff(array_keys($query), $allowedKeys) !== []) {
            return AuthorizationApi::problem(400, 'invalid-scope-targets-query', 'Bad Request', 'The assignment-scope-targets query is invalid.', $correlationId);
        }
        $scopeType = is_string($query['scope_type'] ?? null) ? (string) $query['scope_type'] : null;
        if ($scopeType === null) {
            return AuthorizationApi::problem(400, 'invalid-scope-targets-query', 'Bad Request', 'scope_type is required.', $correlationId);
        }

        $parentScopeType = is_string($query['parent_scope_type'] ?? null) ? (string) $query['parent_scope_type'] : null;
        $parentScopeId = is_string($query['parent_scope_id'] ?? null) ? (string) $query['parent_scope_id'] : null;
        $search = is_string($query['search'] ?? null) ? (string) $query['search'] : null;
        $cursor = is_string($query['cursor'] ?? null) ? (string) $query['cursor'] : null;
        $limit = is_numeric($query['limit'] ?? null) ? (int) $query['limit'] : 0;

        $clusterId = $this->defaultClusterId->resolve();
        $facts = new RecordFacts(
            ownerFacilityId: $this->principalFacilityId($principal),
            resourceType: 'authorization_assignment_scope_targets',
            classification: 'internal',
            clusterId: is_string($clusterId) ? $clusterId : null,
        );
        $decision = $this->access->decide(
            $principal,
            'authorization.assignment.manage',
            $facts,
        );
        if (! $decision->isAllowed()) {
            return AuthorizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        try {
            $page = $this->port->targets(
                (string) $principal['user_id'],
                $scopeType,
                $parentScopeType,
                $parentScopeId,
                $search,
                $cursor,
                $limit,
            );
        } catch (InvalidArgumentException $exception) {
            return match ($exception->getMessage()) {
                'authorization_scope_type_not_catalogued' => AuthorizationApi::problem(
                    422,
                    'urn:cluster:problem:scope_type_not_catalogued',
                    'Unprocessable Entity',
                    'The requested scope_type is not part of the catalog.',
                    $correlationId,
                ),
                'invalid_scope_query' => AuthorizationApi::problem(
                    400,
                    'invalid_scope_query',
                    'Bad Request',
                    'The scope_type/parent_scope combination is not supported.',
                    $correlationId,
                ),
                default => AuthorizationApi::problem(400, 'invalid-scope-targets-query', 'Bad Request', $exception->getMessage(), $correlationId),
            };
        }

        return AuthorizationApi::collection($page, $correlationId);
    }

    /** @param array<string, mixed> $principal */
    private function principalFacilityId(array $principal): ?string
    {
        $facilityIds = $principal['facility_ids'] ?? null;
        if (is_array($facilityIds) && is_string($facilityIds[0] ?? null)) {
            return $facilityIds[0];
        }

        return is_string($principal['facility_id'] ?? null) ? (string) $principal['facility_id'] : null;
    }
}
