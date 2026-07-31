<?php

declare(strict_types=1);

namespace Modules\Tasks\Application;

use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;
use stdClass;

final class TaskAuthorizationFacts implements LinkedResourceAuthorizationFacts
{
    public function resolve(DocumentSourceReference $reference): ?RecordFacts
    {
        if ($reference->sourceModule !== 'tasks' || $reference->sourceType !== 'task') {
            return null;
        }
        $task = DB::table('tasks')->where('id', $reference->sourceId)->first();
        if (! $task instanceof stdClass) {
            return null;
        }

        return new RecordFacts(
            (string) $task->owner_organization_unit_id,
            'task',
            (string) $task->classification,
            recordId: (string) $task->id,
            createdByUserId: (string) $task->created_by_user_id,
            lifecycleState: (string) $task->status,
            lockVersion: (int) $task->lock_version,
        );
    }
}
