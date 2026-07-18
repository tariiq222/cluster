<?php

namespace Modules\Tasks\Features\CompleteTask\Handler;

use Illuminate\Support\Facades\DB;
use Modules\Workflow\Contracts\AdvanceWorkflowStep;
use Shared\Contracts\TransactionalOutbox;

final class CompleteTaskHandler
{
    public function __construct(
        private readonly AdvanceWorkflowStep $workflow,
        private readonly TransactionalOutbox $outbox,
    ) {}

    /** @return array{task: array<string, mixed>, workflow: array<string, mixed>|null, idempotent: bool} */
    public function handle(string $taskId, string $actorUserId): array
    {
        return DB::transaction(function () use ($taskId, $actorUserId): array {
            $task = DB::table('tasks')->where('id', $taskId)->lockForUpdate()->first();
            if ($task === null) {
                throw new \InvalidArgumentException('Task not found.');
            }
            if ($task->status === 'completed') {
                return ['task' => (array) $task, 'workflow' => null, 'idempotent' => true];
            }
            $now = now();
            DB::table('tasks')->where('id', $taskId)->update([
                'status' => 'completed', 'completed_at' => $now,
                'lock_version' => ((int) $task->lock_version) + 1, 'updated_at' => $now,
            ]);
            $this->outbox->append($this->eventId('task.completed.v1', $taskId), $taskId, 'task.completed.v1', [
                'task_id' => $taskId, 'workflow_step_id' => $task->workflow_step_id, 'actor_user_id' => $actorUserId,
            ]);
            $workflow = $task->workflow_step_id === null
                ? null
                : $this->workflow->taskCompleted((string) $task->workflow_step_id, $taskId, $actorUserId);

            return [
                'task' => (array) DB::table('tasks')->where('id', $taskId)->first(),
                'workflow' => $workflow,
                'idempotent' => false,
            ];
        });
    }

    private function eventId(string $type, string $id): string
    {
        $hex = substr(hash('sha256', $type.':'.$id), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-7'.substr($hex, 13, 3).'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
