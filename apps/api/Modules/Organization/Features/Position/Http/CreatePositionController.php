<?php

namespace Modules\Organization\Features\Position\Http;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\Authorization\OrganizationResourceFacts;
use Modules\Organization\Features\Position\Handler\PositionHandler;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class CreatePositionController
{
    private const OPERATION = 'createPosition';

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly OrganizationResourceFacts $resourceFacts,
        private readonly PositionHandler $handler,
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
        $validator = Validator::make($input, [
            'organization_unit_id' => ['required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'code' => ['required', 'string', 'regex:/\A[A-Z0-9_-]{2,64}\z/'],
            'title' => ['sometimes', 'string', 'min:1', 'max:255'],
            'job_title_id' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'manager_position_id' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['organization_unit_id', 'code', 'title', 'job_title_id', 'manager_position_id']) !== []) {
            return OrganizationApi::problem(400, 'invalid-position', 'Bad Request', 'The position payload is invalid.', $correlationId);
        }
        $semantics = $validator->validated();
        $semantics['manager_position_id'] = $semantics['manager_position_id'] ?? null;
        $semantics['job_title_id'] = $semantics['job_title_id'] ?? null;
        if (! isset($semantics['job_title_id']) && ! isset($semantics['title'])) {
            return OrganizationApi::problem(400, 'invalid-position', 'Bad Request', 'Either job_title_id or title is required.', $correlationId);
        }
        $unitFacts = $this->resourceFacts->factsForUnit($semantics['organization_unit_id']);
        if ($unitFacts === null || ! $this->access->decide($principal, 'organization.position.manage', $unitFacts)->isAllowed()) {
            return OrganizationApi::problem(404, 'organization-unit-not-found', 'Not Found', 'The organization unit is not available.', $correlationId);
        }
        if ($semantics['manager_position_id'] !== null) {
            $managerFacts = $this->resourceFacts->factsForPosition($semantics['manager_position_id']);
            if ($managerFacts === null || ! $this->access->decide($principal, 'organization.position.manage', $managerFacts)->isAllowed()) {
                return OrganizationApi::problem(404, 'position-not-found', 'Not Found', 'The manager position is not available.', $correlationId);
            }
        }
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'operation' => self::OPERATION,
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];
        $positionId = Str::uuid7()->toString();

        try {
            $result = $this->handler->create(
                $positionId,
                $semantics,
                $idempotency,
                fn (array $position, string $clusterId): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.positioncreated.v1',
                    '/organization/positions/'.$position['id'],
                    $correlationId,
                    $clusterId,
                    'position',
                    $position,
                    $principal,
                ),
            );
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-position', 'Bad Request', 'The position unit or manager is invalid.', $correlationId);
        } catch (DomainException $exception) {
            return $exception->getMessage() === 'position_manager_cycle'
                ? OrganizationApi::problem(409, 'position-manager-cycle', 'Conflict', 'The manager position relationship would create a cycle.', $correlationId)
                : OrganizationApi::problem(409, 'position-already-exists', 'Conflict', 'A position with this code already exists in the unit.', $correlationId);
        } catch (QueryException $exception) {
            return (string) $exception->getCode() === '23000'
                ? OrganizationApi::problem(409, 'position-already-exists', 'Conflict', 'A position with this code already exists in the unit.', $correlationId)
                : OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        } catch (UnexpectedValueException) {
            return OrganizationApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }

        if (! $result['request_hash_matches']) {
            return OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }

        return OrganizationApi::data($result['position'], 201, $correlationId, $result['position']['lock_version']);
    }
}
