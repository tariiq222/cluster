<?php

namespace App\Http\Controllers\Organization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\Assignment\Handler\AssignmentHandler;
use Modules\Organization\Http\OrganizationApi;

final class ListAssignmentsController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly AssignmentHandler $handler,
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
        if (! $this->access->decide($principal, 'organization.assignment.read', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_assignment',
            classification: 'internal',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        $query = $request->query();
        $validator = Validator::make($query, [
            'cursor' => ['sometimes', 'string', 'min:1', 'max:2048'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'person_id' => ['sometimes', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
        ]);
        if ($validator->fails() || array_diff(array_keys($query), ['cursor', 'limit', 'person_id']) !== []) {
            return OrganizationApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $limit = (int) ($validated['limit'] ?? 25);
        try {
            $page = $this->handler->list($principal, $validated['cursor'] ?? null, $limit, $validated['person_id'] ?? null);
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }
        $response = response()->json($page)->header('X-Correlation-ID', $correlationId);
        if ($page['next_cursor'] !== null) {
            $response->header('Link', '</api/v1/organization/assignments?'.http_build_query(array_filter([
                'cursor' => $page['next_cursor'],
                'limit' => $limit,
                'person_id' => $validated['person_id'] ?? null,
            ], fn (mixed $value): bool => $value !== null), '', '&', PHP_QUERY_RFC3986).'>; rel="next"');
        }

        return $response;
    }
}
