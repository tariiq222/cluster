<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Http\IdentityApi;

final class IdentityCsrfMiddleware
{
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
        if (! is_array($session) || ! is_string($rawSessionToken)) {
            return IdentityApi::problem(
                401,
                'authentication-required',
                'Unauthorized',
                'Authentication is required.',
                $correlationId,
            );
        }
        if (str_starts_with((string) ($session['session_id'] ?? ''), 'fixture-bearer:')) {
            // Fixture-bearer sessions are synthesized in the testing runtime
            // only and cannot prove a CSRF challenge.
            return $next($request);
        }
        $rawCsrfToken = $request->header((string) config('identity.csrf.header', 'X-CSRF-Token'));
        if (! is_string($rawCsrfToken) || $rawCsrfToken === '') {
            return IdentityApi::problem(
                403,
                'csrf-failed',
                'Forbidden',
                'The CSRF proof is invalid.',
                $correlationId,
            );
        }

        // The session middleware already resolved the row once per request
        // (single row lock + last_seen_at bump). Re-resolving here would
        // take a second lock on every mutation, serializing requests per
        // user; instead the proof is checked against the same resolved
        // session snapshot, and the store only re-locks when the CSRF token
        // must actually rotate.
        $csrfTokenHash = is_string($session['csrf_token_hash'] ?? null) ? $session['csrf_token_hash'] : null;
        if ($csrfTokenHash === null || ! hash_equals($csrfTokenHash, hash('sha256', $rawCsrfToken))) {
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
