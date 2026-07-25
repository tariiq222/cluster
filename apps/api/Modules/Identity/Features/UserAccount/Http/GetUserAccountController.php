<?php

namespace Modules\Identity\Features\UserAccount\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Contracts\AuthorizeIdentityManagement;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Identity\Features\UserAccount\Handler\UserAccountHandler;
use Modules\Identity\Http\IdentityApi;

final class GetUserAccountController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly AuthorizeIdentityManagement $authorization,
        private readonly UserAccountHandler $handler,
    ) {}

    public function __invoke(Request $request, string $accountId): JsonResponse
    {
        $correlationId = IdentityApi::correlationId($request);
        if ($correlationId === null) {
            return IdentityApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! IdentityApi::isUuidV7($accountId)) {
            return IdentityApi::problem(400, 'invalid-account-id', 'Bad Request', 'accountId must be a lowercase UUIDv7.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return IdentityApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        if (! $this->authorization->canReadAccounts($principal)) {
            return IdentityApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        $account = $this->handler->find($accountId);
        if ($account === null) {
            return IdentityApi::problem(404, 'account-not-found', 'Not Found', 'The account is not available.', $correlationId);
        }

        return IdentityApi::account($account['account'], 200, $correlationId, $account['lock_version']);
    }
}
