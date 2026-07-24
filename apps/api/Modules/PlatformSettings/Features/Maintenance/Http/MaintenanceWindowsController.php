<?php

namespace Modules\PlatformSettings\Features\Maintenance\Http;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\PlatformSettings\Features\Maintenance\Handler\MaintenanceWindowHandler;
use Throwable;

final class MaintenanceWindowsController
{
    public function __construct(private readonly PlatformSettingsApi $api, private readonly MaintenanceWindowHandler $maintenance) {}

    public function index(Request $request): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_operations.maintenance.manage', $this->api->facts('platform_maintenance_window'));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $items = DB::table('platform_maintenance_windows')->orderByDesc('starts_at')->get()->map(fn (object $row) => $this->present($row, $context['decision']->allowedActions))->all();

        return $this->api->response(['items' => $items], 200, $context['correlation_id']);
    }

    public function store(Request $request): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_operations.maintenance.manage', $this->api->facts('platform_maintenance_window'));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if ($this->api->idempotencyKey($request) === null) {
            return $this->api->problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $context['correlation_id']);
        }
        try {
            $window = $this->maintenance->schedule($context['principal']['user_id'], new DateTimeImmutable((string) $request->input('starts_at')), $request->filled('ends_at') ? new DateTimeImmutable((string) $request->input('ends_at')) : null, (string) $request->input('message_ar'), (string) $request->input('message_en'));
            $row = DB::table('platform_maintenance_windows')->where('id', $window->id)->first();

            return $this->api->response($this->present($row, $context['decision']->allowedActions), 201, $context['correlation_id'], 1);
        } catch (Throwable $exception) {
            return $this->api->exception($exception, $context['correlation_id']);
        }
    }

    public function cancel(Request $request, string $windowId): JsonResponse
    {
        $row = DB::table('platform_maintenance_windows')->where('id', $windowId)->first();
        if ($row === null) {
            return $this->api->problem(404, 'resource-not-found', 'Not Found', 'Maintenance window was not found.', $this->api->correlationId($request));
        }
        $context = $this->api->authorize($request, 'platform_operations.maintenance.cancel', $this->api->facts('platform_maintenance_window', $windowId, null, (string) $row->created_by));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $etag = $this->api->ifMatch($request);
        if ($etag === null) {
            return $this->api->problem(412, 'precondition-required', 'Precondition Failed', 'If-Match is required.', $context['correlation_id']);
        }
        $updated = DB::table('platform_maintenance_windows')->where('id', $windowId)->where('lock_version', $etag)->whereIn('status', ['scheduled', 'active'])->update(['status' => 'cancelled', 'lock_version' => $etag + 1, 'updated_at' => now()]);
        if ($updated !== 1) {
            return $this->api->problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current maintenance window.', $context['correlation_id']);
        }
        $next = DB::table('platform_maintenance_windows')->where('id', $windowId)->first();

        return $this->api->response($this->present($next, $context['decision']->allowedActions), 200, $context['correlation_id'], (int) $next->lock_version);
    }

    private function present(object $window, array $allowedActions): array
    {
        $messages = json_decode((string) $window->reason, true) ?: [];

        return ['id' => $window->id, 'status' => $window->status, 'starts_at' => $window->starts_at, 'ends_at' => $window->ends_at, 'message_ar' => $messages['ar'] ?? '', 'message_en' => $messages['en'] ?? '', 'lock_version' => (int) $window->lock_version, 'allowed_actions' => $allowedActions];
    }
}
