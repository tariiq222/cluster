<?php

declare(strict_types=1);

namespace Modules\Audit\Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Modules\Audit\Http\AuditApi;

/**
 * Test-local session/principal guard for the Audit export tests.
 *
 * Reuses the same X-Test-Audit-* header binding as
 * {@see \Modules\Audit\Tests\AuditHttpPrincipalResolver}. In test mode
 * the principal is fully resolved from headers so the test can pin the
 * facilityId / organizationUnitIds without bootstrapping a real session
 * table.
 */
final class AuditExportSessionMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->header('X-Test-Audit-Authenticated') !== '1') {
            return AuditApi::problem(
                401,
                'authentication-required',
                'Unauthorized',
                'Authentication is required.',
                AuditApi::correlationId($request),
            );
        }

        return $next($request);
    }
}
