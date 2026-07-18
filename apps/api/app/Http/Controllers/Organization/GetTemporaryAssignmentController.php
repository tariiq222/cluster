<?php

namespace App\Http\Controllers\Organization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\TemporaryAssignment\Http\TemporaryAssignmentApi;
use Modules\Organization\Features\TemporaryAssignment\Http\TemporaryAssignmentHttpGateway;
use Modules\Organization\Http\OrganizationApi;

final class GetTemporaryAssignmentController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly TemporaryAssignmentHttpGateway $gateway,
    ) {}

    public function __invoke(
        Request $request,
        string $organizationUnitId,
        string $temporaryAssignmentId,
    ): JsonResponse|Response {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! OrganizationApi::isUuidV7($organizationUnitId)
            || ! OrganizationApi::isUuidV7($temporaryAssignmentId)) {
            return OrganizationApi::problem(400, 'invalid-temporary-assignment-reference', 'Bad Request', 'The temporary assignment reference must contain lowercase UUIDv7 values.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        if (! $this->access->decide($principal, 'organization.temporary-assignment.read', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_temporary_assignment',
            classification: 'internal',
        ))->isAllowed()) {
            return $this->notFound($correlationId);
        }

        $temporaryAssignment = $this->gateway->findInUnit($organizationUnitId, $temporaryAssignmentId);
        if ($temporaryAssignment === null) {
            return $this->notFound($correlationId);
        }
        if (TemporaryAssignmentApi::requestCacheMatches($request, $temporaryAssignment)) {
            return TemporaryAssignmentApi::notModified($temporaryAssignment, $correlationId);
        }

        return TemporaryAssignmentApi::resource($temporaryAssignment, 200, $correlationId);
    }

    private function notFound(string $correlationId): JsonResponse
    {
        return OrganizationApi::problem(404, 'temporary-assignment-not-found', 'Not Found', 'The temporary assignment is not available.', $correlationId);
    }
}
