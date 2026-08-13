<?php

namespace Modules\Organization\Features\OrganizationUnit\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\Authorization\OrganizationResourceFacts;
use Modules\Organization\Features\OrganizationUnit\Handler\OrganizationUnitHandler;
use Modules\Organization\Http\OrganizationApi;

final class ReorderOrganizationUnitsController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly OrganizationResourceFacts $resourceFacts,
        private readonly OrganizationUnitHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }

        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }

        $clusterId = $this->handler->reorderClusterId();
        $facts = $clusterId === null ? null : $this->resourceFacts->factsForCluster($clusterId);
        if ($facts === null || ! $this->access->decide($principal, 'organization.unit.manage', $facts)->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || preg_match('/\A[\x21-\x7E]{1,255}\z/', $key) !== 1) {
            return OrganizationApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $etag = $request->header('If-Match');
        if (! is_string($etag) || ! preg_match('/\A"?(\d+)"?\z/', $etag, $matches)) {
            return OrganizationApi::problem(412, 'precondition-required', 'Precondition Failed', 'If-Match is required.', $correlationId);
        }
        $input = $request->json()->all();
        $requestHash = hash('sha256', json_encode([
            'if_match' => (int) $matches[1],
            'body' => $input,
        ], JSON_THROW_ON_ERROR));
        $replay = $this->handler->findReorderReplay($principal['user_id'], $key, $requestHash);
        if ($replay !== null) {
            return $replay['request_hash_matches']
                ? OrganizationApi::data($replay, 200, $correlationId, (int) $replay['lock_version'])
                : OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }
        if ($input !== []) {
            return OrganizationApi::problem(400, 'invalid-reorder-request', 'Bad Request', 'The reorder request body must be empty.', $correlationId);
        }

        try {
            $result = $this->handler->reorderAll(
                fn (array $payload): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.organizationunitsreordered.v1',
                    '/organization/units',
                    $correlationId,
                    $clusterId,
                    'organization_unit_collection',
                    $payload,
                    $principal,
                ),
                (int) $matches[1],
                $principal['user_id'],
                $key,
                $requestHash,
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            return OrganizationApi::problem($exception->getStatusCode(), 'precondition-failed', 'Precondition Failed', $exception->getMessage(), $correlationId);
        }
        if (! $result['request_hash_matches']) {
            return OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }

        return OrganizationApi::data($result, 200, $correlationId, (int) $result['lock_version']);
    }
}
