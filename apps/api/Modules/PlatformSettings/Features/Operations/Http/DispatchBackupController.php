<?php

namespace Modules\PlatformSettings\Features\Operations\Http;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\PlatformSettings\Features\Operations\Handler\PlatformOperationsHandler;
use Throwable;

final class DispatchBackupController
{
    public function __construct(private readonly PlatformSettingsApi $api, private readonly PlatformOperationsHandler $operations) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_operations.backup.run', $this->api->facts('platform_backup_operation'));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $key = $this->api->idempotencyKey($request);
        if ($key === null) {
            return $this->api->problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $context['correlation_id']);
        }
        try {
            $body = $this->operations->requestBackup($context['principal']['user_id'], $key);
            $body['allowed_actions'] = $context['decision']->allowedActions;

            return $this->api->response($body, 202, $context['correlation_id']);
        } catch (Throwable $exception) {
            return $this->api->exception($exception, $context['correlation_id']);
        }
    }
}
