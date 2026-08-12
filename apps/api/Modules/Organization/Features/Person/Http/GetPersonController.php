<?php

namespace Modules\Organization\Features\Person\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\Person\Authorization\PersonAuthorizationFacts;
use Modules\Organization\Features\Person\Handler\PersonHandler;
use Modules\Organization\Http\OrganizationApi;

final class GetPersonController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly PersonAuthorizationFacts $personAuthorization,
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
        $person = $this->handler->find($personId);
        if ($person === null) {
            return OrganizationApi::problem(404, 'person-not-found', 'Not Found', 'The Person is not available.', $correlationId);
        }
        if (! $this->personAuthorization->allows($principal, 'organization.person.read', $personId, 'organization_person')) {
            return OrganizationApi::problem(404, 'person-not-found', 'Not Found', 'The Person is not available.', $correlationId);
        }

        return OrganizationApi::data($person, 200, $correlationId, $person['person_version']);
    }
}
