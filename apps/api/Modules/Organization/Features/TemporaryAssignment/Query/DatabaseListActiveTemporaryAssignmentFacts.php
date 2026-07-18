<?php

namespace Modules\Organization\Features\TemporaryAssignment\Query;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\ListActiveTemporaryAssignmentFacts;
use Modules\Organization\Features\TemporaryAssignment\Contracts\ValidateTemporaryAssignmentCapabilities;
use Throwable;

final class DatabaseListActiveTemporaryAssignmentFacts implements ListActiveTemporaryAssignmentFacts
{
    public function __construct(private readonly ValidateTemporaryAssignmentCapabilities $capabilities) {}

    public function forPerson(string $personId): array
    {
        $at = CarbonImmutable::now('UTC')->floorMillisecond();
        $rows = DB::table('temporary_assignments as assignment')
            ->join(
                'temporary_assignment_capabilities as capability',
                'capability.temporary_assignment_id',
                '=',
                'assignment.id',
            )
            ->where('assignment.person_id', $personId)
            ->where('assignment.state', '!=', 'revoked')
            ->where('assignment.start_at', '<=', $this->databaseTimestamp($at))
            ->where('assignment.end_at', '>', $this->databaseTimestamp($at))
            ->orderBy('assignment.id')
            ->orderBy('capability.capability_code')
            ->get([
                'assignment.id',
                'assignment.person_id',
                'assignment.organization_unit_id',
                'assignment.start_at',
                'assignment.end_at',
                'capability.capability_code',
            ]);

        /** @var array<string, array{temporary_assignment_id: string, person_id: string, organization_unit_id: string, capability_codes: list<string>, valid_from: string, valid_until: string}> $facts */
        $facts = [];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            if (! isset($facts[$id])) {
                $facts[$id] = [
                    'temporary_assignment_id' => $id,
                    'person_id' => (string) $row->person_id,
                    'organization_unit_id' => (string) $row->organization_unit_id,
                    'capability_codes' => [],
                    'valid_from' => CarbonImmutable::parse((string) $row->start_at)->utc()->format('Y-m-d\TH:i:s.v\Z'),
                    'valid_until' => CarbonImmutable::parse((string) $row->end_at)->utc()->format('Y-m-d\TH:i:s.v\Z'),
                ];
            }
            $facts[$id]['capability_codes'][] = (string) $row->capability_code;
        }

        foreach ($facts as $id => $fact) {
            try {
                $allAreActive = $this->capabilities->allAreActive($fact['capability_codes']);
            } catch (Throwable) {
                return [];
            }
            if (! $allAreActive) {
                unset($facts[$id]);
            }
        }

        return array_values($facts);
    }

    private function databaseTimestamp(CarbonImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.v');
    }
}
