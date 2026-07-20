<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Prevent legacy development bearer credentials from crossing protected paths. */
final class RequireIdentitySessionPrincipal
{
    public function handle(Request $request, Closure $next): mixed
    {
        $request->attributes->set('identity.session_only', true);

        return $next($request);
    }
}
