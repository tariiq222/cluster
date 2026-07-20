<?php

namespace App\Http\Controllers\Identity;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Infrastructure\Persistence\ListActiveRoleSummariesForUser;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\Identity\Http\IdentityApi;

/**
 * GET /api/v1/me — the contracted AccessContext projection of the trusted
 * PrincipalContext. The browser never supplies roles, scopes or clearance.
 */
final class GetCurrentPrincipalController
{
    public function __construct(
        private readonly ResolvePrincipalContext $principalContexts,
        private readonly ListActiveRoleSummariesForUser $roleSummaries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = IdentityApi::correlationId($request);
        if ($correlationId === null) {
            return IdentityApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }

        $context = $this->principalContexts->resolve($request);
        if ($context === null) {
            return IdentityApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        $tenantId = $context->clusterIds[0] ?? null;
        if ($tenantId === null) {
            return IdentityApi::problem(403, 'scope-unavailable', 'Forbidden', 'No organizational scope is available for the current principal.', $correlationId);
        }

        $summary = $this->roleSummaries->forUser($context->userId);

        return response()->json([
            'subject_id' => $context->userId,
            'tenant_id' => $tenantId,
            'organization_unit_ids' => $context->organizationUnitIds,
            'roles' => array_map(static fn (array $role): string => $role['code'], $summary['roles']),
            'clearance' => $summary['clearance'],
            'break_glass' => false,
            'correlation_id' => $correlationId,
        ])->header('X-Correlation-ID', $correlationId);
    }
}
