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
use Modules\Organization\Features\ImportJob\Handler\ImportJobHandler;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class TransitionImportJobController
{
    private const ACTIONS = ['validate', 'approve', 'reject', 'apply', 'cancel'];

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly ImportJobHandler $handler,
    ) {}

    public function __invoke(Request $request, string $jobId, string $jobAction): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null || ! OrganizationApi::isUuidV7($jobId) || ! in_array($jobAction, self::ACTIONS, true)) {
            return OrganizationApi::problem(400, 'invalid-import-action', 'Bad Request', 'A valid import action is required.', $correlationId);
        }
        $expectedVersion = OrganizationApi::ifMatch($request);
        $idempotencyKey = OrganizationApi::idempotencyKey($request);
        if ($expectedVersion === null || $idempotencyKey === null) {
            return OrganizationApi::problem(400, 'invalid-import-headers', 'Bad Request', 'If-Match and Idempotency-Key are required.', $correlationId);
        }
        $principal = $this->principalResolver->resolve($request);
        if ($principal === null) {
            return OrganizationApi::problem(401, 'authentication-required', 'Unauthorized', 'Authentication is required.', $correlationId);
        }
        $capability = in_array($jobAction, ['approve', 'reject'], true)
            ? 'organization.import.approve'
            : 'organization.import.manage';
        if (! $this->access->decide($principal, $capability, new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_import_job',
            classification: 'confidential',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }
        $input = $request->json()->all();
        $validator = Validator::make($input, ['reason' => ['sometimes', 'string', 'min:1', 'max:2000']]);
        if ($validator->fails() || array_diff(array_keys($input), ['reason']) !== []) {
            return OrganizationApi::problem(400, 'invalid-import-action', 'Bad Request', 'The import action payload is invalid.', $correlationId);
        }
        $reason = $validator->validated()['reason'] ?? null;
        $semantics = ['job_id' => $jobId, 'action' => $jobAction, 'expected_version' => $expectedVersion, 'reason' => $reason];
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'operation' => 'transitionImport:'.$jobId.':'.$jobAction,
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];
        try {
            $result = $this->handler->transition(
                $jobId,
                $jobAction,
                $expectedVersion,
                $principal['user_id'],
                $reason,
                $idempotency,
                fn (string $type, string $subject, array $data, string $classification): array => OrganizationApi::cloudEventData(
                    $type,
                    $subject,
                    $correlationId,
                    $principal['facility_id'],
                    $principal,
                    $data,
                    $classification,
                ),
            );
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-import-action', 'Bad Request', 'The import action is invalid.', $correlationId);
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'import_job_not_found' => OrganizationApi::problem(404, 'import-job-not-found', 'Not Found', 'The import job is not available.', $correlationId),
                'precondition_failed' => OrganizationApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current import version.', $correlationId),
                'import_dual_approval_required' => OrganizationApi::problem(409, 'import-dual-approval-required', 'Conflict', 'The submitter cannot approve the same import.', $correlationId),
                default => OrganizationApi::problem(409, 'import-transition-invalid', 'Conflict', 'The import transition is not allowed.', $correlationId),
            };
        } catch (QueryException|UnexpectedValueException) {
            return OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The import transition could not be saved.', $correlationId);
        }
        if (! $result['request_hash_matches']) {
            return OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }

        return OrganizationApi::data($result['job'], 200, $correlationId, $result['job']['lock_version']);
    }
}
