<?php

namespace Modules\Organization\Features\OrganizationUnit\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\Authorization\OrganizationResourceFacts;
use Modules\Organization\Features\OrganizationUnit\Handler\OrganizationUnitHandler;
use Modules\Organization\Http\OrganizationApi;

final class GetOrganizationUnitController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly OrganizationResourceFacts $resourceFacts,
        private readonly OrganizationUnitHandler $handler,
    ) {}

    public function __invoke(Request $request, string $unitId): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! OrganizationApi::isUuidV7($unitId)) {
            return OrganizationApi::problem(400, 'invalid-organization-unit-id', 'Bad Request', 'unitId must be a lowercase UUIDv7.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        $facts = $this->resourceFacts->factsForUnit($unitId);
        if ($facts === null || ! $this->access->decide($principal, 'organization.unit.read', $facts)->isAllowed()) {
            return OrganizationApi::problem(404, 'organization-unit-not-found', 'Not Found', 'The organization unit is not available.', $correlationId);
        }
        $unit = $this->handler->find($unitId);
        if ($unit === null) {
            return OrganizationApi::problem(404, 'organization-unit-not-found', 'Not Found', 'The organization unit is not available.', $correlationId);
        }

        return OrganizationApi::data($unit, 200, $correlationId, $unit['lock_version']);
    }
}
