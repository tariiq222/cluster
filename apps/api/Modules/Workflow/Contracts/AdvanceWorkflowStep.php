<?php

namespace Modules\Workflow\Contracts;

interface AdvanceWorkflowStep
{
    /** @return array{step_id: string, instance_id: string, state: string, instance_state: string} */
    public function taskCompleted(string $stepId, string $taskId, string $actorUserId): array;
}
