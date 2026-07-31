<?php

declare(strict_types=1);

namespace Modules\Organization\Features\ImportFile\Http;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\ImportFile\Handler\ImportFileHandler;
use Modules\Organization\Http\OrganizationApi;

final class UploadImportFileController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly ImportFileHandler $handler,
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
        if (! $this->access->decide($principal, 'organization.import.manage', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_import_job',
            classification: 'confidential',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'max:10240'],
            'template_code' => ['required', 'string', 'in:facilities,organization_units,positions,people_assignments'],
            'import_type' => ['required', 'string', 'in:csv'],
        ]);
        if ($validator->fails() || ! $request->hasFile('file')) {
            return OrganizationApi::problem(400, 'invalid-import-file', 'Bad Request', 'A CSV import file is required.', $correlationId);
        }
        $file = $request->file('file');
        if (! $file instanceof \Illuminate\Http\UploadedFile || $file->getError() !== UPLOAD_ERR_OK) {
            return OrganizationApi::problem(400, 'invalid-import-file', 'Bad Request', 'The uploaded import file is not readable.', $correlationId);
        }
        try {
            $stored = $this->handler->store($file);
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'import_rows_too_many' => OrganizationApi::problem(400, 'import-rows-too-many', 'Bad Request', 'The import file must contain at most 1000 rows.', $correlationId),
                default => OrganizationApi::problem(400, 'invalid-import-file', 'Bad Request', 'The import file does not contain any CSV rows.', $correlationId),
            };
        }

        return OrganizationApi::data(['quarantine_object_id' => $stored['quarantine_object_id']], 201, $correlationId);
    }
}
