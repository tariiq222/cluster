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
use Modules\Organization\Features\ImportJob\Handler\ImportJobHandler;
use Modules\Organization\Http\OrganizationApi;

final class ListImportJobRowsController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly ImportJobHandler $handler,
    ) {}

    public function __invoke(Request $request, string $jobId): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null || ! OrganizationApi::isUuidV7($jobId)) {
            return OrganizationApi::problem(400, 'invalid-import-job-id', 'Bad Request', 'A valid correlation and job identifier are required.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        if (! $this->access->decide($principal, 'organization.import.read', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_import_row',
            classification: 'confidential',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
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
            $page = $this->handler->listRows($jobId, $validated['cursor'] ?? null, $limit);
        } catch (DomainException) {
            return OrganizationApi::problem(404, 'import-job-not-found', 'Not Found', 'The import job is not available.', $correlationId);
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-pagination', 'Bad Request', 'The collection parameters are invalid.', $correlationId);
        }
        $response = response()->json($page)->header('X-Correlation-ID', $correlationId);
        if ($page['next_cursor'] !== null) {
            $response->header('Link', '</api/v1/organization/import-jobs/'.$jobId.'/rows?'.http_build_query([
                'cursor' => $page['next_cursor'],
                'limit' => $limit,
            ], '', '&', PHP_QUERY_RFC3986).'>; rel="next"');
        }

        return $response;
    }
}
