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
use Modules\Organization\Features\UpdateCluster\Handler\UpdateClusterHandler;
use Modules\Organization\Http\OrganizationApi;

final class UpdateClusterController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly UpdateClusterHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
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
        if (! $this->access->decide($principal, 'organization.cluster.manage', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_cluster',
            classification: 'internal',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'reason' => ['sometimes', 'string', 'min:1', 'max:2000'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['name', 'reason']) !== []) {
            return OrganizationApi::problem(400, 'invalid-cluster', 'Bad Request', 'The cluster patch is invalid.', $correlationId);
        }
        $validated = $validator->validated();

        try {
            $cluster = $this->handler->update(
                $expectedVersion,
                ['name' => $validated['name']],
                fn (array $cluster): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.clusterupdated.v1',
                    '/organization/clusters/'.$cluster['id'],
                    $correlationId,
                    $cluster['id'],
                    'cluster',
                    $cluster,
                    $principal,
                    isset($validated['reason']) ? ['reason' => $validated['reason']] : [],
                ),
            );
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'cluster_not_found' => OrganizationApi::problem(404, 'cluster-not-found', 'Not Found', 'The cluster is not available.', $correlationId),
                'precondition_failed' => OrganizationApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current cluster version.', $correlationId),
                default => OrganizationApi::problem(409, 'cluster-conflict', 'Conflict', 'The cluster cannot be updated.', $correlationId),
            };
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-cluster', 'Bad Request', 'The cluster patch does not change the profile.', $correlationId);
        } catch (QueryException) {
            return OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        }

        return OrganizationApi::data($cluster, 200, $correlationId, $cluster['lock_version']);
    }
}
