<?php

namespace App\Http\Controllers\Identity;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Identity\Features\UserAccount\Handler\UserAccountHandler;
use Modules\Identity\Http\IdentityApi;

final class ListUserAccountsController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly UserAccountHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = IdentityApi::correlationId($request);
        if ($correlationId === null) {
            return IdentityApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return IdentityApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        if (! $this->access->decide($principal, 'identity.account.read', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'identity_account',
            classification: 'confidential',
        ))->isAllowed()) {
            return IdentityApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $query = $request->query();
        $validator = Validator::make($query, [
            'cursor' => ['sometimes', 'string', 'min:1', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($validator->fails() || array_diff(array_keys($query), ['cursor', 'limit']) !== []) {
            return IdentityApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 25);
        try {
            $page = $this->handler->list($principal, $validated['cursor'] ?? null, $limit);
        } catch (InvalidArgumentException) {
            return IdentityApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }

        $response = response()->json($page)->header('X-Correlation-ID', $correlationId);
        if ($page['next_cursor'] !== null) {
            $response->header('Link', '</api/v1/identity/accounts?'.http_build_query([
                'cursor' => $page['next_cursor'],
                'limit' => $limit,
            ], '', '&', PHP_QUERY_RFC3986).'>; rel="next"');
        }

        return $response;
    }
}
