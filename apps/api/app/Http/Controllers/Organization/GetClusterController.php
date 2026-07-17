<?php

namespace App\Http\Controllers\Organization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\CreateCluster\Handler\CreateClusterHandler;
use Modules\Organization\Http\OrganizationApi;

final class GetClusterController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly CreateClusterHandler $handler,
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
        if (! $this->access->decide($principal, 'organization.cluster.read', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_cluster',
            classification: 'internal',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $cluster = $this->handler->find();
        if ($cluster === null) {
            return OrganizationApi::problem(404, 'cluster-not-found', 'Not Found', 'The cluster is not available.', $correlationId);
        }

        return OrganizationApi::data($cluster, 200, $correlationId, $cluster['lock_version']);
    }
}
