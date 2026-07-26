<?php

namespace Modules\Workflow\Contracts;

/**
 * Read-only existence check owned by Workflow. Other modules ask through this
 * contract instead of reaching into workflow_step_instances directly.
 */
interface WorkflowStepExists
{
    public function exists(string $stepId): bool;
}
