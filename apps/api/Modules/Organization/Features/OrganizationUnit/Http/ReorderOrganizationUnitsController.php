<?php

namespace Modules\Organization\Features\OrganizationUnit\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\OrganizationUnit\Handler\OrganizationUnitHandler;
use Modules\Organization\Http\OrganizationApi;

final class ReorderOrganizationUnitsController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
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

        if (! $this->access->decide($principal, 'organization.unit.manage', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_unit',
            classification: 'internal',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $result = $this->handler->reorderAll(
            fn (array $payload): array => OrganizationApi::cloudEvent(
                'com.cluster.organization.organizationunitsreordered.v1',
                '/organization/units',
                $correlationId,
                $principal['facility_id'],
                'organization_unit_collection',
                $payload,
                $principal,
            ),
        );

        return OrganizationApi::data($result, 200, $correlationId);
    }
}
