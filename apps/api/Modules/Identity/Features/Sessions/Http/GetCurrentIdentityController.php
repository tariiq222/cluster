<?php

declare(strict_types=1);

namespace Modules\Identity\Features\Sessions\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Features\UserAccount\Handler\UserAccountHandler;
use Modules\Identity\Http\IdentityApi;
use Shared\Contracts\RecordSensitiveAccessEvent;

final class GetCurrentIdentityController
{
    public function __construct(
        private readonly UserAccountHandler $accounts,
        private readonly RecordSensitiveAccessEvent $sensitiveAccess,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = IdentityApi::correlationId($request);
        if ($correlationId === null) {
            return IdentityApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }

        $principal = $request->attributes->get('identity.principal');
        $session = $request->attributes->get('identity.session');
        if (! is_array($principal) || ! is_array($session)
            || ! is_string($principal['user_id'] ?? null)
            || ! is_string($session['user_id'] ?? null)
            || $principal['user_id'] !== $session['user_id']) {
            return IdentityApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        $account = $this->accounts->find($principal['user_id']);
        if ($account === null) {
            return IdentityApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        $this->sensitiveAccess->record([
            'idempotency_key' => 'identity-current-read:'.$principal['user_id'].':'.$correlationId,
            'principal_id' => $principal['user_id'],
            'source_ip' => $request->ip(),
            'device_fingerprint_hash' => null,
            'correlation_id' => $correlationId,
            'classification_code' => 'confidential',
            'resource_type' => 'identity_current_projection',
            'resource_id' => $principal['user_id'],
            'action' => 'read',
            'access_decision_id' => null,
        ]);

        return response()->json(['data' => [
            'principal' => ['user_id' => $principal['user_id']],
            'account' => $account['account'],
            'session' => ['restricted' => (bool) ($session['restricted'] ?? false)],
        ]])->header('X-Correlation-ID', $correlationId);
    }
}
