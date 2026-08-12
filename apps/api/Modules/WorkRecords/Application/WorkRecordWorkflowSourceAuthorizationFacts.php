<?php

namespace Modules\WorkRecords\Application;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\Workflow\Contracts\ResolveWorkflowSourceAuthorizationFacts;
use Modules\Workflow\Contracts\WorkflowSourceReference;

/** App-level adapter: WorkRecords owns this persistence lookup, not Workflow. */
final class WorkRecordWorkflowSourceAuthorizationFacts implements ResolveWorkflowSourceAuthorizationFacts
{
    public function __construct(
        private readonly WorkRecordResourceFacts $builder,
        private readonly ResolveOrganizationScopeAncestry $ancestry,
    ) {}

    public function resolve(WorkflowSourceReference $reference): ?RecordFacts
    {
        return $this->resolveMany([$reference])[$reference->key()] ?? null;
    }

    /** @return array<string, RecordFacts> */
    public function resolveMany(array $references): array
    {
        $workRecordReferences = array_values(array_filter(
            $references,
            static fn (WorkflowSourceReference $reference): bool => in_array($reference->sourceModule, ['work_records', 'work-records'], true)
                && $reference->sourceType === 'work_record',
        ));
        if ($workRecordReferences === []) {
            return [];
        }

        $referencesById = [];
        foreach ($workRecordReferences as $reference) {
            $referencesById[$reference->sourceId][] = $reference;
        }

        $records = DB::table('work_records')->whereIn('id', array_keys($referencesById))->get();
        $facilityIds = $records
            ->pluck('owner_facility_id')
            ->filter(static fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
        $clusterIdsByFacility = $this->ancestry->facilityClusterIds($facilityIds);

        $facts = [];
        foreach ($records as $record) {
            $recordFacts = $this->builder->forRecord($record, $clusterIdsByFacility);
            foreach ($referencesById[(string) $record->id] as $reference) {
                $facts[$reference->key()] = $recordFacts;
            }
        }

        return $facts;
    }
}
