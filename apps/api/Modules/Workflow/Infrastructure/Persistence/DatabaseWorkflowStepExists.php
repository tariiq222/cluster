<?php

namespace Modules\Workflow\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Workflow\Contracts\WorkflowStepExists;

final class DatabaseWorkflowStepExists implements WorkflowStepExists
{
    public function exists(string $stepId): bool
    {
        return DB::table('workflow_step_instances')->where('id', $stepId)->exists();
    }
}
