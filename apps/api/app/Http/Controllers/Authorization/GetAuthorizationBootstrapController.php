<?php

namespace App\Http\Controllers\Authorization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Http\AuthorizationApi;
use Modules\Authorization\Infrastructure\Persistence\AuthorizationBootstrapState;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

/**
 * GET /api/v1/authorization/bootstrap — exposes the bootstrap lifecycle state
 * to any authenticated principal. It reports state only; it grants nothing.
 */
final class GetAuthorizationBootstrapController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly AuthorizationBootstrapState $bootstrap,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = AuthorizationApi::correlationId($request);
        if ($correlationId === null) {
            return AuthorizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }

        if ($this->principalResolver->resolve($request) === null) {
            return AuthorizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        $state = $this->bootstrap->current();

        return response()->json(['data' => $state])
            ->header('X-Correlation-ID', $correlationId)
            ->header('ETag', '"'.(string) $state['version'].'"');
    }
}
