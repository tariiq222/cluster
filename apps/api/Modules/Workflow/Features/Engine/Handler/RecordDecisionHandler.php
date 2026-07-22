<?php

namespace Modules\Workflow\Features\Engine\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Shared\Contracts\TransactionalOutbox;

final class RecordDecisionHandler
{
    public function __construct(private readonly TransactionalOutbox $outbox) {}

    /** @return array<string, mixed> */
    public function record(string $stepId, string $decision, ?string $reason, string $actorUserId, ?string $correlationId = null): array
    {
        return DB::transaction(function () use ($stepId, $decision, $reason, $actorUserId, $correlationId): array {
            $step = DB::table('workflow_step_instances')->where('id', $stepId)->lockForUpdate()->first();
            if ($step === null) {
                throw new \LogicException('Workflow step not found.');
            }
            $existing = DB::table('workflow_decisions')->where('workflow_step_id', $stepId)->first();
            if ($existing !== null) {
                return (array) $existing;
            }
            $now = now();
            $id = Str::uuid7()->toString();
            DB::table('workflow_decisions')->insert([
                'id' => $id,
                'workflow_step_id' => $stepId,
                'workflow_instance_id' => $step->workflow_instance_id,
                'decision' => substr($decision, 0, 16),
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
                'correlation_id' => $correlationId,
                'decided_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->outbox->append(Str::uuid7()->toString(), (string) $step->workflow_instance_id, 'workflow.step.decision.recorded.v1', [
                'workflow_step_id' => $stepId,
                'workflow_instance_id' => (string) $step->workflow_instance_id,
                'decision' => $decision,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
                'correlation_id' => $correlationId,
            ]);

            return (array) DB::table('workflow_decisions')->where('id', $id)->first();
        });
    }
}
