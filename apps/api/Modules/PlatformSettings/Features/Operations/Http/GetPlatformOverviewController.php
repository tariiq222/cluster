<?php

namespace Modules\PlatformSettings\Features\Operations\Http;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\PlatformSettings\Features\Operations\Handler\PlatformOperationsHandler;
use Throwable;

final class GetPlatformOverviewController
{
    public function __construct(private readonly PlatformSettingsApi $api, private readonly PlatformOperationsHandler $operations) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_operations.health.read', $this->api->facts('platform_operations_overview'));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $issues = [];
        $metrics = [];
        $status = 'healthy';
        try {
            $health = $this->operations->health();
            $status = $health->status === 'unhealthy' ? 'critical' : $health->status;
            $metrics['health_checks'] = array_map(static fn ($check): array => ['code' => $check->code, 'status' => $check->status, 'latency_ms' => $check->latencyMs], $health->checks);
        } catch (Throwable) {
            $status = 'degraded';
            $issues[] = ['source' => 'health', 'code' => 'health_source_unavailable'];
        }
        try {
            $metrics['backup'] = $this->operations->backupStatus();
        } catch (Throwable) {
            if ($status !== 'critical') {
                $status = 'degraded';
            }
            $issues[] = ['source' => 'backups', 'code' => 'backup_source_unavailable'];
        }

        return $this->api->response([
            'status' => $status,
            'updated_at' => now()->utc()->format(DATE_ATOM),
            'issues' => $issues,
            'metrics' => $metrics,
            'allowed_actions' => $context['decision']->allowedActions,
        ], 200, $context['correlation_id']);
    }
}
