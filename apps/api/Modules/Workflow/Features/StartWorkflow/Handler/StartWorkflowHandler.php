<?php

namespace Modules\Workflow\Features\StartWorkflow\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Modules\Workflow\Features\Engine\Handler\AdvanceAfterDecision;
use Shared\Contracts\TransactionalOutbox;

final class StartWorkflowHandler
{
    public function __construct(
        private readonly TransactionalOutbox $outbox,
        private readonly ?AdvanceAfterDecision $advancer = null,
    ) {}

    /** @return array<string, mixed> */
    public function start(string $workflowVersionId, string $sourceModule, string $sourceType, string $sourceId, string $actorUserId): array
    {
        return DB::transaction(function () use ($workflowVersionId, $sourceModule, $sourceType, $sourceId, $actorUserId): array {
            $version = DB::table('workflow_versions')->where('id', $workflowVersionId)->first();
            if ($version === null || $version->definition_state !== 'published') {
                throw new LogicException('A workflow instance requires a published workflow version.');
            }

            $existing = DB::table('workflow_instances')
                ->where('source_module', $sourceModule)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('workflow_version_id', $workflowVersionId)
                ->first();
            if ($existing !== null) {
                return (array) $existing;
            }

            $now = now();
            $instanceId = Str::uuid7()->toString();
            DB::table('workflow_instances')->insert([
                'id' => $instanceId,
                'workflow_version_id' => $workflowVersionId,
                'source_module' => $sourceModule,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'state' => 'running',
                'started_by_user_id' => $actorUserId,
                'started_at' => $now,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $graph = json_decode((string) $version->graph_document, true, 512, JSON_THROW_ON_ERROR);
            $taskNodes = collect($graph['nodes'] ?? $graph)
                ->filter(fn (mixed $node): bool => is_array($node) && in_array(($node['type'] ?? null), ['task', 'approval', 'decision'], true));
            if ($taskNodes->count() > 1 && $this->advancer !== null) {
                // Multi-step graphs walk the linear transition chain via the engine
                // so the first step, its assignee, and any rule resolution stay in
                // one transaction with the same idempotency guarantees.
                $this->advancer->fromStart($instanceId, $actorUserId);
            } else {
                $taskNode = $taskNodes->first();
                if (is_array($taskNode)) {
                    DB::table('workflow_step_instances')->insert([
                        'id' => Str::uuid7()->toString(),
                        'workflow_instance_id' => $instanceId,
                        'node_key' => (string) ($taskNode['key'] ?? 'task'),
                        'node_type' => (string) ($taskNode['type'] ?? 'task'),
                        'state' => 'waiting',
                        'activation_sequence' => 1,
                        'assignee_user_id' => $this->assignee($taskNode, $actorUserId),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
            $this->outbox->append(Str::uuid7()->toString(), $instanceId, 'workflow.instance.started.v1', [
                'workflow_instance_id' => $instanceId,
                'workflow_version_id' => $workflowVersionId,
                'source_module' => $sourceModule,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);

            return (array) DB::table('workflow_instances')->where('id', $instanceId)->first();
        });
    }

    /**
     * Who owns the step. The graph may name the approver outright; until the
     * assignment rules resolve `supervisor_of_initiator` and friends against the
     * organisation tree, the starter keeps ownership so no step is left orphaned.
     *
     * @param  array<string, mixed>  $node
     */
    private function assignee(array $node, string $actorUserId): string
    {
        $named = $node['assignee_user_id'] ?? ($node['configuration']['assignee_user_id'] ?? null);

        return is_string($named) && $named !== '' ? $named : $actorUserId;
    }
}
