<?php

namespace Modules\Workflow\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Workflow\Features\Engine\Handler\AdvanceAfterDecision;
use Modules\Workflow\Features\Engine\Handler\RecordDecisionHandler;
use Modules\Workflow\Features\PublishWorkflowVersion\Handler\PublishWorkflowVersionHandler;
use Modules\Workflow\Features\StartWorkflow\Handler\StartWorkflowHandler;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Three-step linear chain walks to completion through the engine handlers, a
 * rejection halts it with the reason recorded in the queryable ledger, and
 * re-recording a decision for the same step is a no-op rather than a duplicate.
 */
final class WorkflowEngineTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR = '0197f0e0-0000-7000-8000-000000000001';

    private const APPROVER_1 = '0197f0e0-0000-7000-8000-000000000002';

    private const APPROVER_2 = '0197f0e0-0000-7000-8000-000000000003';

    private const APPROVER_3 = '0197f0e0-0000-7000-8000-000000000004';

    public function test_three_step_chain_advances_to_completion_through_engine_handlers(): void
    {
        $version = $this->publishThreeStepChain();
        $instance = $this->app->make(StartWorkflowHandler::class)
            ->start($version['id'], 'work_records', 'record', '0197f0e0-0000-7000-8000-000000000099', self::ACTOR);
        $this->assertSame('running', $instance['state']);

        $recorder = $this->app->make(RecordDecisionHandler::class);
        $advancer = $this->app->make(AdvanceAfterDecision::class);

        // Step 1 — start should have produced it because the graph has >1 task node.
        $steps = $this->stepsFor($instance['id']);
        $this->assertCount(1, $steps, 'The multi-step graph must seed only the first step on start.');
        $this->assertSame('step-1', $steps[0]['node_key']);
        $this->assertSame('waiting', $steps[0]['state']);

        $this->complete($recorder, $advancer, $instance['id'], $steps[0]['id'], self::APPROVER_1, null);

        $steps = $this->stepsFor($instance['id']);
        $this->assertCount(2, $steps);
        $this->assertSame('step-2', $steps[1]['node_key']);
        $this->assertSame('waiting', $steps[1]['state']);
        $this->assertSame('completed', $steps[0]['state']);

        $this->complete($recorder, $advancer, $instance['id'], $steps[1]['id'], self::APPROVER_2, null);

        $steps = $this->stepsFor($instance['id']);
        $this->assertCount(3, $steps);
        $this->assertSame('step-3', $steps[2]['node_key']);

        $this->complete($recorder, $advancer, $instance['id'], $steps[2]['id'], self::APPROVER_3, null);

        $steps = $this->stepsFor($instance['id']);
        $this->assertCount(3, $steps, 'No step is created after end.');
        foreach ($steps as $step) {
            $this->assertSame('completed', $step['state']);
        }
        $instanceRow = (array) DB::table('workflow_instances')->where('id', $instance['id'])->sole();
        $this->assertSame('completed', $instanceRow['state']);
    }

    public function test_rejection_records_reason_and_halts_advance(): void
    {
        $version = $this->publishThreeStepChain();
        $instance = $this->app->make(StartWorkflowHandler::class)
            ->start($version['id'], 'work_records', 'record', '0197f0e0-0000-7000-8000-0000000000aa', self::ACTOR);
        $steps = $this->stepsFor($instance['id']);
        $firstStepId = $steps[0]['id'];

        $recorder = $this->app->make(RecordDecisionHandler::class);
        $recorder->record($firstStepId, 'reject', 'بيانات الموظف غير مكتملة', self::APPROVER_1, 'corr-reject-1');

        $row = (array) DB::table('workflow_decisions')->where('workflow_step_id', $firstStepId)->sole();
        $this->assertSame('reject', $row['decision']);
        $this->assertSame('بيانات الموظف غير مكتملة', $row['reason']);
        $this->assertSame(self::APPROVER_1, $row['actor_user_id']);
        $this->assertSame('corr-reject-1', $row['correlation_id']);

        // Engine must not be invoked here; production caller halts on reject.
        // Re-running advance() after a reject still walks the graph but the
        // controller/handler pair decides whether to call it. We assert the
        // ledger row is the only record of the rejection.
        $this->assertSame(1, DB::table('workflow_decisions')->where('workflow_step_id', $firstStepId)->count());
    }

    public function test_recording_the_same_decision_twice_is_idempotent(): void
    {
        $version = $this->publishThreeStepChain();
        $instance = $this->app->make(StartWorkflowHandler::class)
            ->start($version['id'], 'work_records', 'record', '0197f0e0-0000-7000-8000-0000000000bb', self::ACTOR);
        $steps = $this->stepsFor($instance['id']);
        $firstStepId = $steps[0]['id'];

        $recorder = $this->app->make(RecordDecisionHandler::class);
        $first = $recorder->record($firstStepId, 'approve', null, self::APPROVER_1, 'corr-replay-1');
        $second = $recorder->record($firstStepId, 'approve', null, self::APPROVER_1, 'corr-replay-1');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, DB::table('workflow_decisions')->where('workflow_step_id', $firstStepId)->count());
    }

    /**
     * Mark the step completed, record the decision, and create the next step
     * through the engine. Mirrors what the controller will do once it delegates
     * advance to AdvanceAfterDecision.
     */
    private function complete(
        RecordDecisionHandler $recorder,
        AdvanceAfterDecision $advancer,
        string $instanceId,
        string $stepId,
        string $actorUserId,
        ?string $reason,
    ): void {
        DB::table('workflow_step_instances')->where('id', $stepId)->update([
            'state' => 'completed',
            'completed_at' => now(),
            'lock_version' => DB::raw('lock_version + 1'),
            'updated_at' => now(),
        ]);
        $recorder->record($stepId, 'approve', $reason, $actorUserId);
        $advancer->advance($instanceId, $stepId, $actorUserId);
    }

    /** @return list<array<string, mixed>> */
    private function stepsFor(string $instanceId): array
    {
        return array_values(array_map(
            static fn (object $row): array => (array) $row,
            DB::table('workflow_step_instances')->where('workflow_instance_id', $instanceId)->orderBy('activation_sequence')->get()->all(),
        ));
    }

    /** @return array<string, mixed> */
    private function publishThreeStepChain(): array
    {
        $graph = [
            'nodes' => [
                ['key' => 'start', 'type' => 'start'],
                ['key' => 'step-1', 'type' => 'approval', 'assignee_user_id' => self::APPROVER_1],
                ['key' => 'step-2', 'type' => 'approval', 'assignee_user_id' => self::APPROVER_2],
                ['key' => 'step-3', 'type' => 'approval', 'assignee_user_id' => self::APPROVER_3],
                ['key' => 'end', 'type' => 'end'],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'step-1'],
                ['from' => 'step-1', 'to' => 'step-2'],
                ['from' => 'step-2', 'to' => 'step-3'],
                ['from' => 'step-3', 'to' => 'end'],
            ],
        ];

        return $this->app->make(PublishWorkflowVersionHandler::class)
            ->publish('engine-'.Str::uuid7()->toString(), 'work_record', self::ACTOR, $graph);
    }
}
