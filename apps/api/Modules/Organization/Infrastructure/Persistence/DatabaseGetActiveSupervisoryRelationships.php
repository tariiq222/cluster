<?php

namespace Modules\Organization\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;

final class DatabaseGetActiveSupervisoryRelationships implements GetActiveSupervisoryRelationships
{
    /** @var list<'direct'|'functional'|'coordination'|'read_only'> */
    private const TYPES = ['direct', 'functional', 'coordination', 'read_only'];

    public function forSourceOrganizationUnit(string $sourceOrganizationUnitId): array
    {
        $at = CarbonImmutable::now('UTC')->floorMillisecond();
        $rows = DB::table('supervisory_relationships as relationship')
            ->leftJoin(
                'relationship_capabilities as capability',
                'capability.supervisory_relationship_id',
                '=',
                'relationship.id',
            )
            ->where('relationship.source_organization_unit_id', $sourceOrganizationUnitId)
            ->where('relationship.valid_from', '<=', $this->databaseTimestamp($at))
            ->where('relationship.valid_until', '>', $this->databaseTimestamp($at))
            ->orderBy('relationship.id')
            ->orderBy('capability.module_code')
            ->orderBy('capability.capability_code')
            ->get([
                'relationship.id',
                'relationship.source_organization_unit_id',
                'relationship.target_organization_unit_id',
                'relationship.relationship_type',
                'relationship.valid_from',
                'relationship.valid_until',
                'capability.id as relationship_capability_id',
                'capability.module_code',
                'capability.capability_code',
            ]);

        /** @var array<string, array{supervisory_relationship_id: string, source_organization_unit_id: string, target_organization_unit_id: string, relationship_type: 'direct'|'functional'|'coordination'|'read_only', valid_from: string, valid_until: string, relationship_capabilities: list<array{relationship_capability_id: string, module_code: string, capability_code: string}>}> $facts */
        $facts = [];
        foreach ($rows as $row) {
            $type = (string) $row->relationship_type;
            if (! in_array($type, self::TYPES, true)) {
                continue;
            }
            $id = (string) $row->id;
            if (! isset($facts[$id])) {
                $facts[$id] = [
                    'supervisory_relationship_id' => $id,
                    'source_organization_unit_id' => (string) $row->source_organization_unit_id,
                    'target_organization_unit_id' => (string) $row->target_organization_unit_id,
                    'relationship_type' => $type,
                    'valid_from' => CarbonImmutable::parse((string) $row->valid_from)->utc()->format('Y-m-d\TH:i:s.v\Z'),
                    'valid_until' => CarbonImmutable::parse((string) $row->valid_until)->utc()->format('Y-m-d\TH:i:s.v\Z'),
                    'relationship_capabilities' => [],
                ];
            }
            if ($row->relationship_capability_id !== null) {
                $facts[$id]['relationship_capabilities'][] = [
                    'relationship_capability_id' => (string) $row->relationship_capability_id,
                    'module_code' => (string) $row->module_code,
                    'capability_code' => (string) $row->capability_code,
                ];
            }
        }

        return array_values($facts);
    }

    private function databaseTimestamp(CarbonImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.v');
    }
}
