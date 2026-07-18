<?php

namespace Modules\Tasks\Features\CreateTaskFromWorkflowStep\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Shared\Contracts\TransactionalOutbox;

final class CreateTaskFromWorkflowStepHandler
{
    public function __construct(private readonly TransactionalOutbox $outbox) {}

    /** @param array<string, mixed> $step @return array<string, mixed> */
    public function handle(array $step, string $principalId, ?string $title = null): array
    {
        $stepId = (string) ($step['step_id'] ?? $step['id'] ?? '');
        if ($stepId === '') {
            throw new \InvalidArgumentException('A workflow step id is required.');
        }

        return DB::transaction(function () use ($step, $stepId, $principalId, $title): array {
            $existing = DB::table('tasks')->where('workflow_step_id', $stepId)->first();
            if ($existing !== null) {
                return (array) $existing;
            }
            $now = now();
            $taskId = Str::uuid7()->toString();
            DB::table('tasks')->insert([
                'id' => $taskId,
                'title' => $title ?? (string) ($step['title'] ?? 'Workflow task'),
                'description' => $step['description'] ?? null,
                'created_by_user_id' => $principalId,
                'assignee_user_id' => (string) ($step['assignee_user_id'] ?? $principalId),
                'owner_organization_unit_id' => $step['owner_organization_unit_id'] ?? null,
                'status' => 'open',
                'priority' => 'normal',
                'classification' => 'internal',
                'completion_policy' => 'direct',
                'source_module' => $step['source_module'] ?? 'workflow',
                'source_type' => $step['source_type'] ?? 'workflow_step',
                'source_id' => $step['source_id'] ?? $stepId,
                'workflow_step_id' => $stepId,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->outbox->append(Str::uuid7()->toString(), $taskId, 'task.created.v1', [
                'task_id' => $taskId, 'workflow_step_id' => $stepId, 'assignee_user_id' => (string) ($step['assignee_user_id'] ?? $principalId),
            ]);

            return (array) DB::table('tasks')->where('id', $taskId)->first();
        });
    }
}
