<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Identity\Features\Sessions\Contracts\ResolveSession;
use Modules\Identity\Http\IdentityApi;

final class IdentitySessionMiddleware
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

        $rawSessionToken = $request->cookie((string) config('identity.session.cookie', 'cluster_identity_session'));
        $session = $this->sessions->resolve(
            is_string($rawSessionToken) ? $rawSessionToken : '',
            IdentityRequestBinding::context($request),
        );
        if ($session === null) {
            return IdentityApi::problem(
                401,
                'authentication-required',
                'Unauthorized',
                'Authentication is required.',
                $correlationId,
            );
        }

        $request->attributes->set(IdentityRequestAttributes::SESSION, $session);
        $request->attributes->set(IdentityRequestAttributes::PRINCIPAL, [
            'user_id' => $session['user_id'],
        ]);
        $request->attributes->set('identity.raw_session_token', $rawSessionToken);

        return $next($request);
    }
}
