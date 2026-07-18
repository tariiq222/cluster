<?php

namespace Modules\Workflow\Features\StartWorkflow\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Shared\Contracts\TransactionalOutbox;

final class StartWorkflowHandler
{
    public function __construct(private readonly TransactionalOutbox $outbox) {}

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
            $taskNode = collect($graph['nodes'] ?? $graph)->first(fn (mixed $node): bool => is_array($node) && ($node['type'] ?? null) === 'task');
            if (is_array($taskNode)) {
                DB::table('workflow_step_instances')->insert([
                    'id' => Str::uuid7()->toString(),
                    'workflow_instance_id' => $instanceId,
                    'node_key' => (string) ($taskNode['key'] ?? 'task'),
                    'node_type' => 'task',
                    'state' => 'waiting',
                    'activation_sequence' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
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
}
