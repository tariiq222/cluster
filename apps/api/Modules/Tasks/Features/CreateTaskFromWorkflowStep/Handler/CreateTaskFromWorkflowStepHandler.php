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
        $stepId = (string) ($step['step_id'] ?? $step['workflow_step_id'] ?? $step['id'] ?? '');

        return DB::transaction(function () use ($step, $stepId, $principalId, $title): array {
            if ($stepId !== '') {
                $existing = DB::table('tasks')->where('workflow_step_id', $stepId)->first();
                if ($existing !== null) {
                    return (array) $existing;
                }
            }
            $now = now();
            $taskId = Str::uuid7()->toString();
            $source = is_array($step['source'] ?? null) ? $step['source'] : [];
            $sourceModule = is_string($source['source_module'] ?? null) ? $source['source_module'] : (is_string($step['source_module'] ?? null) ? $step['source_module'] : null);
            $sourceType = is_string($source['record_type'] ?? null) ? $source['record_type'] : (is_string($step['source_type'] ?? null) ? $step['source_type'] : null);
            $sourceId = is_string($source['record_id'] ?? null) ? $source['record_id'] : (is_string($step['source_id'] ?? null) ? $step['source_id'] : null);
            DB::table('tasks')->insert([
                'id' => $taskId,
                'title' => $title ?? (string) ($step['title'] ?? 'Workflow task'),
                'description' => $step['description'] ?? null,
                'created_by_user_id' => $principalId,
                'assignee_user_id' => (string) ($step['assignee_user_id'] ?? $principalId),
                'owner_organization_unit_id' => $step['owner_organization_unit_id'] ?? null,
                'status' => 'open',
                'due_at' => $step['due_at'] ?? null,
                'priority' => (string) ($step['priority'] ?? 'normal'),
                'classification' => (string) ($step['classification'] ?? 'internal'),
                'completion_policy' => (string) ($step['completion_policy'] ?? 'direct'),
                'source_module' => $sourceModule,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'workflow_step_id' => $stepId !== '' ? $stepId : null,
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
