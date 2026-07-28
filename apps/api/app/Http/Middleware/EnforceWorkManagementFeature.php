<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolvePrincipalContext;

/**
 * Central work-management feature gate (spec: docs/superpowers/specs/2026-07-28-cluster-task-only-workspace-design.md §4).
 *
 * While `features.work_management` is disabled:
 * - mutations are rejected with 409 urn:cluster:problem:feature-disabled;
 * - reads are rejected with the non-disclosing 404 unless the principal holds
 *   work_management.history.read;
 * - no disabled command reaches a handler, so no audit, outbox, notification,
 *   or persistence side effect can occur.
 *
 * Bind this middleware AFTER IdentitySessionMiddleware/RequireIdentitySessionPrincipal
 * (so unauthenticated callers still receive 401) and BEFORE any route-level
 * side-effect middleware, which never runs when the gate short-circuits.
 */
final class EnforceWorkManagementFeature
{
    private const HISTORY_CAPABILITY = 'work_management.history.read';

    public function __construct(
        private readonly ResolvePrincipalContext $principals,
        private readonly DecideAccess $access,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ((bool) config('features.work_management')) {
            return $next($request);
        }

        if (in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            if ($this->canReadHistory($request)) {
                return $next($request);
            }

            return response()->json([
                'type' => 'https://cluster.example/problems/resource-unavailable',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => 'لا يمكنك فتح هذا الطلب أو لم يعد متاحاً.',
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        return response()->json([
            'type' => 'urn:cluster:problem:feature-disabled',
            'title' => 'Conflict',
            'status' => 409,
            'detail' => 'إدارة العمل معطّلة حالياً؛ المهام هي مسار العمل الوحيد المتاح.',
        ], 409)->header('Content-Type', 'application/problem+json');
    }

    private function canReadHistory(Request $request): bool
    {
        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return false;
        }
        // Same correlation-id validation as EnforcePlatformMaintenance: a
        // malformed header must not flow into the decision pipeline.
        $correlationId = $request->header('X-Correlation-ID');
        if (! is_string($correlationId) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $correlationId) !== 1) {
            return false;
        }

        // evaluateOnly: read-side gate checks must not persist access_decisions
        // or sensitive_access_events rows (zero-side-effect requirement).
        return $this->access->evaluateOnly(
            $principal->toActorArray($correlationId),
            self::HISTORY_CAPABILITY,
            new RecordFacts(null, 'work_management', 'internal'),
        )->isAllowed();
    }
}
