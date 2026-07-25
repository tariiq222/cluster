<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        $request->attributes->set(IdentityRequestAttributes::CORRELATION_ID, $correlationId);
        $rawSessionToken = $request->cookie((string) config('identity.session.cookie', 'cluster_identity_session'));
        $session = $this->sessions->resolve(
            is_string($rawSessionToken) ? $rawSessionToken : '',
            IdentityRequestBinding::context($request),
        );
        if ($session === null && app()->environment(['local', 'testing'])) {
            // Development-runtime fallback: synthesize an Identity session
            // attribute from a fixture bearer so the legacy fixture journeys
            // keep working in local/testing; production stays bound to the
            // validated session. Malformed or expired fixture states are
            // evicted exactly like the fixture principal resolver does.
            $bearer = $request->bearerToken();
            if (is_string($bearer) && preg_match('/\A[A-Za-z0-9]{64}\z/', $bearer) === 1) {
                $cacheKey = 'development-fixture-bearer:'.hash('sha256', $bearer);
                $store = Cache::store('file');
                $credential = $store->get($cacheKey);
                $valid = is_array($credential)
                    && is_int($credential['expires_at'] ?? null)
                    && $credential['expires_at'] > now()->getTimestamp()
                    && is_array($credential['principal'] ?? null)
                    && is_string($credential['principal']['user_id'] ?? null)
                    && is_string($credential['principal']['facility_id'] ?? null);
                if ($credential !== null && ! $valid) {
                    $store->forget($cacheKey);
                }
                if ($valid) {
                    $session = [
                        'user_id' => (string) $credential['principal']['user_id'],
                        'session_id' => 'fixture-bearer:'.hash('sha256', $bearer),
                        'restricted' => false,
                    ];
                    $request->attributes->set('identity.raw_session_token', $bearer);
                }
            }
        }
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
        if (! $request->attributes->has('identity.raw_session_token')) {
            $request->attributes->set('identity.raw_session_token', $rawSessionToken);
        }

        return $next($request);
    }
}
