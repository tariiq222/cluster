<?php

namespace Modules\Organization\Features\UpdateFacility\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\Authorization\OrganizationResourceFacts;
use Modules\Organization\Features\UpdateFacility\Handler\UpdateFacilityHandler;
use Modules\Organization\Http\OrganizationApi;

final class GetFacilityController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly OrganizationResourceFacts $resourceFacts,
        private readonly UpdateFacilityHandler $handler,
    ) {}

    public function __invoke(Request $request, string $facilityId): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! OrganizationApi::isUuidV7($facilityId)) {
            return OrganizationApi::problem(400, 'invalid-facility-id', 'Bad Request', 'facilityId must be a lowercase UUIDv7.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        $facts = $this->resourceFacts->factsForFacility($facilityId);
        if ($facts === null || ! $this->access->decide($principal, 'organization.facility.read', $facts)->isAllowed()) {
            return OrganizationApi::problem(404, 'facility-not-found', 'Not Found', 'The facility is not available.', $correlationId);
        }
        $facility = $this->handler->find($facilityId);
        if ($facility === null) {
            return OrganizationApi::problem(404, 'facility-not-found', 'Not Found', 'The facility is not available.', $correlationId);
        }

        return OrganizationApi::data($facility, 200, $correlationId, $facility['lock_version']);
    }
}
