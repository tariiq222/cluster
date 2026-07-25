<?php

namespace Modules\Organization\Features\Position\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\Position\Handler\PositionHandler;
use Modules\Organization\Http\OrganizationApi;

final class GetPositionController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly PositionHandler $handler,
    ) {}

    public function __invoke(Request $request, string $positionId): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! OrganizationApi::isUuidV7($positionId)) {
            return OrganizationApi::problem(400, 'invalid-position-id', 'Bad Request', 'positionId must be a lowercase UUIDv7.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        if (! $this->access->decide($principal, 'organization.position.read', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_position',
            classification: 'internal',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $position = $this->handler->find($positionId);
        if ($position === null) {
            return OrganizationApi::problem(404, 'position-not-found', 'Not Found', 'The position is not available.', $correlationId);
        }

        return OrganizationApi::data($position, 200, $correlationId, $position['lock_version']);
    }
}
