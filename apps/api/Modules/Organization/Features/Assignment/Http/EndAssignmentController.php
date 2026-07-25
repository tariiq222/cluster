<?php

namespace Modules\Organization\Features\Assignment\Http;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\Assignment\Handler\AssignmentHandler;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class EndAssignmentController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly AssignmentHandler $handler,
    ) {}

    public function __invoke(Request $request, string $assignmentId): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! OrganizationApi::isUuidV7($assignmentId)) {
            return OrganizationApi::problem(400, 'invalid-assignment-id', 'Bad Request', 'assignmentId must be a lowercase UUIDv7.', $correlationId);
        }
        $expectedVersion = OrganizationApi::ifMatch($request);
        if ($expectedVersion === null) {
            return OrganizationApi::problem(400, 'invalid-if-match', 'Bad Request', 'If-Match must contain one current strong ETag.', $correlationId);
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
        $validator = Validator::make($input, [
            'end_at' => ['required', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/', 'date'],
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['end_at', 'reason']) !== []) {
            return OrganizationApi::problem(400, 'invalid-assignment-end', 'Bad Request', 'The assignment end payload is invalid.', $correlationId);
        }
        $semantics = [
            ...$validator->validated(),
            'assignment_id' => $assignmentId,
            'expected_version' => $expectedVersion,
        ];
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'operation' => 'endAssignment:'.$assignmentId,
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];
        try {
            $result = $this->handler->end(
                $assignmentId,
                $expectedVersion,
                $semantics['end_at'],
                $semantics['reason'],
                $principal['user_id'],
                $idempotency,
                fn (array $assignment, string $clusterId): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.assignmentended.v1',
                    '/organization/assignments/'.$assignment['id'],
                    $correlationId,
                    $clusterId,
                    'assignment',
                    $assignment,
                    $principal,
                ),
            );
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-assignment-end', 'Bad Request', 'The assignment end time is invalid.', $correlationId);
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'assignment_not_found' => OrganizationApi::problem(404, 'assignment-not-found', 'Not Found', 'The assignment is not available.', $correlationId),
                'precondition_failed' => OrganizationApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current assignment version.', $correlationId),
                'assignment_already_ended' => OrganizationApi::problem(409, 'assignment-already-ended', 'Conflict', 'The assignment has already ended.', $correlationId),
                'assignment_not_active' => OrganizationApi::problem(409, 'assignment-not-active', 'Conflict', 'Only an active assignment can be ended.', $correlationId),
                default => OrganizationApi::problem(409, 'assignment-conflict', 'Conflict', 'The assignment cannot be ended.', $correlationId),
            };
        } catch (QueryException) {
            return OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        } catch (UnexpectedValueException) {
            return OrganizationApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }
        if (! $result['request_hash_matches']) {
            return OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }

        return OrganizationApi::data($result['assignment'], 200, $correlationId, $result['assignment']['lock_version']);
    }
}
