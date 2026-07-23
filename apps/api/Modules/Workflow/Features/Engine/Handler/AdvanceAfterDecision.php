<?php

namespace Modules\Workflow\Features\Engine\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Workflow\Contracts\RuleContext;
use Modules\Workflow\Contracts\RuleSpec;
use Modules\Workflow\Domain\AssignmentRules;

final class AdvanceAfterDecision
{
    public function __construct(private readonly ?AssignmentRules $rules = null) {}

    /** @return array<string, mixed>|null */
    public function fromStart(string $instanceId, string $actorUserId): ?array
    {
        return DB::transaction(fn (): ?array => $this->advance($instanceId, null, $actorUserId));
    }

    /** @return array<string, mixed>|null */
    public function advance(string $instanceId, ?string $stepId, string $actorUserId): ?array
    {
        $instance = DB::table('workflow_instances')->where('id', $instanceId)->lockForUpdate()->first();
        if ($instance === null) {
            return null;
        }
        $version = DB::table('workflow_versions')->where('id', $instance->workflow_version_id)->first();
        if ($version === null) {
            return null;
        }
        $graph = json_decode((string) $version->graph_document, true, 512, JSON_THROW_ON_ERROR);
        $nodes = collect($graph['nodes'] ?? [])->keyBy('key');
        $transitions = collect($graph['transitions'] ?? []);
        $current = $stepId === null ? 'start' : (string) DB::table('workflow_step_instances')->where('id', $stepId)->value('node_key');
        $nextKey = $transitions->firstWhere('from', $current)['to'] ?? null;
        while ($nextKey !== null && (($nodes[$nextKey]['type'] ?? null) === 'start')) {
            $nextKey = $transitions->firstWhere('from', $nextKey)['to'] ?? null;
        }
        if ($nextKey === null || (($nodes[$nextKey]['type'] ?? null) === 'end')) {
            DB::table('workflow_instances')->where('id', $instanceId)->update(['state' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);

            return null;
        }
        $node = $nodes[$nextKey];
        $sequence = ((int) DB::table('workflow_step_instances')->where('workflow_instance_id', $instanceId)->where('node_key', $nextKey)->max('activation_sequence')) + 1;
        $existing = DB::table('workflow_step_instances')->where(['workflow_instance_id' => $instanceId, 'node_key' => $nextKey, 'activation_sequence' => $sequence])->first();
        if ($existing !== null) {
            return (array) $existing;
        }
        $rule = RuleSpec::fromNode($node);
        $assignee = $rule !== null && $this->rules !== null ? $this->rules->resolve(new RuleContext(['workflow_instance_id' => $instanceId, 'initiator_user_id' => $instance->started_by_user_id]), $rule) : ($node['assignee_user_id'] ?? $instance->started_by_user_id);
        $id = Str::uuid7()->toString();
        DB::table('workflow_step_instances')->insert([
            'id' => $id,
            'workflow_instance_id' => $instanceId,
            'node_key' => $nextKey,
            'node_type' => (string) ($node['type'] ?? 'task'),
            'state' => 'waiting',
            'activation_sequence' => max(1, $sequence),
            'assignee_user_id' => is_string($assignee) ? $assignee : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (array) DB::table('workflow_step_instances')->where('id', $id)->first();
    }
}
