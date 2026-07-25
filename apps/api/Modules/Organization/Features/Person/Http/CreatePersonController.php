<?php

namespace Modules\Organization\Features\Person\Http;

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
use Modules\Organization\Features\Person\Handler\PersonHandler;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class CreatePersonController
{
    private const OPERATION = 'registerPerson';

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly PersonHandler $handler,
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
        if (! $this->access->decide($principal, 'organization.person.manage', new RecordFacts(
            ownerFacilityId: $principal['facility_id'],
            resourceType: 'organization_person',
            classification: 'confidential',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'employee_number' => ['required', 'string', 'min:1', 'max:64'],
            'display_name_ar' => ['required', 'string', 'min:1', 'max:255'],
            'display_name_en' => ['sometimes', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:active,suspended,left'],
        ]);
        if ($validator->fails() || array_diff(array_keys($input), ['employee_number', 'display_name_ar', 'display_name_en', 'status']) !== []) {
            return OrganizationApi::problem(400, 'invalid-person', 'Bad Request', 'The Person payload is invalid.', $correlationId);
        }
        $validated = $validator->validated();
        $semantics = [
            'employee_number' => $validated['employee_number'],
            'display_name_ar' => $validated['display_name_ar'],
            'display_name_en' => $validated['display_name_en'] ?? null,
            'status' => $validated['status'],
        ];
        $idempotency = [
            'principal_id' => $principal['user_id'],
            'operation' => self::OPERATION,
            'key_hash' => hash('sha256', $idempotencyKey),
            'request_hash' => hash('sha256', json_encode($semantics, JSON_THROW_ON_ERROR)),
        ];

        try {
            $personId = Str::uuid7()->toString();
            $result = $this->handler->create(
                $personId,
                $semantics,
                $idempotency,
                function (array $person) use ($correlationId, $principal): array {
                    $eventPerson = $this->eventPerson($person);
                    $subject = '/organization/people/'.$person['id'];

                    return [
                        OrganizationApi::cloudEventData(
                            'com.cluster.organization.personregistered.v1',
                            $subject,
                            $correlationId,
                            $principal['facility_id'],
                            $principal,
                            ['person' => $eventPerson],
                            'confidential',
                        ),
                        OrganizationApi::cloudEventData(
                            'com.cluster.organization.identityprovisioningrequested.v1',
                            $subject,
                            $correlationId,
                            $principal['facility_id'],
                            $principal,
                            [
                                'person_id' => $person['id'],
                                'person_version' => $person['person_version'],
                                'requested_account_status' => $person['status'] === 'active' ? 'pending' : 'disabled',
                            ],
                            'confidential',
                        ),
                    ];
                },
            );
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-person', 'Bad Request', 'The Person payload is invalid.', $correlationId);
        } catch (DomainException) {
            return OrganizationApi::problem(409, 'person-already-exists', 'Conflict', 'A Person with this employee number already exists.', $correlationId);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return OrganizationApi::problem(409, 'person-already-exists', 'Conflict', 'A Person with this employee number already exists.', $correlationId);
            }

            return OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        } catch (UnexpectedValueException) {
            return OrganizationApi::problem(500, 'idempotency-state-unavailable', 'Internal Server Error', 'The request cannot be safely replayed.', $correlationId);
        }

        if (! $result['request_hash_matches']) {
            return OrganizationApi::problem(409, 'idempotency-conflict', 'Conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }

        return OrganizationApi::data($result['person'], 201, $correlationId, $result['person']['person_version']);
    }

    /** @param array<string, mixed> $person */
    private function eventPerson(array $person): array
    {
        return [
            'person_id' => $person['id'],
            'person_version' => $person['person_version'],
            'status' => $person['status'],
        ];
    }
}
