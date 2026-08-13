<?php

namespace Modules\Organization\Features\OrganizationUnit\Http;

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
use Modules\Organization\Features\OrganizationUnit\Handler\OrganizationUnitHandler;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class CreateOrganizationUnitController
{
    private const OPERATION = 'createOrganizationUnit';

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly OrganizationResourceFacts $resourceFacts,
        private readonly OrganizationUnitHandler $handler,
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
            'cluster_id' => ['required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'parent_id' => ['sometimes', 'required', 'string', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/'],
            'type_code' => ['required', 'string', 'regex:/\A[a-z][a-z0-9_]{1,63}\z/'],
            'code' => ['required', 'string', 'regex:/\A[A-Z0-9_-]{2,64}\z/'],
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['cluster_id', 'parent_id', 'type_code', 'code', 'name', 'name_en']) !== []) {
            return OrganizationApi::problem(400, 'invalid-organization-unit', 'Bad Request', 'The organization unit payload is invalid.', $correlationId);
        }
        $semantics = $validator->validated();
        $semantics['name_en'] = $semantics['name_en'] ?? null;
        $parentFacts = $this->resourceFacts->factsForUnitParent(
            $semantics['cluster_id'],
            $semantics['parent_id'] ?? null,
        );
        if ($parentFacts === null || ! $this->access->decide($principal, 'organization.unit.manage', $parentFacts)->isAllowed()) {
            return OrganizationApi::problem(404, 'organization-unit-not-found', 'Not Found', 'The organization unit parent is not available.', $correlationId);
        }
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'operation' => self::OPERATION,
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];
        $unitId = Str::uuid7()->toString();

        try {
            $result = $this->handler->create(
                $unitId,
                $semantics,
                $idempotency,
                fn (array $unit): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.organizationunitcreated.v1',
                    '/organization/units/'.$unit['id'],
                    $correlationId,
                    $unit['cluster_id'],
                    'organization_unit',
                    $unit,
                    $principal,
                ),
            );
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-organization-unit', 'Bad Request', 'The organization unit parent or type is invalid.', $correlationId);
        } catch (DomainException) {
            return OrganizationApi::problem(409, 'organization-unit-already-exists', 'Conflict', 'An organization unit with this code already exists under the parent.', $correlationId);
        } catch (QueryException $exception) {
            return (string) $exception->getCode() === '23000'
                ? OrganizationApi::problem(409, 'organization-unit-already-exists', 'Conflict', 'An organization unit with this code already exists under the parent.', $correlationId)
                : OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        } catch (UnexpectedValueException) {
            return OrganizationApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }

        if (! $result['request_hash_matches']) {
            return OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }

        return OrganizationApi::data($result['unit'], 201, $correlationId, $result['unit']['lock_version']);
    }
}
