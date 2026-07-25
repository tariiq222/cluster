<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Http\IdentityApi;

/** Require a coherent Identity session/principal binding on protected paths. */
final class RequireIdentitySessionPrincipal
{
    public function handle(Request $request, Closure $next): mixed
    {
        $session = $request->attributes->get('identity.session');
        $principal = $request->attributes->get('identity.principal');
        $sessionUserId = is_array($session) ? ($session['user_id'] ?? null) : null;
        $sessionId = is_array($session) ? ($session['session_id'] ?? null) : null;
        $principalUserId = is_array($principal) ? ($principal['user_id'] ?? null) : null;

        if (! is_string($sessionUserId) || $sessionUserId === ''
            || ! is_string($sessionId) || $sessionId === ''
            || ! is_string($principalUserId) || $principalUserId === ''
            || $principalUserId !== $sessionUserId) {
            return IdentityApi::problem(
                401,
                'authentication-required',
                'Unauthorized',
                'Authentication is required.',
            );
        }

        return $next($request);
    }
}
