<?php

namespace App\Http\Controllers\Authorization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Features\OperationsOffice\BootstrapOperationsOffice;
use Modules\Authorization\Http\AuthorizationApi;
use Modules\Authorization\Infrastructure\Persistence\AuthorizationBootstrapState;
use Modules\Identity\Contracts\ResolveAccountEntitlement;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;

/**
 * POST /api/v1/authorization/bootstrap/complete — ends the bootstrap window
 * once, recorded with a reason. Requires an active admin account; replay of
 * the same Idempotency-Key returns the stored result.
 */
final class CompleteAuthorizationBootstrapController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly ResolveAccountEntitlement $accountEntitlements,
        private readonly AuthorizationBootstrapState $bootstrap,
        private readonly BootstrapOperationsOffice $operationsOffice,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = AuthorizationApi::correlationId($request);
        if ($correlationId === null) {
            return AuthorizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }

        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return AuthorizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        $entitlement = $this->accountEntitlements->resolve($principal['user_id']);
        if ($entitlement === null) {
            return AuthorizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        if (! $entitlement['active'] || ! $entitlement['administrator']) {
            return AuthorizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $idempotencyKey = AuthorizationApi::idempotencyKey($request);
        if ($idempotencyKey === null) {
            return AuthorizationApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }

        $reason = $request->json('reason');
        if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 500) {
            return AuthorizationApi::problem(422, 'invalid-bootstrap-reason', 'Unprocessable Entity', 'A completion reason is required.', $correlationId);
        }

        $result = $this->bootstrap->complete(
            $principal['user_id'],
            trim($reason),
            $idempotencyKey,
            hash('sha256', json_encode(['reason' => trim($reason)], JSON_THROW_ON_ERROR)),
        );

        if ($result['status'] === 'conflict') {
            return AuthorizationApi::problem(409, 'authorization-conflict', 'Conflict', 'The authorization bootstrap is already complete or the key was reused.', $correlationId);
        }

        // Closing the bootstrap window is the moment the platform gains an owner:
        // the account that completed setup becomes the first operations-office
        // member. Seeding this from a migration instead would put catalog data in
        // the schema and leave the owner nameless.
        $clusterId = DB::table('clusters')->orderBy('code')->value('id');
        if (is_string($clusterId)) {
            $this->operationsOffice->bootstrap($principal['user_id'], $clusterId);
        }

        return response()->json(['data' => $result['payload']], 200)
            ->header('X-Correlation-ID', $correlationId)
            ->header('ETag', '"'.(string) $result['version'].'"');
    }
}
