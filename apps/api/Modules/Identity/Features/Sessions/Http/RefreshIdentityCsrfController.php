<?php

namespace Modules\Identity\Features\Sessions\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Exceptions\AuthenticationFailed;
use Modules\Identity\Features\Sessions\Handler\SessionHandler;
use Modules\Identity\Http\IdentityApi;

final class RefreshIdentityCsrfController
{
    public function __construct(private readonly SessionHandler $sessions) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = IdentityApi::correlationId($request);
        $session = $request->attributes->get('identity.session');
        if ($correlationId === null) {
            return IdentityApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! is_array($session) || ! is_string($session['session_id'] ?? null)) {
            return IdentityApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        try {
            $csrfToken = $this->sessions->rotateCsrf($session['session_id']);
        } catch (AuthenticationFailed) {
            return IdentityApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        return response()->json(['data' => ['csrf_token' => $csrfToken]])
            ->header('X-Correlation-ID', $correlationId)
            ->header('X-CSRF-Token', $csrfToken);
    }
}
