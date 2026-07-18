<?php

namespace App\Http\Controllers\Organization;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\TemporaryAssignment\Http\TemporaryAssignmentApi;
use Modules\Organization\Features\TemporaryAssignment\Http\TemporaryAssignmentHttpGateway;
use Modules\Organization\Http\OrganizationApi;

final class ListTemporaryAssignmentsController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly TemporaryAssignmentHttpGateway $gateway,
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
            'organization_unit_id' => ['required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'cursor' => ['sometimes', 'string', 'min:1', 'max:4096'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($validator->fails() || array_diff(array_keys($query), ['organization_unit_id', 'cursor', 'limit']) !== []) {
            return OrganizationApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $organizationUnitId = (string) $validated['organization_unit_id'];
        if (! $this->access->decide($principal, 'organization.temporary-assignment.read', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_temporary_assignment',
            classification: 'internal',
            organizationUnitId: $organizationUnitId,
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        $limit = (int) ($validated['limit'] ?? 25);
        try {
            $page = $this->gateway->listInUnit($organizationUnitId, $validated['cursor'] ?? null, $limit);
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        } catch (DomainException) {
            return OrganizationApi::problem(404, 'organization-unit-not-found', 'Not Found', 'The organization unit is not available.', $correlationId);
        }
        $response = response()->json(TemporaryAssignmentApi::page($page))
            ->header('X-Correlation-ID', $correlationId);
        if ($page['next_cursor'] !== null) {
            $response->header('Link', '</api/v1/organization/temporary-assignments?'.http_build_query([
                'organization_unit_id' => $organizationUnitId,
                'cursor' => $page['next_cursor'],
                'limit' => $limit,
            ], '', '&', PHP_QUERY_RFC3986).'>; rel="next"');
        }

        return $response;
    }
}
