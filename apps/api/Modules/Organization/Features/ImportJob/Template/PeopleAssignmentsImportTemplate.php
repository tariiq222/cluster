<?php

namespace Modules\Organization\Features\ImportJob\Template;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Organization\Features\Assignment\Handler\AssignmentHandler;
use Modules\Organization\Features\Person\Handler\PersonHandler;
use stdClass;

final readonly class PeopleAssignmentsImportTemplate implements GovernedImportTemplate
{
    use ValidatesImportRows;

    public function __construct(
        private PersonHandler $people,
        private AssignmentHandler $assignments,
    ) {}

    public function validate(array $payload): array
    {
        $errors = $this->missingRequired(['employee_number', 'display_name_ar', 'status', 'position_id', 'start_at'], $payload);
        if ($errors !== []) {
            return $errors;
        }
        if (! in_array($payload['status'], ['active', 'suspended', 'left'], true)) {
            $errors[] = ['code' => 'invalid_status', 'severity' => 'error', 'field' => 'status'];
        } elseif ($payload['status'] !== 'active') {
            $errors[] = ['code' => 'person_not_assignable', 'severity' => 'critical', 'field' => 'status'];
        }
        if (mb_strlen($payload['employee_number']) > 64) {
            $errors[] = ['code' => 'invalid_employee_number', 'severity' => 'error', 'field' => 'employee_number'];
        }
        if (mb_strlen($payload['display_name_ar']) > 255) {
            $errors[] = ['code' => 'invalid_display_name', 'severity' => 'error', 'field' => 'display_name_ar'];
        }
        if (array_key_exists('display_name_en', $payload)
            && $payload['display_name_en'] !== null
            && (! is_string($payload['display_name_en']) || mb_strlen($payload['display_name_en']) > 255)) {
            $errors[] = ['code' => 'invalid_display_name', 'severity' => 'error', 'field' => 'display_name_en'];
        }
        if (array_key_exists('is_primary', $payload) && ! is_bool($payload['is_primary'])) {
            $errors[] = ['code' => 'invalid_is_primary', 'severity' => 'error', 'field' => 'is_primary'];
        }
        if (! $this->isUuidV7($payload['position_id']) || ! $this->activePositionInActiveUnitExists($payload['position_id'])) {
            $errors[] = ['code' => 'invalid_position', 'severity' => 'error', 'field' => 'position_id'];
        }
        $person = DB::table('people')->where('employee_number', $payload['employee_number'])->first();
        if ($person instanceof stdClass && $person->status !== 'active') {
            $errors[] = ['code' => 'person_not_assignable', 'severity' => 'critical', 'field' => 'employee_number'];
        }
        $endAt = $payload['end_at'] ?? null;
        if (! $this->isUtc($payload['start_at']) || ($endAt !== null && (! is_string($endAt) || ! $this->isUtc($endAt)))) {
            $errors[] = ['code' => 'invalid_period', 'severity' => 'error'];
        } elseif (is_string($endAt)
            && (strtotime($endAt) <= strtotime($payload['start_at']) || strtotime($endAt) <= now('UTC')->getTimestamp())) {
            $errors[] = ['code' => 'invalid_period', 'severity' => 'error'];
        } else {
            if ($this->isUuidV7($payload['position_id']) && $this->positionAssignmentOverlapsExisting($payload)) {
                $errors[] = ['code' => 'position_assignment_overlap', 'severity' => 'critical', 'field' => 'position_id'];
            }
            if (($payload['is_primary'] ?? true) === true && $person instanceof stdClass && $this->primaryAssignmentOverlapsExisting((string) $person->id, $payload)) {
                $errors[] = ['code' => 'primary_assignment_overlap', 'severity' => 'critical', 'field' => 'employee_number'];
            }
        }

        return $errors;
    }

    public function validateBatch(array $payload, ImportBatchContext $context, int $rowNumber): array
    {
        return array_map(
            static fn (string $code): array => ['code' => $code, 'severity' => 'critical'],
            $context->peopleAssignmentConflictCodes($rowNumber),
        );
    }

    public function apply(string $rowId, array $payload, string $principalId, Closure $eventFactory, Closure $idempotencyFactory): string
    {
        $person = DB::table('people')->where('employee_number', $payload['employee_number'])->lockForUpdate()->first();
        if (! $person instanceof stdClass) {
            $personId = Str::uuid7()->toString();
            $personResult = $this->people->create(
                $personId,
                [
                    'employee_number' => $payload['employee_number'],
                    'display_name_ar' => $payload['display_name_ar'],
                    'display_name_en' => $payload['display_name_en'] ?? null,
                    'status' => $payload['status'],
                ],
                $idempotencyFactory('importPerson:'.$rowId, $payload),
                fn (array $created): array => [
                    $eventFactory(
                        'com.cluster.organization.personregistered.v1',
                        '/organization/people/'.$created['id'],
                        ['person' => [
                            'person_id' => $created['id'],
                            'person_version' => $created['person_version'],
                            'status' => $created['status'],
                        ]],
                        'confidential',
                    ),
                    $eventFactory(
                        'com.cluster.organization.identityprovisioningrequested.v1',
                        '/organization/people/'.$created['id'],
                        [
                            'person_id' => $created['id'],
                            'person_version' => $created['person_version'],
                            'requested_account_status' => $created['status'] === 'active' ? 'pending' : 'disabled',
                        ],
                        'confidential',
                    ),
                ],
            );
            $personId = $personResult['person']['id'];
        } else {
            $personId = (string) $person->id;
        }
        $this->assignments->create(
            Str::uuid7()->toString(),
            [
                'person_id' => $personId,
                'position_id' => $payload['position_id'],
                'start_at' => $payload['start_at'],
                'end_at' => $payload['end_at'] ?? null,
                'is_primary' => $payload['is_primary'] ?? true,
            ],
            $idempotencyFactory('importAssignment:'.$rowId, $payload),
            fn (array $assignment): array => $eventFactory(
                'com.cluster.organization.assignmentstarted.v1',
                '/organization/assignments/'.$assignment['id'],
                ['assignment' => $assignment],
                'internal',
            ),
        );

        return $personId;
    }

    /** @param array<string, mixed> $payload */
    private function positionAssignmentOverlapsExisting(array $payload): bool
    {
        return $this->periodQuery(DB::table('assignments')->where('position_id', $payload['position_id']), $payload)->exists();
    }

    private function activePositionInActiveUnitExists(string $positionId): bool
    {
        return DB::table('positions')
            ->join('organization_units', 'organization_units.id', '=', 'positions.organization_unit_id')
            ->where('positions.id', $positionId)
            ->where('positions.is_active', true)
            ->where('organization_units.status', 'active')
            ->exists();
    }

    /** @param array<string, mixed> $payload */
    private function primaryAssignmentOverlapsExisting(string $personId, array $payload): bool
    {
        return $this->periodQuery(DB::table('assignments')->where('person_id', $personId)->where('is_primary', true), $payload)->exists();
    }

    /** @param array<string, mixed> $payload */
    private function periodQuery(mixed $query, array $payload): mixed
    {
        return $query
            ->where('start_at', '<', isset($payload['end_at']) && is_string($payload['end_at']) ? $this->databaseTimestamp($payload['end_at']) : '9999-12-31 23:59:59.999')
            ->where(function (mixed $period) use ($payload): void {
                $period->whereNull('end_at')->orWhere('end_at', '>', $this->databaseTimestamp($payload['start_at']));
            });
    }

    private function databaseTimestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
