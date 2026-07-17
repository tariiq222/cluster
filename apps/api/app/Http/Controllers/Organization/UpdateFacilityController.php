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
use Modules\Organization\Features\UpdateFacility\Handler\UpdateFacilityHandler;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class UpdateFacilityController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly UpdateFacilityHandler $handler,
    ) {}

    public function __invoke(Request $request, string $facilityId): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! OrganizationApi::isUuidV7($facilityId)) {
            return OrganizationApi::problem(400, 'invalid-facility-id', 'Bad Request', 'facilityId must be a lowercase UUIDv7.', $correlationId);
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
        if (! $this->access->decide($principal, 'organization.facility.manage', new RecordFacts(
            ownerFacilityId: $facilityId,
            resourceType: 'organization_facility',
            classification: 'internal',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive,archived'],
            'reason' => ['sometimes', 'string', 'min:1', 'max:2000'],
        ]);
        $hasChange = array_key_exists('name', $input) || array_key_exists('status', $input);
        if ($validator->fails() || ! $hasChange || array_diff(array_keys($input), ['name', 'status', 'reason']) !== []) {
            return OrganizationApi::problem(400, 'invalid-facility', 'Bad Request', 'The facility patch is invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $changes = array_intersect_key($validated, array_flip(['name', 'status']));

        try {
            $facility = $this->handler->update(
                $facilityId,
                $expectedVersion,
                $changes,
                function (array $facility, string $previousStatus) use ($correlationId, $principal, $validated): array {
                    $eventType = $facility['status'] !== $previousStatus && in_array($facility['status'], ['inactive', 'archived'], true)
                        ? 'com.cluster.organization.facilityarchived.v1'
                        : 'com.cluster.organization.facilityupdated.v1';

                    return OrganizationApi::cloudEvent(
                        $eventType,
                        '/organization/facilities/'.$facility['id'],
                        $correlationId,
                        $facility['cluster_id'],
                        'facility',
                        $facility,
                        $principal,
                        isset($validated['reason']) ? ['reason' => $validated['reason']] : [],
                    );
                },
            );
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'facility_not_found' => OrganizationApi::problem(404, 'facility-not-found', 'Not Found', 'The facility is not available.', $correlationId),
                'precondition_failed' => OrganizationApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current facility version.', $correlationId),
                'invalid_facility_transition' => OrganizationApi::problem(409, 'invalid-facility-transition', 'Conflict', 'The facility status transition is not allowed.', $correlationId),
                default => OrganizationApi::problem(409, 'facility-conflict', 'Conflict', 'The facility cannot be updated.', $correlationId),
            };
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-facility', 'Bad Request', 'The facility patch does not change the profile.', $correlationId);
        } catch (UnexpectedValueException|QueryException) {
            return OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        }

        return OrganizationApi::data($facility, 200, $correlationId, $facility['lock_version']);
    }
}
