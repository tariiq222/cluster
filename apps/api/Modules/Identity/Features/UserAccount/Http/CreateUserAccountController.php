<?php

namespace Modules\Identity\Features\UserAccount\Http;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Identity\Contracts\AuthorizeIdentityManagement;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Identity\Domain\UserAccount;
use Modules\Identity\Features\UserAccount\Handler\UserAccountHandler;
use Modules\Identity\Http\IdentityApi;
use UnexpectedValueException;

final class CreateUserAccountController
{
    private const OPERATION = 'createUserAccount';

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly AuthorizeIdentityManagement $authorization,
        private readonly UserAccountHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = IdentityApi::correlationId($request);
        if ($correlationId === null) {
            return IdentityApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
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
        $validator = Validator::make($input, [
            'person_id' => ['required', 'string'],
            'person_version' => ['required', 'integer', 'min:1'],
            'username' => ['required', 'string', 'min:1', 'max:128'],
        ]);
        if ($validator->fails()
            || array_diff(array_keys($input), ['person_id', 'person_version', 'username']) !== []
            || ! IdentityApi::isUuidV7((string) ($input['person_id'] ?? ''))) {
            return IdentityApi::problem(400, 'invalid-account', 'Bad Request', 'The account payload is invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $semantics = [
            'person_id' => $validated['person_id'],
            'person_version' => (int) $validated['person_version'],
            'username' => UserAccount::normalizeUsername($validated['username']),
        ];
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'operation' => self::OPERATION,
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];

        try {
            $replay = $this->handler->findReplay($idempotency);
            if ($replay !== null) {
                return $replay['request_hash_matches']
                    ? IdentityApi::account($replay['account'], 201, $correlationId, $replay['lock_version'])
                    : IdentityApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
            }
            $accountId = Str::uuid7()->toString();
            $result = $this->handler->create(
                $accountId,
                $semantics,
                $idempotency,
                fn (array $account): array => IdentityApi::cloudEvent(
                    'com.cluster.identity.useraccountcreated.v1',
                    '/identity/accounts/'.$account['id'],
                    $correlationId,
                    $principal,
                    [
                        'account_id' => $account['id'],
                        'person_id' => $account['person_id'],
                        'person_version' => $account['person_version'],
                        'status' => $account['status'],
                        'action' => 'create',
                        'lock_version' => 1,
                    ],
                ),
            );
        } catch (DomainException $exception) {
            return $this->domainProblem($exception->getMessage(), $correlationId);
        } catch (InvalidArgumentException) {
            return IdentityApi::problem(400, 'invalid-account', 'Bad Request', 'The account payload is invalid.', $correlationId);
        } catch (QueryException $exception) {
            $sqlState = $exception->errorInfo[0] ?? null;
            $driverCode = $exception->errorInfo[1] ?? null;
            if (in_array($sqlState, ['23000', '23505'], true)
                || in_array($driverCode, [19, '19', 1062, '1062'], true)) {
                return IdentityApi::problem(409, 'identity-account-conflict', 'Conflict', 'The account conflicts with existing Identity state.', $correlationId);
            }

            return IdentityApi::problem(500, 'identity-write-failed', 'Internal Server Error', 'The Identity account could not be saved.', $correlationId);
        } catch (UnexpectedValueException) {
            return IdentityApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }

        return IdentityApi::account($result['account'], 201, $correlationId, $result['lock_version']);
    }

    private function domainProblem(string $code, string $correlationId): JsonResponse
    {
        return match ($code) {
            'person_reference_unavailable' => IdentityApi::problem(409, 'person-reference-unavailable', 'Conflict', 'The Person reference is unavailable.', $correlationId),
            'person_reference_stale' => IdentityApi::problem(409, 'person-reference-stale', 'Conflict', 'The requested person_version is not current.', $correlationId),
            'person_reference_inactive' => IdentityApi::problem(409, 'person-reference-inactive', 'Conflict', 'The Person is not active.', $correlationId),
            'username_already_exists' => IdentityApi::problem(409, 'username-already-exists', 'Conflict', 'The username is already in use.', $correlationId),
            'person_account_already_exists' => IdentityApi::problem(409, 'person-account-already-exists', 'Conflict', 'The Person already has a live account.', $correlationId),
            default => IdentityApi::problem(409, 'identity-account-conflict', 'Conflict', 'The account cannot be created.', $correlationId),
        };
    }
}
