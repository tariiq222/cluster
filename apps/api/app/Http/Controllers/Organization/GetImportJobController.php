<?php

namespace App\Http\Controllers\Organization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\ImportJob\Handler\ImportJobHandler;
use Modules\Organization\Http\OrganizationApi;

final class GetImportJobController
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
            resourceType: 'organization_import_job',
            classification: 'confidential',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        $job = $this->handler->find($jobId);
        if ($job === null) {
            return OrganizationApi::problem(404, 'import-job-not-found', 'Not Found', 'The import job is not available.', $correlationId);
        }

        return OrganizationApi::data($job, 200, $correlationId, $job['lock_version']);
    }
}
