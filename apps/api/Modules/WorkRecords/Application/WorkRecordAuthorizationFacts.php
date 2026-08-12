<?php

namespace Modules\WorkRecords\Application;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;

final class WorkRecordAuthorizationFacts implements LinkedResourceAuthorizationFacts
{
    public function __construct(
        private readonly WorkRecordResourceFacts $builder,
    ) {}

    public function resolve(DocumentSourceReference $reference): ?RecordFacts
    {
        if ($reference->sourceModule !== 'work-records' || $reference->sourceType !== 'work_record') {
            return null;
        }
        $record = DB::table('work_records')->where('id', $reference->sourceId)->first();

        return $record === null ? null : $this->builder->forRecord($record);
    }
}
