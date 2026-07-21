<?php

namespace App\Http\Controllers\Organization;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Features\JobTitle\Handler\JobTitleHandler;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class CreateJobTitleController
{
    private const OPERATION = 'createJobTitle';

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly JobTitleHandler $handler,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return OrganizationApi::problem(400, 'invalid-idempotency-key', 'Bad Request', 'Idempotency-Key is required.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        if (! $this->access->decide($principal, 'organization.position.manage', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_job_title',
            classification: 'internal',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'code' => ['required', 'string', 'regex:/\A[A-Z0-9_-]{2,96}\z/'],
            'title_ar' => ['required', 'string', 'min:1', 'max:255'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['code', 'title_ar']) !== []) {
            return OrganizationApi::problem(400, 'invalid-job-title', 'Bad Request', 'The job title payload is invalid.', $correlationId);
        }
        $semantics = $validator->validated();
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'operation' => self::OPERATION,
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];
        $jobTitleId = Str::uuid7()->toString();

        try {
            $result = $this->handler->create(
                $jobTitleId,
                $semantics,
                $idempotency,
                fn (array $jobTitle, string $aggregateId): array => OrganizationApi::cloudEvent(
                    'com.cluster.organization.jobtitlecreated.v1',
                    '/organization/job-titles/'.$jobTitle['id'],
                    $correlationId,
                    $principal['facility_id'],
                    'job_title',
                    $jobTitle,
                    $principal,
                ),
            );
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-job-title', 'Bad Request', 'The job title payload is invalid.', $correlationId);
        } catch (DomainException $exception) {
            return $exception->getMessage() === 'job_title_already_exists'
                ? OrganizationApi::problem(409, 'job-title-already-exists', 'Conflict', 'A job title with this code already exists.', $correlationId)
                : OrganizationApi::problem(409, 'job-title-conflict', 'Conflict', 'The job title cannot be created.', $correlationId);
        } catch (QueryException $exception) {
            return (string) $exception->getCode() === '23000'
                ? OrganizationApi::problem(409, 'job-title-already-exists', 'Conflict', 'A job title with this code already exists.', $correlationId)
                : OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        } catch (UnexpectedValueException) {
            return OrganizationApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }

        if (! $result['request_hash_matches']) {
            return OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }

        return OrganizationApi::data($result['job_title'], 201, $correlationId, 1);
    }
}
