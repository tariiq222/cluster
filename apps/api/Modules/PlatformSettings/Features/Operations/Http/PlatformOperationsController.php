<?php

namespace Modules\PlatformSettings\Features\Operations\Http;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\PlatformSettings\Features\Operations\Handler\PlatformOperationsHandler;
use Throwable;

final class PlatformOperationsController
{
    public function __construct(private readonly PlatformSettingsApi $api, private readonly PlatformOperationsHandler $operations) {}

    public function health(Request $request): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_operations.health.read', $this->api->facts('platform_health_snapshot'));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        try {
            $snapshot = $this->operations->health();
            $body = ['status' => $snapshot->status === 'unhealthy' ? 'critical' : $snapshot->status, 'updated_at' => now()->utc()->format(DATE_ATOM), 'checks' => array_map(static fn ($check) => ['code' => $check->code, 'status' => $check->status, 'checked_at' => $check->checkedAt->format(DATE_ATOM), 'latency_ms' => $check->latencyMs, 'message_code' => $check->messageCode], $snapshot->checks), 'allowed_actions' => $context['decision']->allowedActions];

            return $this->api->response($body, 200, $context['correlation_id']);
        } catch (Throwable $exception) {
            return $this->api->exception($exception, $context['correlation_id']);
        }
    }

    public function backups(Request $request): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_operations.backup.read', $this->api->facts('platform_backup_report'));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        try {
            Log::info('platform_settings.sensitive_access', ['resource_type' => 'platform_backup_report', 'user_id' => $context['principal']['user_id'], 'correlation_id' => $context['correlation_id']]);
            $body = $this->operations->backupStatus();
            $body['allowed_actions'] = $context['decision']->allowedActions;

            return $this->api->response($body, 200, $context['correlation_id']);
        } catch (Throwable $exception) {
            return $this->api->exception($exception, $context['correlation_id']);
        }
    }

    public function requestRestore(Request $request): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_operations.restore.request', $this->api->facts('platform_restore_request'));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if ($this->api->idempotencyKey($request) === null) {
            return $this->api->problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $context['correlation_id']);
        }
        try {
            $body = $this->operations->requestRestore($context['principal']['user_id'], (string) $request->input('backup_id'), (string) $request->input('reason'), ['platform_operations.restore.request']);
            $body['allowed_actions'] = $context['decision']->allowedActions;

            return $this->api->response($body, 202, $context['correlation_id']);
        } catch (Throwable $exception) {
            return $this->api->exception($exception, $context['correlation_id']);
        }
    }

    public function confirmRestore(Request $request, string $requestId): JsonResponse
    {
        $operation = DB::table('platform_operation_requests')->where('id', $requestId)->first();
        if ($operation === null) {
            return $this->api->problem(404, 'resource-not-found', 'Not Found', 'Restore request was not found.', $this->api->correlationId($request));
        }
        $context = $this->api->authorize($request, 'platform_operations.restore.confirm', $this->api->facts('platform_restore_request', $requestId, null, (string) $operation->requested_by));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if ($this->api->idempotencyKey($request) === null) {
            return $this->api->problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $context['correlation_id']);
        }
        try {
            $body = $this->operations->confirmRestore($requestId, $context['principal']['user_id'], ['platform_operations.restore.confirm']);
            $body['allowed_actions'] = $context['decision']->allowedActions;

            return $this->api->response($body, 202, $context['correlation_id']);
        } catch (Throwable $exception) {
            return $this->api->exception($exception, $context['correlation_id']);
        }
    }
}
