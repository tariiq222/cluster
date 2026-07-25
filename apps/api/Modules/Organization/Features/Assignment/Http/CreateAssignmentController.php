<?php

namespace Modules\Organization\Features\Assignment\Http;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\Assignment\Handler\AssignmentHandler;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class CreateAssignmentController
{
    private const OPERATION = 'createAssignment';

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
        $idempotencyKey = OrganizationApi::idempotencyKey($request);
        if ($idempotencyKey === null) {
            return OrganizationApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        if (! $this->access->decide($principal, 'organization.assignment.manage', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_assignment',
            classification: 'internal',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $input = $request->json()->all();
        $utc = ['string', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/', 'date'];
        $validator = Validator::make($input, [
            'person_id' => ['required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'position_id' => ['required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'start_at' => ['required', ...$utc],
            'end_at' => ['sometimes', ...$utc],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['person_id', 'position_id', 'start_at', 'end_at', 'is_primary']) !== []) {
            return OrganizationApi::problem(400, 'invalid-assignment', 'Bad Request', 'The assignment payload is invalid.', $correlationId);
        }
        $semantics = $validator->validated();
        $semantics['end_at'] = $semantics['end_at'] ?? null;
        $semantics['is_primary'] = $semantics['is_primary'] ?? true;
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'operation' => self::OPERATION,
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];
        $assignmentId = Str::uuid7()->toString();

        try {
            $result = $this->handler->create(
                $assignmentId,
                $semantics,
                $idempotency,
                fn (array $assignment, string $clusterId): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.assignmentstarted.v1',
                    '/organization/assignments/'.$assignment['id'],
                    $correlationId,
                    $clusterId,
                    'assignment',
                    $assignment,
                    $principal,
                ),
            );
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-assignment', 'Bad Request', 'The assignment window is invalid.', $correlationId);
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'person_not_found' => OrganizationApi::problem(404, 'person-not-found', 'Not Found', 'The Person is not available.', $correlationId),
                'position_not_found' => OrganizationApi::problem(404, 'position-not-found', 'Not Found', 'The position is not available.', $correlationId),
                'person_inactive' => OrganizationApi::problem(409, 'person-inactive', 'Conflict', 'The Person is not active.', $correlationId),
                'position_inactive' => OrganizationApi::problem(409, 'position-inactive', 'Conflict', 'The position is not active.', $correlationId),
                'primary_assignment_overlap' => OrganizationApi::problem(409, 'primary-assignment-overlap', 'Conflict', 'The Person already has an overlapping primary assignment.', $correlationId),
                'position_assignment_overlap' => OrganizationApi::problem(409, 'position-assignment-overlap', 'Conflict', 'The position already has an overlapping assignment.', $correlationId),
                default => OrganizationApi::problem(409, 'assignment-conflict', 'Conflict', 'The assignment cannot be created.', $correlationId),
            };
        } catch (QueryException $exception) {
            return (string) $exception->getCode() === '23000'
                ? OrganizationApi::problem(409, 'assignment-conflict', 'Conflict', 'The assignment conflicts with Organization state.', $correlationId)
                : OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        } catch (UnexpectedValueException) {
            return OrganizationApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }
        if (! $result['request_hash_matches']) {
            return OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }

        return OrganizationApi::data($result['assignment'], 201, $correlationId, $result['assignment']['lock_version']);
    }
}
