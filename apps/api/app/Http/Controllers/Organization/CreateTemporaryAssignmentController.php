<?php

namespace App\Http\Controllers\Organization;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\TemporaryAssignment\Exceptions\TemporaryAssignmentIdempotencyConflict;
use Modules\Organization\Features\TemporaryAssignment\Http\TemporaryAssignmentApi;
use Modules\Organization\Features\TemporaryAssignment\Http\TemporaryAssignmentHttpGateway;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class CreateTemporaryAssignmentController
{
    private const OPERATION = 'createTemporaryAssignment';

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
        $idempotencyKey = OrganizationApi::idempotencyKey($request);
        if ($idempotencyKey === null) {
            return OrganizationApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        $input = $request->json()->all();
        $utc = ['string', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/', 'date'];
        $validator = Validator::make($input, [
            'person_id' => ['required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'organization_unit_id' => ['required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'capability_codes' => ['required', 'array', 'min:1', 'max:100'],
            'capability_codes.*' => ['required', 'string', 'max:96', 'distinct:strict', 'regex:/\A[a-z][a-z0-9]*(?:[.:-][a-z0-9]+)*\z/'],
            'start_at' => ['required', ...$utc],
            'end_at' => ['required', ...$utc],
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ]);
        if ($validator->fails()
            || array_diff(array_keys($input), ['person_id', 'organization_unit_id', 'capability_codes', 'start_at', 'end_at', 'reason']) !== []) {
            return OrganizationApi::problem(400, 'invalid-temporary-assignment', 'Bad Request', 'The temporary assignment payload is invalid.', $correlationId);
        }
        $semantics = $validator->validated();
        $organizationUnitId = (string) $semantics['organization_unit_id'];
        if (! $this->access->decide($principal, 'organization.temporary-assignment.manage', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_temporary_assignment',
            classification: 'internal',
            organizationUnitId: $organizationUnitId,
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        $semantics['reason'] = trim((string) $semantics['reason']);
        if ($semantics['reason'] === '') {
            return OrganizationApi::problem(400, 'invalid-temporary-assignment', 'Bad Request', 'The temporary assignment payload is invalid.', $correlationId);
        }
        /** @var list<string> $capabilityCodes */
        $capabilityCodes = $semantics['capability_codes'];
        sort($capabilityCodes);
        $semantics['capability_codes'] = $capabilityCodes;
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'operation' => self::OPERATION,
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];

        try {
            $result = $this->gateway->create(
                Str::uuid7()->toString(),
                $semantics,
                $principal['user_id'],
                $correlationId,
                $idempotency,
            );
        } catch (TemporaryAssignmentIdempotencyConflict) {
            return OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-temporary-assignment', 'Bad Request', 'The temporary assignment payload or validity window is invalid.', $correlationId);
        } catch (DomainException $exception) {
            return $this->domainFailure($exception->getMessage(), $correlationId);
        } catch (QueryException $exception) {
            return (string) $exception->getCode() === '23000'
                ? OrganizationApi::problem(409, 'temporary-assignment-conflict', 'Conflict', 'The temporary assignment conflicts with Organization state.', $correlationId)
                : OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        } catch (UnexpectedValueException) {
            return OrganizationApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }

        return TemporaryAssignmentApi::resource($result['temporary_assignment'], 201, $correlationId);
    }

    private function domainFailure(string $code, string $correlationId): JsonResponse
    {
        return match ($code) {
            'person_not_found' => OrganizationApi::problem(404, 'person-not-found', 'Not Found', 'The Person is not available.', $correlationId),
            'organization_unit_not_found' => OrganizationApi::problem(404, 'organization-unit-not-found', 'Not Found', 'The organization unit is not available.', $correlationId),
            'person_inactive' => OrganizationApi::problem(409, 'person-inactive', 'Conflict', 'The Person is not active.', $correlationId),
            'organization_unit_inactive' => OrganizationApi::problem(409, 'organization-unit-inactive', 'Conflict', 'The organization unit is not active.', $correlationId),
            'temporary_assignment_capability_overlap' => OrganizationApi::problem(409, 'temporary-assignment-capability-overlap', 'Conflict', 'A capability already overlaps in this exact Person and OrganizationUnit scope.', $correlationId),
            'temporary_assignment_capability_not_active' => OrganizationApi::problem(409, 'temporary-assignment-capability-not-active', 'Conflict', 'A requested capability is not active.', $correlationId),
            'temporary_assignment_capability_validation_unavailable' => OrganizationApi::problem(503, 'temporary-assignment-capability-validation-unavailable', 'Service Unavailable', 'Capability validation is unavailable.', $correlationId),
            default => OrganizationApi::problem(409, 'temporary-assignment-conflict', 'Conflict', 'The temporary assignment cannot be created.', $correlationId),
        };
    }
}
