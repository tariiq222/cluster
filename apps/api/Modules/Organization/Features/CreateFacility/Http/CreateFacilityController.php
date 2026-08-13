<?php

namespace Modules\Organization\Features\CreateFacility\Http;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Organization\Contracts\DecideAccess;
use Modules\Organization\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Domain\Facility;
use Modules\Organization\Features\Authorization\OrganizationResourceFacts;
use Modules\Organization\Features\CreateFacility\Handler\CreateFacilityHandler;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class CreateFacilityController
{
    private const OPERATION = 'createFacility';

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly OrganizationResourceFacts $resourceFacts,
        private readonly CreateFacilityHandler $handler,
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
            'cluster_id' => ['required', 'string'],
            'type_code' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]{1,63}\z/'],
            'code' => ['required', 'string', 'regex:/\A[A-Z0-9_-]{2,64}\z/'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['cluster_id', 'type_code', 'code', 'name', 'name_en']) !== []) {
            return OrganizationApi::problem(400, 'invalid-facility', 'Bad Request', 'The facility payload is invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $facts = $this->resourceFacts->factsForCluster($validated['cluster_id']);
        if ($facts === null || ! $this->access->decide($principal, 'organization.facility.manage', $facts)->isAllowed()) {
            return OrganizationApi::problem(404, 'cluster-not-found', 'Not Found', 'The cluster is not available.', $correlationId);
        }
        $semantics = [
            'cluster_id' => $validated['cluster_id'],
            'type_code' => $validated['type_code'],
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
                    ? OrganizationApi::data($replay['facility'], 201, $correlationId, $replay['facility']['lock_version'])
                    : OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
            }

            $facility = Facility::create(
                Str::uuid7()->toString(),
                $semantics['cluster_id'],
                $semantics['type_code'],
                $semantics['code'],
                $semantics['name'],
                $semantics['name_en'],
            );
            $data = $facility->toArray();
            $result = $this->handler->persist(
                $facility,
                OrganizationApi::cloudEvent(
                    'com.cluster.organization.facilitycreated.v1',
                    '/organization/facilities/'.$facility->id,
                    $correlationId,
                    $facility->clusterId,
                    'facility',
                    $data,
                    $principal,
                ),
                $idempotency,
            );
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-facility', 'Bad Request', 'The facility parent or type is invalid.', $correlationId);
        } catch (DomainException) {
            return OrganizationApi::problem(409, 'facility-already-exists', 'Conflict', 'A facility with this code already exists.', $correlationId);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return OrganizationApi::problem(409, 'facility-already-exists', 'Conflict', 'A facility with this code already exists.', $correlationId);
            }

            return OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        } catch (UnexpectedValueException) {
            return OrganizationApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }

        return OrganizationApi::data($result['facility'], 201, $correlationId, $result['facility']['lock_version']);
    }
}
