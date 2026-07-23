<?php

namespace Modules\PlatformSettings\Features\Settings\Http;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\PlatformSettings\Features\Settings\Handler\PlatformSettingsHandler;

final class GetCurrentPlatformSettingsController
{
    public function __construct(private readonly PlatformSettingsApi $api, private readonly PlatformSettingsHandler $settings) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_settings.read', $this->api->facts('platform_settings'));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $body = $this->settings->current();
        $body['allowed_actions'] = $context['decision']->allowedActions;

        return $this->api->response($body, 200, $context['correlation_id'], isset($body['lock_version']) ? (int) $body['lock_version'] : null);
    }
}
