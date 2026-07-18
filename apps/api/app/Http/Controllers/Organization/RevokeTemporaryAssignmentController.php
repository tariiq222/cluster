<?php

namespace App\Http\Controllers\Organization;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\TemporaryAssignment\Exceptions\TemporaryAssignmentIdempotencyConflict;
use Modules\Organization\Features\TemporaryAssignment\Http\TemporaryAssignmentApi;
use Modules\Organization\Features\TemporaryAssignment\Http\TemporaryAssignmentHttpGateway;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class RevokeTemporaryAssignmentController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly TemporaryAssignmentHttpGateway $gateway,
    ) {}

    public function __invoke(
        Request $request,
        string $temporaryAssignmentId,
    ): JsonResponse {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! OrganizationApi::isUuidV7($temporaryAssignmentId)) {
            return OrganizationApi::problem(400, 'invalid-temporary-assignment-reference', 'Bad Request', 'The temporary assignment reference must be a lowercase UUIDv7.', $correlationId);
        }
        $expectedVersion = OrganizationApi::ifMatch($request);
        if ($expectedVersion === null) {
            return OrganizationApi::problem(400, 'invalid-if-match', 'Bad Request', 'If-Match must contain the current strong X-Resource-Version token.', $correlationId);
        }
        $idempotencyKey = OrganizationApi::idempotencyKey($request);
        if ($idempotencyKey === null) {
            return OrganizationApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        $temporaryAssignment = $this->gateway->find($temporaryAssignmentId);
        if ($temporaryAssignment === null) {
            return $this->notFound($correlationId);
        }
        if (! $this->access->decide($principal, 'organization.temporary-assignment.manage', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_temporary_assignment',
            classification: 'internal',
            organizationUnitId: (string) $temporaryAssignment['organization_unit_id'],
        ))->isAllowed()) {
            return $this->notFound($correlationId);
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ]);
        if ($validator->fails() || array_keys($input) !== ['reason']) {
            return OrganizationApi::problem(400, 'invalid-temporary-assignment-revocation', 'Bad Request', 'The revocation payload is invalid.', $correlationId);
        }
        $reason = trim((string) $validator->validated()['reason']);
        if ($reason === '') {
            return OrganizationApi::problem(400, 'invalid-temporary-assignment-revocation', 'Bad Request', 'The revocation payload is invalid.', $correlationId);
        }
        $semantics = [
            'temporary_assignment_id' => $temporaryAssignmentId,
            'expected_version' => $expectedVersion,
            'reason' => $reason,
        ];
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'operation' => 'revokeTemporaryAssignment:'.$temporaryAssignmentId,
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];

        try {
            $result = $this->gateway->revoke(
                $temporaryAssignmentId,
                $expectedVersion,
                $reason,
                $principal['user_id'],
                $correlationId,
                $idempotency,
            );
        } catch (TemporaryAssignmentIdempotencyConflict) {
            return OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-temporary-assignment-revocation', 'Bad Request', 'The revocation payload is invalid.', $correlationId);
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'temporary_assignment_not_found', 'temporary_assignment_organization_unit_unavailable' => $this->notFound($correlationId),
                'precondition_failed' => OrganizationApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current temporary assignment lock version.', $correlationId),
                'temporary_assignment_already_revoked' => OrganizationApi::problem(409, 'temporary-assignment-already-revoked', 'Conflict', 'The temporary assignment has already been revoked.', $correlationId),
                'temporary_assignment_expired' => OrganizationApi::problem(409, 'temporary-assignment-expired', 'Conflict', 'The temporary assignment has expired.', $correlationId),
                default => OrganizationApi::problem(409, 'temporary-assignment-conflict', 'Conflict', 'The temporary assignment cannot be revoked.', $correlationId),
            };
        } catch (QueryException) {
            return OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        } catch (UnexpectedValueException) {
            return OrganizationApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }

        return TemporaryAssignmentApi::resource($result['temporary_assignment'], 200, $correlationId);
    }

    private function notFound(string $correlationId): JsonResponse
    {
        return OrganizationApi::problem(404, 'temporary-assignment-not-found', 'Not Found', 'The temporary assignment is not available.', $correlationId);
    }
}
