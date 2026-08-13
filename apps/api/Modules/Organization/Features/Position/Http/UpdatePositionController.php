<?php

namespace Modules\Organization\Features\Position\Http;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\Authorization\OrganizationResourceFacts;
use Modules\Organization\Features\Position\Handler\PositionHandler;
use Modules\Organization\Http\OrganizationApi;

final class UpdatePositionController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly OrganizationResourceFacts $resourceFacts,
        private readonly PositionHandler $handler,
    ) {}

    /**
     * PATCH accepts unit, title, manager and is_active changes; job_title_id is preserved or nulled by the handler's title resolution semantics.
     */
    public function __invoke(Request $request, string $positionId): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! OrganizationApi::isUuidV7($positionId)) {
            return OrganizationApi::problem(400, 'invalid-position-id', 'Bad Request', 'positionId must be a lowercase UUIDv7.', $correlationId);
        }
        $expectedVersion = OrganizationApi::ifMatch($request);
        if ($expectedVersion === null) {
            return OrganizationApi::problem(400, 'invalid-if-match', 'Bad Request', 'If-Match must contain one current strong ETag.', $correlationId);
        }
        if (! OrganizationApi::isMergePatch($request)) {
            return OrganizationApi::problem(400, 'invalid-content-type', 'Bad Request', 'Content-Type must be application/merge-patch+json.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        $facts = $this->resourceFacts->factsForPosition($positionId);
        if ($facts === null || ! $this->access->decide($principal, 'organization.position.manage', $facts)->isAllowed()) {
            return OrganizationApi::problem(404, 'position-not-found', 'Not Found', 'The position is not available.', $correlationId);
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'organization_unit_id' => ['sometimes', 'required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'manager_position_id' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);
        $hasChange = array_intersect(array_keys($input), ['organization_unit_id', 'title', 'manager_position_id', 'is_active']) !== [];
        if ($validator->fails() || ! $hasChange || array_diff(array_keys($input), ['organization_unit_id', 'title', 'manager_position_id', 'is_active']) !== []) {
            return OrganizationApi::problem(400, 'invalid-position', 'Bad Request', 'The position patch is invalid.', $correlationId);
        }
        $changes = $validator->validated();
        if (array_key_exists('manager_position_id', $input) && $input['manager_position_id'] === null) {
            $changes['manager_position_id'] = null;
        }
        if (isset($changes['organization_unit_id'])) {
            $unitFacts = $this->resourceFacts->factsForUnit($changes['organization_unit_id']);
            if ($unitFacts === null || ! $this->access->decide($principal, 'organization.position.manage', $unitFacts)->isAllowed()) {
                return OrganizationApi::problem(404, 'organization-unit-not-found', 'Not Found', 'The organization unit is not available.', $correlationId);
            }
        }
        if (isset($changes['manager_position_id'])) {
            $managerFacts = $this->resourceFacts->factsForPosition($changes['manager_position_id']);
            if ($managerFacts === null || ! $this->access->decide($principal, 'organization.position.manage', $managerFacts)->isAllowed()) {
                return OrganizationApi::problem(404, 'position-not-found', 'Not Found', 'The manager position is not available.', $correlationId);
            }
        }

        try {
            $position = $this->handler->update(
                $positionId,
                $expectedVersion,
                $changes,
                fn (array $position, string $clusterId): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.positionupdated.v1',
                    '/organization/positions/'.$position['id'],
                    $correlationId,
                    $clusterId,
                    'position',
                    $position,
                    $principal,
                ),
            );
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'position_not_found' => OrganizationApi::problem(404, 'position-not-found', 'Not Found', 'The position is not available.', $correlationId),
                'precondition_failed' => OrganizationApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current position version.', $correlationId),
                'position_manager_cycle' => OrganizationApi::problem(409, 'position-manager-cycle', 'Conflict', 'The manager position relationship would create a cycle.', $correlationId),
                'position_has_subordinates' => OrganizationApi::problem(409, 'position-deactivation-conflict', 'Conflict', 'The position is the manager of other positions and cannot be deactivated.', $correlationId),
                default => OrganizationApi::problem(409, 'position-conflict', 'Conflict', 'The position cannot be updated.', $correlationId),
            };
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-position', 'Bad Request', 'The position unit or manager is invalid.', $correlationId);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return OrganizationApi::problem(409, 'position-conflict', 'Conflict', 'The position code conflicts in the target unit.', $correlationId);
            }

            return OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        }

        return OrganizationApi::data($position, 200, $correlationId, $position['lock_version']);
    }
}
