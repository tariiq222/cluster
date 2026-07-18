<?php

namespace Modules\Organization\Features\ImportJob\Template;

final class ImportBatchContext
{
    /** @var array<string, int> */
    private array $facilityKeys = [];

    /** @var array<string, int> */
    private array $unitKeys = [];

    /** @var array<string, int> */
    private array $positionKeys = [];

    /** @var array<int, array{employee_number: string, position_id: string, start: int, end: int|null, is_primary: bool}> */
    private array $peopleAssignmentRows = [];

    /** @param list<array<string, mixed>> $rows */
    public static function from(string $templateCode, array $rows): self
    {
        return new self($templateCode, $rows);
    }

    /** @param list<array<string, mixed>> $rows */
    private function __construct(string $templateCode, array $rows)
    {
        foreach ($rows as $offset => $payload) {
            $rowNumber = $offset + 1;
            match ($templateCode) {
                'facilities' => $this->rememberFacility($payload),
                'organization_units' => $this->rememberUnit($payload),
                'positions' => $this->rememberPosition($payload),
                'people_assignments' => $this->rememberPeopleAssignment($rowNumber, $payload),
                default => null,
            };
        }
    }

    public function hasDuplicateFacility(string $clusterId, string $code): bool
    {
        return ($this->facilityKeys[$clusterId.'|'.$code] ?? 0) > 1;
    }

    public function hasDuplicateUnit(string $clusterId, ?string $parentId, string $code): bool
    {
        return ($this->unitKeys[$clusterId.'|'.($parentId ?? $clusterId).'|'.$code] ?? 0) > 1;
    }

    public function hasDuplicatePosition(string $unitId, string $code): bool
    {
        return ($this->positionKeys[$unitId.'|'.$code] ?? 0) > 1;
    }

    /** @return list<string> */
    public function peopleAssignmentConflictCodes(int $rowNumber): array
    {
        $current = $this->peopleAssignmentRows[$rowNumber] ?? null;
        if ($current === null) {
            return [];
        }

        $codes = [];
        foreach ($this->peopleAssignmentRows as $otherRowNumber => $other) {
            if ($otherRowNumber === $rowNumber || ! $this->periodsOverlap($current, $other)) {
                continue;
            }
            if ($current['position_id'] === $other['position_id']) {
                $codes['position_assignment_overlap'] = true;
            }
            if ($current['is_primary'] && $other['is_primary'] && $current['employee_number'] === $other['employee_number']) {
                $codes['primary_assignment_overlap'] = true;
            }
        }

        return array_keys($codes);
    }

    /** @param array<string, mixed> $payload */
    private function rememberFacility(array $payload): void
    {
        if (is_string($payload['cluster_id'] ?? null) && is_string($payload['code'] ?? null)) {
            $key = $payload['cluster_id'].'|'.$payload['code'];
            $this->facilityKeys[$key] = ($this->facilityKeys[$key] ?? 0) + 1;
        }
    }

    /** @param array<string, mixed> $payload */
    private function rememberUnit(array $payload): void
    {
        if (! is_string($payload['cluster_id'] ?? null) || ! is_string($payload['code'] ?? null)) {
            return;
        }
        $parentId = $payload['parent_id'] ?? null;
        if ($parentId !== null && ! is_string($parentId)) {
            return;
        }
        $key = $payload['cluster_id'].'|'.($parentId ?? $payload['cluster_id']).'|'.$payload['code'];
        $this->unitKeys[$key] = ($this->unitKeys[$key] ?? 0) + 1;
    }

    /** @param array<string, mixed> $payload */
    private function rememberPosition(array $payload): void
    {
        if (is_string($payload['organization_unit_id'] ?? null) && is_string($payload['code'] ?? null)) {
            $key = $payload['organization_unit_id'].'|'.$payload['code'];
            $this->positionKeys[$key] = ($this->positionKeys[$key] ?? 0) + 1;
        }
    }

    /** @param array<string, mixed> $payload */
    private function rememberPeopleAssignment(int $rowNumber, array $payload): void
    {
        if (($payload['status'] ?? null) !== 'active'
            || ! is_string($payload['employee_number'] ?? null)
            || ! is_string($payload['position_id'] ?? null)
            || ! is_string($payload['start_at'] ?? null)) {
            return;
        }
        $start = strtotime($payload['start_at']);
        if ($start === false) {
            return;
        }
        $endAt = $payload['end_at'] ?? null;
        if ($endAt !== null && ! is_string($endAt)) {
            return;
        }
        $end = $endAt === null ? null : strtotime($endAt);
        if ($end === false || ($end !== null && $end <= $start)) {
            return;
        }
        $isPrimary = $payload['is_primary'] ?? true;
        if (! is_bool($isPrimary)) {
            return;
        }

        $this->peopleAssignmentRows[$rowNumber] = [
            'employee_number' => $payload['employee_number'],
            'position_id' => $payload['position_id'],
            'start' => $start,
            'end' => $end,
            'is_primary' => $isPrimary,
        ];
    }

    /**
     * @param  array{start: int, end: int|null}  $left
     * @param  array{start: int, end: int|null}  $right
     */
    private function periodsOverlap(array $left, array $right): bool
    {
        return $left['start'] < ($right['end'] ?? PHP_INT_MAX)
            && ($left['end'] ?? PHP_INT_MAX) > $right['start'];
    }
}
