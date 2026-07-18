<?php

namespace App\Http\Controllers\Organization;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\ImportJob\Handler\ImportJobHandler;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class SubmitImportJobController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly ImportJobHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $idempotencyKey = OrganizationApi::idempotencyKey($request);
        if ($idempotencyKey === null) {
            return OrganizationApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        if (! $this->allowed($principal, 'organization.import.manage')) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'quarantine_object_id' => ['required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'template_code' => ['required', 'string', 'in:facilities,organization_units,positions,people_assignments'],
            'import_type' => ['required', 'string', 'in:csv,xlsx'],
            'notes' => ['sometimes', 'string', 'max:2000'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['quarantine_object_id', 'template_code', 'import_type', 'notes']) !== []) {
            return OrganizationApi::problem(400, 'invalid-import-job', 'Bad Request', 'The import job payload is invalid.', $correlationId);
        }
        $semantics = $validator->validated();
        $semantics['notes'] = $semantics['notes'] ?? null;
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'operation' => 'submitOrganizationImport',
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];
        try {
            $result = $this->handler->submit(
                Str::uuid7()->toString(),
                $semantics,
                $idempotency,
                $this->eventFactory($correlationId, $principal),
            );
        } catch (QueryException) {
            return OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The import job could not be saved.', $correlationId);
        } catch (UnexpectedValueException) {
            return OrganizationApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }
        if (! $result['request_hash_matches']) {
            return OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }

        return OrganizationApi::data($result['job'], 202, $correlationId, $result['job']['lock_version']);
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function allowed(array $principal, string $capability): bool
    {
        return $this->access->decide($principal, $capability, new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_import_job',
            classification: 'confidential',
        ))->isAllowed();
    }

    /** @param array{user_id: string, facility_id: string} $principal */
    private function eventFactory(string $correlationId, array $principal): \Closure
    {
        return fn (string $type, string $subject, array $data, string $classification): array => OrganizationApi::cloudEventData(
            $type,
            $subject,
            $correlationId,
            $principal['facility_id'],
            $principal,
            $data,
            $classification,
        );
    }
}
