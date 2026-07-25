<?php

namespace Modules\Organization\Features\Person\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\Person\Handler\PersonHandler;
use Modules\Organization\Http\OrganizationApi;

final class GetPersonReferenceController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly PersonHandler $handler,
    ) {}

    public function __invoke(Request $request, string $personId): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! OrganizationApi::isUuidV7($personId)) {
            return OrganizationApi::problem(400, 'invalid-person-id', 'Bad Request', 'personId must be a lowercase UUIDv7.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        if (! $this->access->decide($principal, 'organization.person.reference', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_person_reference',
            classification: 'confidential',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $reference = $this->handler->reference($personId);
        if ($reference === null) {
            return OrganizationApi::problem(404, 'person-not-found', 'Not Found', 'The Person is not available.', $correlationId);
        }

        return response()->json($reference)->header('X-Correlation-ID', $correlationId);
    }
}
