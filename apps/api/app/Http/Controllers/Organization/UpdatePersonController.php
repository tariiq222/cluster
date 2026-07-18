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
use Modules\Organization\Features\Person\Handler\PersonHandler;
use Modules\Organization\Http\OrganizationApi;
use UnexpectedValueException;

final class UpdatePersonController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $principalResolver,
        private readonly DecideAccess $access,
        private readonly PersonHandler $handler,
    ) {}

    public function __invoke(Request $request, string $personId): JsonResponse
    {
        $correlationId = OrganizationApi::correlationId($request);
        if ($correlationId === null) {
            return OrganizationApi::problem(400, 'invalid-correlation-id', 'Bad Request', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        if (! OrganizationApi::isUuidV7($personId)) {
            return OrganizationApi::problem(400, 'invalid-person-id', 'Bad Request', 'personId must be a lowercase UUIDv7.', $correlationId);
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
        if (! $this->access->decide($principal, 'organization.person.manage', new RecordFacts(
            ownerFacilityId: null,
            resourceType: 'organization_person',
            classification: 'confidential',
        ))->isAllowed()) {
            return OrganizationApi::problem(403, 'access-denied', 'Forbidden', 'Access denied.', $correlationId);
        }

        $input = $request->json()->all();
        $validator = Validator::make($input, [
            'display_name_ar' => ['sometimes', 'required', 'string', 'min:1', 'max:255'],
            'display_name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'string', 'in:active,suspended,left'],
        ]);
        if ($validator->fails() || $input === [] || array_diff(array_keys($input), ['display_name_ar', 'display_name_en', 'status']) !== []) {
            return OrganizationApi::problem(400, 'invalid-person', 'Bad Request', 'The Person patch is invalid.', $correlationId);
        }
        $changes = $validator->validated();

        try {
            $person = $this->handler->update(
                $personId,
                $expectedVersion,
                $changes,
                function (array $person, string $previousStatus) use ($correlationId, $principal): array {
                    $subject = '/organization/people/'.$person['id'];
                    $eventPerson = [
                        'person_id' => $person['id'],
                        'person_version' => $person['person_version'],
                        'status' => $person['status'],
                    ];
                    $events = [OrganizationApi::cloudEventData(
                        'com.cluster.organization.personupdated.v1',
                        $subject,
                        $correlationId,
                        $principal['facility_id'],
                        $principal,
                        ['person' => $eventPerson, 'previous_status' => $previousStatus],
                        'confidential',
                    )];
                    if ($person['status'] !== $previousStatus) {
                        $events[] = OrganizationApi::cloudEventData(
                            'com.cluster.organization.personaccessstatuschanged.v1',
                            $subject,
                            $correlationId,
                            $principal['facility_id'],
                            $principal,
                            [
                                'person_id' => $person['id'],
                                'person_version' => $person['person_version'],
                                'access_status' => $person['status'],
                            ],
                            'confidential',
                        );
                    }

                    return $events;
                },
            );
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'person_not_found' => OrganizationApi::problem(404, 'person-not-found', 'Not Found', 'The Person is not available.', $correlationId),
                'precondition_failed' => OrganizationApi::problem(412, 'precondition-failed', 'Precondition Failed', 'If-Match does not match the current Person version.', $correlationId),
                default => OrganizationApi::problem(409, 'person-conflict', 'Conflict', 'The Person cannot be updated.', $correlationId),
            };
        } catch (InvalidArgumentException) {
            return OrganizationApi::problem(400, 'invalid-person', 'Bad Request', 'The Person patch does not change the profile.', $correlationId);
        } catch (UnexpectedValueException|QueryException) {
            return OrganizationApi::problem(500, 'organization-write-failed', 'Internal Server Error', 'The organization change could not be saved.', $correlationId);
        }

        return OrganizationApi::data($person, 200, $correlationId, $person['person_version']);
    }
}
