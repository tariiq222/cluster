<?php

namespace App\Http\Middleware;

use Closure;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Contracts\WorkerPrincipalResolver;
use Modules\Identity\Contracts\ResolvePrincipalContext;
use Modules\PlatformSettings\Domain\MaintenanceWindow;
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
        if ($this->isAllowedRequest($request) || $this->isTrustedInternalWorker($request)) {
            return $next($request);
        }

        $now = new DateTimeImmutable('now');
        $cachedWindow = Cache::remember(
            'platform:maintenance:active',
            60,
            fn (): array => $this->cacheableWindow($this->maintenance->activeAt($now)),
        );
        $window = $this->restoreWindow($cachedWindow);
        if ($window === null || ! $window->isActiveAt($now)) {
            return $next($request);
        }

        if ($this->canManageMaintenance($request)) {
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

    /** @return array{active: false}|array{active: true, id: string, starts_at: string, ends_at: ?string, message_ar: string, message_en: string, status: string} */
    private function cacheableWindow(?MaintenanceWindow $window): array
    {
        if ($window === null) {
            return ['active' => false];
        }

        return [
            'active' => true,
            'id' => $window->id,
            'starts_at' => $window->startsAt->format(DATE_ATOM),
            'ends_at' => $window->endsAt?->format(DATE_ATOM),
            'message_ar' => $window->messageAr,
            'message_en' => $window->messageEn,
            'status' => $window->status,
        ];
    }

    /** @param array{active: false}|array{active: true, id: string, starts_at: string, ends_at: ?string, message_ar: string, message_en: string, status: string} $cachedWindow */
    private function restoreWindow(array $cachedWindow): ?MaintenanceWindow
    {
        if (! $cachedWindow['active']) {
            return null;
        }

        return new MaintenanceWindow(
            id: $cachedWindow['id'],
            startsAt: new DateTimeImmutable($cachedWindow['starts_at']),
            endsAt: $cachedWindow['ends_at'] === null ? null : new DateTimeImmutable($cachedWindow['ends_at']),
            messageAr: $cachedWindow['message_ar'],
            messageEn: $cachedWindow['message_en'],
            status: $cachedWindow['status'],
        );
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
        // Validate the correlation id before forwarding it to
        // DecideAccess so a malformed header cannot trigger a DB
        // error when access_decisions.correlation_id (uuid type,
        // 36 chars) refuses to accept a longer string. The other
        // modules (e.g. MarkNotificationReadController) enforce
        // the same regex at their controller boundary.
        $correlationId = $request->header('X-Correlation-ID');
        if (! is_string($correlationId) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $correlationId) !== 1) {
            return false;
        }

        return $this->access->decide(
            $principal->toActorArray($correlationId),
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
