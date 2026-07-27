<?php

declare(strict_types=1);

namespace Modules\Audit\Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Modules\Audit\Http\AuditApi;

/**
 * Test-local CSRF guard for the Audit export tests.
 *
 * In production this is {@see \App\Http\Middleware\IdentityCsrfMiddleware};
 * the test mode replaces the cryptographic CSRF token check with a
 * header presence check so the focused test does not have to bootstrap
 * the full Identity session machinery. The header contract — `X-CSRF-Token`
 * — is identical to production, so an absent header still produces a
 * canonical 403 problem.
 */
final class AuditExportCsrfMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! in_array($request->getMethod(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            return $next($request);
        }
        $csrf = $request->header('X-CSRF-Token');
        if (! is_string($csrf) || $csrf === '') {
            return AuditApi::problem(
                403,
                'csrf-failed',
                'Forbidden',
                'The CSRF token is missing or invalid.',
                AuditApi::correlationId($request),
            );
        }
        if ($csrf === 'wrong') {
            return AuditApi::problem(
                403,
                'csrf-failed',
                'Forbidden',
                'The CSRF token is missing or invalid.',
                AuditApi::correlationId($request),
            );
        }

        return $next($request);
    }
}
