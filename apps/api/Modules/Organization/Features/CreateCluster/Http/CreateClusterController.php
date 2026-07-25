<?php

namespace Modules\Organization\Features\CreateCluster\Http;

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
use Modules\Organization\Domain\Cluster;
use Modules\Organization\Features\CreateCluster\Handler\CreateClusterHandler;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class CreateClusterController
{
    private const OPERATION = 'createCluster';

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly CreateClusterHandler $handler,
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
        if (! $this->access->decide($principal, 'organization.cluster.manage', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_cluster',
            classification: 'internal',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'code' => ['required', 'string', 'regex:/\A[A-Z0-9_-]{2,64}\z/'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['code', 'name', 'name_en']) !== []) {
            return OrganizationApi::problem(400, 'invalid-cluster', 'Bad Request', 'The cluster payload is invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $semantics = [
            'code' => $validated['code'],
            'name' => $validated['name'],
            'name_en' => $validated['name_en'] ?? null,
        ];
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'operation' => self::OPERATION,
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];

        try {
            $replay = $this->handler->findReplay($idempotency);
            if ($replay !== null) {
                return $replay['request_hash_matches']
                    ? OrganizationApi::data($replay['cluster'], 201, $correlationId, $replay['cluster']['lock_version'])
                    : OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
            }

            $cluster = Cluster::create(
                Str::uuid7()->toString(),
                $semantics['code'],
                $semantics['name'],
                $semantics['name_en'],
            );
            $data = $cluster->toArray();
            $result = $this->handler->persist(
                $cluster,
                OrganizationApi::cloudEvent(
                    'com.cluster.organization.clustercreated.v1',
                    '/organization/clusters/'.$cluster->id,
                    $correlationId,
                    $cluster->id,
                    'cluster',
                    $data,
                    $principal,
                ),
                $idempotency,
            );
        } catch (DomainException) {
            return OrganizationApi::problem(409, 'cluster-already-exists', 'Conflict', 'Only one cluster may exist.', $correlationId);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return OrganizationApi::problem(409, 'cluster-already-exists', 'Conflict', 'Only one cluster may exist.', $correlationId);
            }

            return OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-cluster', 'Bad Request', 'The cluster payload is invalid.', $correlationId);
        } catch (UnexpectedValueException) {
            return OrganizationApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }

        return OrganizationApi::data($result['cluster'], 201, $correlationId, $result['cluster']['lock_version']);
    }
}
