<?php

namespace Modules\Organization\Features\OrganizationUnit\Http;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\OrganizationUnit\Handler\OrganizationUnitHandler;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class UpdateOrganizationUnitController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly OrganizationUnitHandler $handler,
    ) {}

    public function __invoke(Request $request, string $unitId): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! OrganizationApi::isUuidV7($unitId)) {
            return OrganizationApi::problem(400, 'invalid-organization-unit-id', 'Bad Request', 'unitId must be a lowercase UUIDv7.', $correlationId);
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
        if (! $this->access->decide($principal, 'organization.unit.manage', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_unit',
            classification: 'internal',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'parent_id' => ['sometimes', 'required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive,archived'],
            'reason' => ['sometimes', 'string', 'min:1', 'max:2000'],
        ]);
        $hasChange = array_intersect(array_keys($input), ['parent_id', 'name', 'status']) !== [];
        if ($validator->fails() || ! $hasChange || array_diff(array_keys($input), ['parent_id', 'name', 'status', 'reason']) !== []) {
            return OrganizationApi::problem(400, 'invalid-organization-unit', 'Bad Request', 'The organization unit patch is invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $changes = array_intersect_key($validated, array_flip(['parent_id', 'name', 'status']));

        try {
            $unit = $this->handler->update(
                $unitId,
                $expectedVersion,
                $changes,
                function (array $unit, string $previousParentId, string $previousStatus) use ($correlationId, $principal, $validated): array {
                    $eventType = $unit['parent_id'] !== $previousParentId
                        ? 'com.cluster.organization.organizationunitmoved.v1'
                        : ($unit['status'] !== $previousStatus && in_array($unit['status'], ['inactive', 'archived'], true)
                            ? 'com.cluster.organization.organizationunitarchived.v1'
                            : 'com.cluster.organization.organizationunitupdated.v1');

                    return OrganizationApi::cloudEvent(
                        $eventType,
                        '/organization/units/'.$unit['id'],
                        $correlationId,
                        $unit['cluster_id'],
                        'organization_unit',
                        $unit,
                        $principal,
                        array_filter([
                            'previous_parent_id' => $unit['parent_id'] !== $previousParentId ? $previousParentId : null,
                            'reason' => $validated['reason'] ?? null,
                        ], fn (mixed $value): bool => $value !== null),
                    );
                },
            );
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'organization_unit_not_found' => OrganizationApi::problem(404, 'organization-unit-not-found', 'Not Found', 'The organization unit is not available.', $correlationId),
                'precondition_failed' => OrganizationApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current organization unit version.', $correlationId),
                'organization_unit_cycle' => OrganizationApi::problem(409, 'organization-unit-cycle', 'Conflict', 'An organization unit cannot move below itself or its descendant.', $correlationId),
                'organization_unit_owner_root_mismatch' => OrganizationApi::problem(409, 'organization-unit-owner-root-mismatch', 'Conflict', 'The organization unit cannot be moved across ownership roots.', $correlationId),
                'organization_unit_owner_root_unresolved' => OrganizationApi::problem(409, 'organization-unit-conflict', 'Conflict', 'The organization unit cannot be updated.', $correlationId),
                'invalid_organization_unit_transition' => OrganizationApi::problem(409, 'invalid-organization-unit-transition', 'Conflict', 'The organization unit status transition is not allowed.', $correlationId),
                default => OrganizationApi::problem(409, 'organization-unit-conflict', 'Conflict', 'The organization unit cannot be updated.', $correlationId),
            };
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-organization-unit', 'Bad Request', 'The organization unit patch is invalid.', $correlationId);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return OrganizationApi::problem(409, 'organization-unit-conflict', 'Conflict', 'The organization unit code conflicts under the target parent.', $correlationId);
            }

            return OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        } catch (UnexpectedValueException) {
            return OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        }

        return OrganizationApi::data($unit, 200, $correlationId, $unit['lock_version']);
    }
}
