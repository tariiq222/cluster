<?php

namespace Modules\Identity\Features\Sessions\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\Identity\Http\IdentityApi;

/**
 * GET /api/v1/me/scopes — lists the scopes the current principal already
 * holds; it never grants or expands access.
 */
final class ListMyScopesController
{
    use ResolvesScopeSelection;

    public function __construct(private readonly ResolvePrincipalContext $principalContexts) {}

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

        $selection = $this->scopeSelectionFor($context);
        if ($selection['effective_scope'] === null) {
            return IdentityApi::problem(403, 'scope-unavailable', 'Forbidden', 'No organizational scope is available for the current principal.', $correlationId);
        }

        $session = $request->attributes->get('identity.session');
        $version = 1;
        if (is_array($session) && is_string($session['session_id'] ?? null)) {
            $metadata = DB::table('identity_sessions')->where('id', $session['session_id'])->value('metadata');
            if (is_string($metadata) && $metadata !== '') {
                $decoded = json_decode($metadata, true);
                if (is_array($decoded) && is_int($decoded['scope_version'] ?? null)) {
                    $version = max(1, $decoded['scope_version']);
                }
            }
        }

        return response()->json($selection)
            ->header('X-Correlation-ID', $correlationId)
            ->header('ETag', '"'.$version.'"');
    }
}
