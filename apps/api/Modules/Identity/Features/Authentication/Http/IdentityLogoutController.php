<?php

namespace Modules\Identity\Features\Authentication\Http;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Features\Sessions\Handler\SessionHandler;
use Modules\Identity\Http\IdentityApi;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

final class IdentityLogoutController
{
    public function __construct(
        private readonly SessionHandler $sessions,
        private readonly IdentityIdempotency $idempotency,
    ) {}

    public function __invoke(Request $request): Response
    {
        $correlationId = IdentityApi::correlationId($request);
        if ($correlationId === null) {
            return IdentityApi::problem(
                400,
                'invalid-correlation-id',
                'Bad Request',
                'X-Correlation-ID must be a lowercase UUIDv7.',
            );
        }

        $principal = $request->attributes->get('identity.principal');
        $session = $request->attributes->get('identity.session');
        if (! is_array($principal) || ! is_array($session) || ! is_string($principal['user_id'] ?? null)
            || ! is_string($session['session_id'] ?? null)) {
            return IdentityApi::problem(
                401,
                'authentication-required',
                'Unauthorized',
                'Authentication is required.',
                $correlationId,
            );
        }

        $idempotencyKey = IdentityApi::idempotencyKey($request);
        if ($idempotencyKey === null) {
            return IdentityApi::problem(
                400,
                'invalid-idempotency-key',
                'Bad Request',
                'Idempotency-Key is required.',
                $correlationId,
            );
        }

        $scope = [
            'principal_id' => $principal['user_id'],
            'operation' => 'identity.session.logout:'.$session['session_id'],
            'key_hash' => hash('sha256', $idempotencyKey),
            'resource_type' => 'session',
            'resource_id' => $session['session_id'],
        ];
        $requestHash = hash('sha256', json_encode(['session_id' => $session['session_id']], JSON_THROW_ON_ERROR));

        try {
            DB::transaction(function () use ($scope, $requestHash, $session): void {
                $existing = $this->idempotency->find($scope, $requestHash);
                if ($existing !== null) {
                    if (! $existing['request_hash_matches'] || $existing['response'] === null) {
                        throw new UnexpectedValueException('The logout replay is unavailable.');
                    }

                    return;
                }
                if (! $this->idempotency->claim($scope, $requestHash)) {
                    $concurrent = $this->idempotency->find($scope, $requestHash);
                    if ($concurrent === null || ! $concurrent['request_hash_matches'] || $concurrent['response'] === null) {
                        throw new UnexpectedValueException('The logout replay is unavailable.');
                    }

                    return;
                }

                $this->sessions->revoke((string) $session['session_id'], 'manual_logout');
                $this->idempotency->store($scope, ['status' => 'revoked']);
            });
        } catch (QueryException|UnexpectedValueException) {
            return IdentityApi::problem(
                500,
                'identity-logout-unavailable',
                'Internal Server Error',
                'The session could not be safely revoked.',
                $correlationId,
            );
        }

        return $this->noContent($correlationId);
    }

    private function noContent(string $correlationId): Response
    {
        return response()->noContent()->withHeaders([
            'X-Correlation-ID' => $correlationId,
        ])->withCookie(new Cookie(
            (string) config('identity.session.cookie', 'cluster_identity_session'),
            '',
            now()->subYear(),
            (string) config('identity.session.path', '/'),
            null,
            (bool) config('identity.session.secure', true),
            (bool) config('identity.session.http_only', true),
            false,
            (string) config('identity.session.same_site', 'lax'),
        ));
    }
}
