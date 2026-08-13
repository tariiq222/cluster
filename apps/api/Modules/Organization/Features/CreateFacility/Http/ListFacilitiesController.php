<?php

namespace Modules\Organization\Features\CreateFacility\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\Authorization\OrganizationResourceFacts;
use Modules\Organization\Features\CreateFacility\Handler\CreateFacilityHandler;
use Modules\Organization\Http\OrganizationApi;

final class ListFacilitiesController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly OrganizationResourceFacts $resourceFacts,
        private readonly CreateFacilityHandler $handler,
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
        $query = $request->query();
        $validator = Validator::make($query, [
            'cursor' => ['sometimes', 'string', 'min:1', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($validator->fails() || array_diff(array_keys($query), ['cursor', 'limit']) !== []) {
            return OrganizationApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 25);
        try {
            $page = $this->handler->list(
                $principal,
                $validated['cursor'] ?? null,
                $limit,
                function (string $facilityId) use ($principal): bool {
                    $facts = $this->resourceFacts->factsForFacility($facilityId);

                    return $facts !== null
                        && $this->access->decide($principal, 'organization.facility.read', $facts)->isAllowed();
                },
            );
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }

        $response = response()->json($page)->header('X-Correlation-ID', $correlationId);
        if ($page['next_cursor'] !== null) {
            $response->header('Link', '</api/v1/organization/facilities?'.http_build_query([
                'cursor' => $page['next_cursor'],
                'limit' => $limit,
            ], '', '&', PHP_QUERY_RFC3986).'>; rel="next"');
        }

        return $response;
    }
}
