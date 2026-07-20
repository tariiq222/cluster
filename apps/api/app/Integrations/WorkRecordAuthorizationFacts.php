<?php

namespace App\Integrations;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Application\DocumentSourceReference;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;

final class WorkRecordAuthorizationFacts implements LinkedResourceAuthorizationFacts
{
    public function resolve(DocumentSourceReference $reference): ?RecordFacts
    {
        if ($reference->sourceModule !== 'work-records' || $reference->sourceType !== 'work_record') {
            return null;
        }
        $record = DB::table('work_records')->where('id', $reference->sourceId)->first();

        return $record === null ? null : new RecordFacts(
            (string) $record->owner_facility_id,
            'work_record',
            (string) $record->classification,
            fieldPolicyKey: isset($record->field_policy_key) && is_string($record->field_policy_key)
                ? $record->field_policy_key
                : null,
        );
    }
}
