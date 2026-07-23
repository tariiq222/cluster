<?php

namespace Modules\PlatformSettings\Features\Logs\Http;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\PlatformSettings\Domain\TechnicalLogFilter;
use Modules\PlatformSettings\Features\Logs\Handler\TechnicalLogsHandler;
use Throwable;

final class TechnicalLogsController
{
    public function __construct(private readonly PlatformSettingsApi $api, private readonly TechnicalLogsHandler $logs) {}

    public function index(Request $request): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_operations.logs.read', $this->api->facts('technical_log'));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        try {
            Log::info('platform_settings.sensitive_access', ['resource_type' => 'technical_log', 'user_id' => $context['principal']['user_id'], 'correlation_id' => $context['correlation_id']]);
            $page = $this->logs->search(new TechnicalLogFilter($request->query('category'), $request->query('source'), $request->query('correlation_id'), $request->query('cursor'), (int) $request->query('per_page', 50)));
            $items = array_map(static fn ($entry) => ['id' => $entry->id, 'source' => $entry->source, 'category' => $entry->category, 'occurred_at' => $entry->occurredAt->format(DATE_ATOM), 'correlation_id' => $entry->correlationId, 'context' => $entry->context], $page->entries);

            return $this->api->response(['items' => $items, 'next_cursor' => $page->nextCursor, 'allowed_actions' => $context['decision']->allowedActions], 200, $context['correlation_id']);
        } catch (Throwable $exception) {
            return $this->api->exception($exception, $context['correlation_id']);
        }
    }

    public function restore(Request $request): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_operations.logs.restore', $this->api->facts('technical_log_archive'));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if ($this->api->idempotencyKey($request) === null) {
            return $this->api->problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $context['correlation_id']);
        }
        try {
            $operationId = $this->logs->requestRestore((string) $request->input('manifest_id'), $context['principal']['user_id'], (string) $request->input('reason'), ['platform_operations.logs.restore']);

            return $this->api->response(['operation_id' => $operationId, 'status' => 'requested', 'allowed_actions' => $context['decision']->allowedActions], 202, $context['correlation_id']);
        } catch (Throwable $exception) {
            return $this->api->exception($exception, $context['correlation_id']);
        }
    }
}
