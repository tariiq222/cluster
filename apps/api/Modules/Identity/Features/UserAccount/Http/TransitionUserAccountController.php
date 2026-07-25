<?php

namespace Modules\Identity\Features\UserAccount\Http;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Identity\Contracts\AuthorizeIdentityManagement;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Identity\Features\UserAccount\Handler\UserAccountHandler;
use Modules\Identity\Http\IdentityApi;
use UnexpectedValueException;

final class TransitionUserAccountController
{
    private const ACTIONS = ['activate', 'unlock', 'disable', 'archive', 'revoke-sessions', 'force-password-change'];

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly AuthorizeIdentityManagement $authorization,
        private readonly UserAccountHandler $handler,
    ) {}

    public function __invoke(Request $request, string $accountId, string $accountAction): JsonResponse
    {
        $correlationId = IdentityApi::correlationId($request);
        if ($correlationId === null) {
            return IdentityApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! IdentityApi::isUuidV7($accountId)) {
            return IdentityApi::problem(400, 'invalid-account-id', 'Bad Request', 'accountId must be a lowercase UUIDv7.', $correlationId);
        }
        if (! in_array($accountAction, self::ACTIONS, true)) {
            return IdentityApi::problem(400, 'invalid-account-action', 'Bad Request', 'The account action is invalid.', $correlationId);
        }
        $expectedVersion = IdentityApi::ifMatch($request);
        if ($expectedVersion === null) {
            return IdentityApi::problem(400, 'invalid-if-match', 'Bad Request', 'If-Match must contain one current strong ETag.', $correlationId);
        }
        $idempotencyKey = IdentityApi::idempotencyKey($request);
        if ($idempotencyKey === null) {
            return IdentityApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return IdentityApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        if (! $this->authorization->canManageAccounts($principal)) {
            return IdentityApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, ['reason' => ['sometimes', 'string', 'min:1', 'max:500']]);
        if ($validator->fails() || array_diff(array_keys($input), ['reason']) !== []) {
            return IdentityApi::problem(400, 'invalid-account-action', 'Bad Request', 'The account action payload is invalid.', $correlationId);
        }
        $reason = $validator->validated()['reason'] ?? null;
        $semantics = [
            'account_id' => $accountId,
            'action' => $accountAction,
            'expected_version' => $expectedVersion,
            'reason' => $reason,
        ];
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'operation' => 'transitionUserAccount:'.$accountAction.':'.$accountId,
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];

        try {
            $replay = $this->handler->findReplay($idempotency);
            if ($replay !== null) {
                return $replay['request_hash_matches']
                    ? IdentityApi::account($replay['account'], 200, $correlationId, $replay['lock_version'])
                    : IdentityApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
            }
            $result = $this->handler->transition(
                $accountId,
                $accountAction,
                $expectedVersion,
                $reason,
                $idempotency,
                fn (array $account, string $action, ?string $_eventReason, int $version): array => IdentityApi::cloudEvent(
                    'com.cluster.identity.useraccountchanged.v1',
                    '/identity/accounts/'.$account['id'],
                    $correlationId,
                    $principal,
                    array_filter([
                        'account_id' => $account['id'],
                        'person_id' => $account['person_id'],
                        'person_version' => $account['person_version'],
                        'status' => $account['status'],
                        'action' => $action,
                        'lock_version' => $version,
                    ], static fn (mixed $value): bool => $value !== null),
                ),
            );
        } catch (DomainException $exception) {
            return $this->domainProblem($exception->getMessage(), $correlationId);
        } catch (InvalidArgumentException) {
            return IdentityApi::problem(400, 'invalid-account-action', 'Bad Request', 'The account action is invalid.', $correlationId);
        } catch (QueryException|UnexpectedValueException) {
            return IdentityApi::problem(500, 'identity-write-failed', 'Internal Server Error', 'The Identity change could not be saved.', $correlationId);
        }

        return IdentityApi::account($result['account'], 200, $correlationId, $result['lock_version']);
    }

    private function domainProblem(string $code, string $correlationId): JsonResponse
    {
        return match ($code) {
            'account_not_found' => IdentityApi::problem(404, 'account-not-found', 'Not Found', 'The account is not available.', $correlationId),
            'precondition_failed' => IdentityApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current account version.', $correlationId),
            'person_reference_unavailable' => IdentityApi::problem(409, 'person-reference-unavailable', 'Conflict', 'The Person reference is unavailable.', $correlationId),
            'person_reference_stale' => IdentityApi::problem(409, 'person-reference-stale', 'Conflict', 'The account person_version is no longer current.', $correlationId),
            'person_reference_inactive' => IdentityApi::problem(409, 'person-reference-inactive', 'Conflict', 'The Person is not active.', $correlationId),
            default => IdentityApi::problem(409, 'invalid-account-transition', 'Conflict', 'The account lifecycle transition is not allowed.', $correlationId),
        };
    }
}
