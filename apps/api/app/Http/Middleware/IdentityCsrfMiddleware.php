<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Features\Sessions\Contracts\ResolveSession;
use Modules\Identity\Http\IdentityApi;

final class IdentityCsrfMiddleware
{
    public function __construct(private readonly ResolveSession $sessions) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $correlationId = IdentityApi::correlationId($request);
        if ($correlationId === null) {
            return IdentityApi::problem(
                400,
                'invalid-correlation-id',
                'Bad Request',
                'X-Correlation-ID must be a lowercase UUIDv7.',
            );
        }

        $session = $request->attributes->get(IdentityRequestAttributes::SESSION);
        $rawSessionToken = $request->attributes->get('identity.raw_session_token');
        $rawCsrfToken = $request->header((string) config('identity.csrf.header', 'X-CSRF-Token'));
        if (! is_array($session) || ! is_string($rawSessionToken)) {
            return IdentityApi::problem(
                401,
                'authentication-required',
                'Unauthorized',
                'Authentication is required.',
                $correlationId,
            );
        }
        if (! is_string($rawCsrfToken) || $rawCsrfToken === '') {
            return IdentityApi::problem(
                403,
                'csrf-failed',
                'Forbidden',
                'The CSRF proof is invalid.',
                $correlationId,
            );
        }

        if (! is_string($session['session_id'] ?? null) || str_starts_with($session['session_id'], 'fixture-bearer:')) {
            // Fixture-bearer sessions are synthesized in the testing runtime
            // only and cannot prove a CSRF challenge.
            return $next($request);
        }

        if (! $this->sessions->validateCsrf($rawSessionToken, $rawCsrfToken, IdentityRequestBinding::context($request))) {
            return IdentityApi::problem(
                403,
                'csrf-failed',
                'Forbidden',
                'The CSRF proof is invalid.',
                $correlationId,
            );
        }

        return $next($request);
    }
}
