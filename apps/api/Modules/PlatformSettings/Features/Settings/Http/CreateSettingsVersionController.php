<?php

namespace Modules\PlatformSettings\Features\Settings\Http;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\PlatformSettings\Features\Settings\Handler\PlatformSettingsHandler;
use Throwable;

final class CreateSettingsVersionController
{
    public function __construct(private readonly PlatformSettingsApi $api, private readonly PlatformSettingsHandler $settings) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_settings.manage', $this->api->facts('platform_settings_version'));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        if ($this->api->idempotencyKey($request) === null) {
            return $this->api->problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $context['correlation_id']);
        }
        try {
            $body = $this->settings->createDraft();
            $body['allowed_actions'] = $context['decision']->allowedActions;

            return $this->api->response($body, 201, $context['correlation_id'], (int) $body['lock_version']);
        } catch (Throwable $exception) {
            return $this->api->exception($exception, $context['correlation_id']);
        }
    }
}
