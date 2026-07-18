<?php

namespace App\Http\Controllers\Identity;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Identity\Features\UserAccount\Handler\UserAccountHandler;
use Modules\Identity\Http\IdentityApi;

final class GetUserAccountController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
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
        if (! $this->access->decide($principal, 'identity.account.read', new RecordFacts(
            ownerFacilityId: null,
            resourceType: 'identity_account',
            classification: 'confidential',
        ))->isAllowed()) {
            return IdentityApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        $account = $this->handler->find($accountId);
        if ($account === null) {
            return IdentityApi::problem(404, 'account-not-found', 'Not Found', 'The account is not available.', $correlationId);
        }

        return IdentityApi::account($account['account'], 200, $correlationId, $account['lock_version']);
    }
}
