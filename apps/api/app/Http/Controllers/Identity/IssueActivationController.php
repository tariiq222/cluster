<?php

namespace App\Http\Controllers\Identity;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Features\Activation\Contracts\IssueActivationToken;
use Modules\Identity\Http\IdentityApi;
use RuntimeException;
use UnexpectedValueException;

final class IssueActivationController
{
    private const OPERATION = 'identity.activation.issue';

    public function __construct(
        private readonly IssueActivationToken $activation,
        private readonly DecideAccess $access,
        private readonly IdentityIdempotency $idempotency,
    ) {}

    public function __invoke(Request $request, string $accountId): JsonResponse
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
        if (! IdentityApi::isUuidV7($accountId)) {
            return IdentityApi::problem(
                400,
                'invalid-account-id',
                'Bad Request',
                'accountId must be a lowercase UUIDv7.',
                $correlationId,
            );
        }
        $input = $request->json()->all();
        if ($input !== []) {
            return IdentityApi::problem(
                400,
                'invalid-activation-request',
                'Bad Request',
                'The activation request payload is invalid.',
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

        $principal = $this->principal($request);
        $session = $request->attributes->get('identity.session');
        if ($principal === null || ! is_array($session)) {
            return IdentityApi::problem(
                401,
                'authentication-required',
                'Unauthorized',
                'Authentication is required.',
                $correlationId,
            );
        }
        if (($session['restricted'] ?? false) === true || ! $this->access->decide(
            $principal,
            'identity.account.manage',
            new RecordFacts(ownerFacilityId: null, resourceType: 'identity_activation', classification: 'confidential'),
        )->isAllowed()) {
            return IdentityApi::problem(
                403,
                'access-denied',
                'Forbidden',
                'Access denied.',
                $correlationId,
            );
        }

        $scope = [
            'principal_id' => $principal['user_id'],
            'operation' => self::OPERATION.':'.$accountId,
            'key_hash' => hash('sha256', $idempotencyKey),
            'resource_type' => 'activation',
            'resource_id' => $accountId,
        ];
        $requestHash = hash('sha256', json_encode(['account_id' => $accountId], JSON_THROW_ON_ERROR));

        try {
            $result = DB::transaction(function () use ($scope, $requestHash, $accountId): array {
                $existing = $this->idempotency->find($scope, $requestHash);
                if ($existing !== null) {
                    if (! $existing['request_hash_matches']) {
                        return ['conflict' => true];
                    }
                    if ($existing['response'] === null) {
                        throw new UnexpectedValueException('The activation replay is incomplete.');
                    }

                    return ['response' => $existing['response']];
                }
                if (! $this->idempotency->claim($scope, $requestHash)) {
                    $concurrent = $this->idempotency->find($scope, $requestHash);
                    if ($concurrent === null || ! $concurrent['request_hash_matches']) {
                        return ['conflict' => true];
                    }
                    if ($concurrent['response'] === null) {
                        throw new UnexpectedValueException('The activation replay is incomplete.');
                    }

                    return ['response' => $concurrent['response']];
                }

                $issued = $this->activation->issue($accountId);
                $response = [
                    'account_id' => $accountId,
                    'status' => 'activation_issued',
                    'expires_at' => (string) $issued['expires_at'],
                    'delivery' => 'controlled',
                ];
                $this->idempotency->store($scope, $response);

                return ['response' => $response];
            });
        } catch (DomainException) {
            return IdentityApi::problem(
                409,
                'activation-unavailable',
                'Conflict',
                'The activation cannot be issued.',
                $correlationId,
            );
        } catch (QueryException|RuntimeException) {
            return IdentityApi::problem(
                500,
                'identity-write-failed',
                'Internal Server Error',
                'The activation request could not be safely completed.',
                $correlationId,
            );
        }

        if (($result['conflict'] ?? false) === true) {
            return IdentityApi::problem(
                409,
                'idempotency-conflict',
                'Conflict',
                'Idempotency-Key was already used for a different request.',
                $correlationId,
            );
        }

        return response()->json($result['response'], 202)->header('X-Correlation-ID', $correlationId);
    }

    /** @return array{user_id: string}|null */
    private function principal(Request $request): ?array
    {
        $principal = $request->attributes->get('identity.principal');

        return is_array($principal) && is_string($principal['user_id'] ?? null)
            ? ['user_id' => $principal['user_id']]
            : null;
    }
}
