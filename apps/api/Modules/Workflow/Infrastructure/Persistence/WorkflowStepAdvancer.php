<?php

namespace Modules\Workflow\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Workflow\Contracts\AdvanceWorkflowStep;
use Modules\Workflow\Features\Engine\Handler\AdvanceAfterDecision;
use Shared\Contracts\TransactionalOutbox;

final class WorkflowStepAdvancer implements AdvanceWorkflowStep
{
    public function __construct(
        private readonly TransactionalOutbox $outbox,
        private readonly AdvanceAfterDecision $advancer,
    ) {}

    /**
     * Failure after the step state update MUST roll the update back
     * together with the outbox append — the engine emits
     * `workflow.step.completed.v1` exactly once per step completion, and
     * downstream relays dedupe on the shared `outbox_events` table.
     */
    public function taskCompleted(string $stepId, string $taskId, string $actorUserId): array
    {
        return DB::transaction(function () use ($stepId, $taskId, $actorUserId): array {
            $step = DB::table('workflow_step_instances')->where('id', $stepId)->lockForUpdate()->first();
            if ($step === null) {
                return ['step_id' => $stepId, 'instance_id' => '', 'state' => 'unknown', 'instance_state' => 'unknown'];
            }
            $instance = DB::table('workflow_instances')->where('id', $step->workflow_instance_id)->lockForUpdate()->first();
            if ($step->state === 'completed') {
                return ['step_id' => $stepId, 'instance_id' => $step->workflow_instance_id, 'state' => 'completed', 'instance_state' => (string) $instance->state];
            }
            $now = now();
            DB::table('workflow_step_instances')->where('id', $stepId)->update([
                'state' => 'completed', 'task_id' => $taskId, 'completed_at' => $now,
                'lock_version' => ((int) $step->lock_version) + 1, 'updated_at' => $now,
            ]);
            $this->advancer->advance($step->workflow_instance_id, $stepId, $actorUserId);
            $this->outbox->append($this->eventId('workflow.step.completed.v1', $stepId), $step->workflow_instance_id, 'workflow.step.completed.v1', [
                'workflow_step_id' => $stepId, 'workflow_instance_id' => $step->workflow_instance_id, 'task_id' => $taskId, 'actor_user_id' => $actorUserId,
            ]);
            $instanceState = (string) DB::table('workflow_instances')->where('id', $step->workflow_instance_id)->value('state');

            return ['step_id' => $stepId, 'instance_id' => $step->workflow_instance_id, 'state' => 'completed', 'instance_state' => $instanceState];
        });
    }

    private function eventId(string $type, string $id): string
    {
        $hex = substr(hash('sha256', $type.':'.$id), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-7'.substr($hex, 13, 3).'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
