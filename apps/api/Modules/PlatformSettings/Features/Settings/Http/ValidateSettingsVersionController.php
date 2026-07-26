<?php

namespace Modules\PlatformSettings\Features\Settings\Http;

use App\Integrations\PlatformSettings\PlatformSettingsApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\PlatformSettings\Features\Settings\Handler\PlatformSettingsHandler;
use Modules\PlatformSettings\Infrastructure\Persistence\PlatformSettingsIdempotency;
use Throwable;

final class ValidateSettingsVersionController
{
    public function __construct(private readonly PlatformSettingsApi $api, private readonly PlatformSettingsHandler $settings) {}

    public function __invoke(Request $request, string $versionId): JsonResponse
    {
        $context = $this->api->authorize($request, 'platform_settings.manage', $this->api->facts('platform_settings_version', $versionId));
        if ($context instanceof JsonResponse) {
            return $context;
        }
        $key = $this->api->idempotencyKey($request);
        if ($key === null) {
            return $this->api->problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $context['correlation_id']);
        }
        $etag = $this->api->ifMatch($request);
        if ($etag === null) {
            return $this->api->problem(412, 'precondition-required', 'Precondition Failed', 'If-Match is required.', $context['correlation_id']);
        }
        try {
            $result = PlatformSettingsIdempotency::run(
                $context['principal']['user_id'],
                'platform_settings.validate',
                $key,
                hash('sha256', json_encode(['version_id' => $versionId, 'if_match' => $etag], JSON_THROW_ON_ERROR)),
                fn (): array => $this->settings->validate($versionId, $etag),
            );
            if (! $result['request_hash_matches']) {
                return $this->api->problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $context['correlation_id']);
            }
            $body = $result['payload'];
            $body['allowed_actions'] = $context['decision']->allowedActions;

            return $this->api->response($body, 200, $context['correlation_id'], (int) $body['lock_version']);
        } catch (Throwable $exception) {
            return $this->api->exception($exception, $context['correlation_id']);
        }
    }
}
