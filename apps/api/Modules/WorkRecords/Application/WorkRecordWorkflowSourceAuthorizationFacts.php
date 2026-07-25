<?php

namespace Modules\WorkRecords\Application;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Workflow\Contracts\ResolveWorkflowSourceAuthorizationFacts;
use Modules\Workflow\Contracts\WorkflowSourceReference;

/** App-level adapter: WorkRecords owns this persistence lookup, not Workflow. */
final class WorkRecordWorkflowSourceAuthorizationFacts implements ResolveWorkflowSourceAuthorizationFacts
{
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

        $facts = [];
        foreach (DB::table('work_records')->whereIn('id', array_keys($referencesById))->get() as $record) {
            $recordFacts = new RecordFacts(
                ownerFacilityId: (string) $record->owner_facility_id,
                resourceType: 'work_record',
                classification: (string) $record->classification,
                fieldPolicyKey: isset($record->field_policy_key) && is_string($record->field_policy_key)
                    ? $record->field_policy_key
                    : null,
            );
            foreach ($referencesById[(string) $record->id] as $reference) {
                $facts[$reference->key()] = $recordFacts;
            }
        }

        return $facts;
    }
}
