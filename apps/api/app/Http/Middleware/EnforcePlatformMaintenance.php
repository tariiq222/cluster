<?php

namespace App\Http\Middleware;

use Closure;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Contracts\WorkerPrincipalResolver;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\PlatformSettings\Features\Maintenance\Handler\MaintenanceWindowHandler;

final class EnforcePlatformMaintenance
{
    public function __construct(
        private readonly MaintenanceWindowHandler $maintenance,
        private readonly ResolvePrincipalContext $principals,
        private readonly DecideAccess $access,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->isAllowedRequest($request) || $this->isTrustedInternalWorker($request) || $this->canManageMaintenance($request)) {
            return $next($request);
        }

        $now = new DateTimeImmutable('now');
        $window = $this->maintenance->activeAt($now);
        if ($window === null || ! $window->isActiveAt($now)) {
            return $next($request);
        }

        $retryAfter = $window->endsAt === null
            ? 3600
            : max(1, $window->endsAt->getTimestamp() - $now->getTimestamp());

        return response()->json([
            'type' => 'https://cluster.example/problems/platform-maintenance',
            'type_key' => 'platform-maintenance',
            'title' => 'Service Unavailable',
            'status' => 503,
            'detail' => $window->messageFor($request->getPreferredLanguage(['ar', 'en']) ?? 'ar'),
        ], 503)->withHeaders([
            'Content-Type' => 'application/problem+json',
            'Retry-After' => (string) $retryAfter,
        ]);
    }

    private function isAllowedRequest(Request $request): bool
    {
        return in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
            || in_array(trim($request->path(), '/'), ['up', 'api/v1/auth/login', 'api/v1/identity/login'], true);
    }

    private function canManageMaintenance(Request $request): bool
    {
        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return false;
        }

        return $this->access->decide(
            $principal->toActorArray($request->header('X-Correlation-ID')),
            'platform_operations.maintenance.manage',
            new RecordFacts(null, 'platform_maintenance', 'internal', 'platform-maintenance-v1'),
        )->isAllowed();
    }

    private function isTrustedInternalWorker(Request $request): bool
    {
        if ($request->getMethod() !== 'POST'
            || preg_match('#\Aapi/v1/internal/documents/versions/[0-9a-f-]+/(?:scan|reconcile-promotion)\z#', trim($request->path(), '/')) !== 1) {
            return false;
        }

        /** @var WorkerPrincipalResolver $resolver */
        $resolver = app(WorkerPrincipalResolver::class);

        return $resolver->resolve($request) !== null;
    }
}
