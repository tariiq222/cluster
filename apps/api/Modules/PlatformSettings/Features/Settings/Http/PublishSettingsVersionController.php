<?php

namespace Modules\PlatformSettings\Features\Settings\Http;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\PlatformSettings\Features\Settings\Handler\PlatformSettingsHandler;
use Throwable;

final class PublishSettingsVersionController
{
    public function __construct(private readonly PlatformSettingsApi $api, private readonly PlatformSettingsHandler $settings) {}

    public function __invoke(Request $request, string $versionId): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_settings.manage', $this->api->facts('platform_settings_version', $versionId));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $etag = $this->api->ifMatch($request);
        if ($etag === null) {
            return $this->api->problem(412, 'precondition-required', 'Precondition Failed', 'If-Match is required.', $context['correlation_id']);
        }
        try {
            $body = $this->settings->publish($versionId, $etag);
            $body['allowed_actions'] = $context['decision']->allowedActions;

            return $this->api->response($body, 200, $context['correlation_id'], (int) $body['lock_version']);
        } catch (Throwable $exception) {
            return $this->api->exception($exception, $context['correlation_id']);
        }
    }
}
