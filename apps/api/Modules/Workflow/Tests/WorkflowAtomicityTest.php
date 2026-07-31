<?php

namespace Modules\Workflow\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Workflow\Features\PublishWorkflowVersion\Handler\PublishWorkflowVersionHandler;
use Modules\Workflow\Features\StartWorkflow\Handler\StartWorkflowHandler;
use Modules\Workflow\Features\WorkflowLifecycle\Handler\WorkflowLifecycleMutator;
use Modules\Workflow\Infrastructure\Persistence\WorkflowStepAdvancer;
use RuntimeException;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

final class WorkflowAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private const ACTOR = '0197f0e0-0000-7000-8000-000000000001';

    private const REASSIGNED_TO = '0197f0e0-0000-7000-8000-000000000002';

    private const CORRELATION = '0197f0e0-0000-7000-8000-000000000003';

    public function test_create_version_rolls_back_state_outbox_and_idempotency_when_outbox_fails(): void
    {
        $draft = $this->createDraftWorkflow();
        $beforeVersions = DB::table('workflow_versions')->count();
        $beforeOutbox = DB::table('outbox_events')->count();
        $beforeIdempotency = DB::table('workflow_idempotency_keys')->count();
        $this->bindFailingOutbox();

        $result = $this->mutator()->createVersion(
            $draft['definition_id'],
            self::ACTOR,
            $this->graph('second'),
            $this->idempotency('createWorkflowVersion', 'create-v2', ['definition_id' => $draft['definition_id']]),
        );

        $this->assertFalse($result['ok']);
        $this->assertSame($beforeVersions, DB::table('workflow_versions')->count());
        $this->assertSame($beforeOutbox, DB::table('outbox_events')->count());
        $this->assertSame($beforeIdempotency, DB::table('workflow_idempotency_keys')->count());
    }

    public function test_publish_version_rolls_back_to_draft_when_outbox_fails(): void
    {
        $draft = $this->createDraftWorkflow();
        $beforeOutbox = DB::table('outbox_events')->count();
        $beforeIdempotency = DB::table('workflow_idempotency_keys')->count();
        $this->bindFailingOutbox();

        $result = $this->mutator()->publishVersion(
            $draft['version_id'],
            $this->idempotency('publishWorkflowVersion', 'publish-v1', ['version_id' => $draft['version_id']]),
        );

        $this->assertFalse($result['ok']);
        $version = DB::table('workflow_versions')->where('id', $draft['version_id'])->sole();
        $this->assertSame('draft', $version->definition_state);
        $this->assertNull($version->published_at);
        $this->assertSame($beforeOutbox, DB::table('outbox_events')->count());
        $this->assertSame($beforeIdempotency, DB::table('workflow_idempotency_keys')->count());
    }

    public function test_step_action_rolls_back_lock_assignee_outbox_and_idempotency_when_outbox_fails(): void
    {
        [$instance, $step] = $this->startWorkflow();
        $beforeOutbox = DB::table('outbox_events')->count();
        $beforeIdempotency = DB::table('workflow_idempotency_keys')->count();
        $this->bindFailingOutbox();

        $result = $this->mutator()->actOnStep(
            (array) $step,
            (string) $step->lock_version,
            'reassign',
            self::REASSIGNED_TO,
            'coverage',
            self::ACTOR,
            self::CORRELATION,
            $this->idempotency('actOnWorkflowStep.reassign', 'act', ['step_id' => (string) $step->id]),
        );

        $this->assertFalse($result['ok']);
        $current = DB::table('workflow_step_instances')->where('id', $step->id)->sole();
        $this->assertSame((int) $step->lock_version, (int) $current->lock_version);
        $this->assertSame($step->assignee_user_id, $current->assignee_user_id);
        $this->assertSame($instance['state'], DB::table('workflow_instances')->where('id', $instance['id'])->value('state'));
        $this->assertSame($beforeOutbox, DB::table('outbox_events')->count());
        $this->assertSame($beforeIdempotency, DB::table('workflow_idempotency_keys')->count());
    }

    public function test_idempotency_store_failure_rolls_back_state_and_outbox(): void
    {
        [$instance, $step] = $this->startWorkflow();
        $command = ['step_id' => (string) $step->id];
        $idempotency = $this->idempotency('actOnWorkflowStep.reassign', 'racing-act', $command);
        DB::table('workflow_idempotency_keys')->insert([
            ...$idempotency,
            'resource_id' => (string) $step->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $beforeOutbox = DB::table('outbox_events')->count();

        $result = $this->mutator()->actOnStep(
            (array) $step,
            (string) $step->lock_version,
            'reassign',
            self::REASSIGNED_TO,
            'coverage',
            self::ACTOR,
            self::CORRELATION,
            $idempotency,
        );

        $this->assertFalse($result['ok']);
        $current = DB::table('workflow_step_instances')->where('id', $step->id)->sole();
        $this->assertSame((int) $step->lock_version, (int) $current->lock_version);
        $this->assertSame($step->assignee_user_id, $current->assignee_user_id);
        $this->assertSame($instance['state'], DB::table('workflow_instances')->where('id', $instance['id'])->value('state'));
        $this->assertSame($beforeOutbox, DB::table('outbox_events')->count());
        $this->assertSame(1, DB::table('workflow_idempotency_keys')->where([
            'principal_id' => self::ACTOR,
            'operation' => 'actOnWorkflowStep.reassign',
            'key_hash' => $idempotency['key_hash'],
        ])->count());
    }

    public function test_decision_rolls_back_step_instance_ledger_outbox_and_idempotency_when_outbox_fails(): void
    {
        [$instance, $step] = $this->startWorkflow();
        $beforeOutbox = DB::table('outbox_events')->count();
        $beforeIdempotency = DB::table('workflow_idempotency_keys')->count();
        $beforeDecisions = DB::table('workflow_decisions')->count();
        $this->bindFailingOutbox();

        $result = $this->mutator()->recordStepDecision(
            (array) $step,
            $instance,
            (string) $step->lock_version,
            'completed',
            'approve',
            'coverage',
            self::ACTOR,
            self::CORRELATION,
            $this->idempotency('recordWorkflowDecision', 'decide', ['step_id' => (string) $step->id]),
        );

        $this->assertFalse($result['ok']);
        $currentStep = DB::table('workflow_step_instances')->where('id', $step->id)->sole();
        $currentInstance = DB::table('workflow_instances')->where('id', $instance['id'])->sole();
        $this->assertSame($step->state, $currentStep->state);
        $this->assertSame((int) $step->lock_version, (int) $currentStep->lock_version);
        $this->assertSame($instance['state'], $currentInstance->state);
        $this->assertSame((int) $instance['lock_version'], (int) $currentInstance->lock_version);
        $this->assertSame($beforeDecisions, DB::table('workflow_decisions')->count());
        $this->assertSame($beforeOutbox, DB::table('outbox_events')->count());
        $this->assertSame($beforeIdempotency, DB::table('workflow_idempotency_keys')->count());
    }

    public function test_cancel_rolls_back_instance_steps_outbox_and_idempotency_when_outbox_fails(): void
    {
        [$instance, $step] = $this->startWorkflow();
        $beforeOutbox = DB::table('outbox_events')->count();
        $beforeIdempotency = DB::table('workflow_idempotency_keys')->count();
        $this->bindFailingOutbox();

        $result = $this->mutator()->cancelInstance(
            $instance,
            (string) $instance['lock_version'],
            'coverage',
            self::ACTOR,
            self::CORRELATION,
            $this->idempotency('cancelWorkflow', 'cancel', ['instance_id' => $instance['id']]),
        );

        $this->assertFalse($result['ok']);
        $currentInstance = DB::table('workflow_instances')->where('id', $instance['id'])->sole();
        $currentStep = DB::table('workflow_step_instances')->where('id', $step->id)->sole();
        $this->assertSame($instance['state'], $currentInstance->state);
        $this->assertSame((int) $instance['lock_version'], (int) $currentInstance->lock_version);
        $this->assertSame($step->state, $currentStep->state);
        $this->assertSame($beforeOutbox, DB::table('outbox_events')->count());
        $this->assertSame($beforeIdempotency, DB::table('workflow_idempotency_keys')->count());
    }

    public function test_task_completion_rolls_back_step_and_instance_when_outbox_fails(): void
    {
        [$instance, $step] = $this->startWorkflow();
        $beforeOutbox = DB::table('outbox_events')->count();

        try {
            (new WorkflowStepAdvancer(
                $this->failingOutbox(),
                $this->app->make(\Modules\Workflow\Features\Engine\Handler\AdvanceAfterDecision::class),
            ))->taskCompleted(
                (string) $step->id,
                Str::uuid7()->toString(),
                self::ACTOR,
            );
            $this->fail('The failing outbox should abort workflow completion.');
        } catch (RuntimeException $exception) {
            $this->assertSame('outbox unavailable', $exception->getMessage());
        }

        $currentStep = DB::table('workflow_step_instances')->where('id', $step->id)->sole();
        $currentInstance = DB::table('workflow_instances')->where('id', $instance['id'])->sole();
        $this->assertSame($step->state, $currentStep->state);
        $this->assertSame((int) $step->lock_version, (int) $currentStep->lock_version);
        $this->assertSame($instance['state'], $currentInstance->state);
        $this->assertSame((int) $instance['lock_version'], (int) $currentInstance->lock_version);
        $this->assertSame($beforeOutbox, DB::table('outbox_events')->count());
    }

    public function test_task_completion_advances_multi_step_workflow_without_closing_instance(): void
    {
        [$instance, $step] = $this->startMultiStepWorkflow();

        $result = $this->app->make(WorkflowStepAdvancer::class)->taskCompleted(
            (string) $step->id,
            Str::uuid7()->toString(),
            self::ACTOR,
        );

        $steps = DB::table('workflow_step_instances')
            ->where('workflow_instance_id', $instance['id'])
            ->orderBy('created_at')
            ->get();
        $this->assertSame('completed', $result['state']);
        $this->assertSame('running', $result['instance_state']);
        $this->assertCount(2, $steps);
        $this->assertSame('completed', $steps[0]->state);
        $this->assertSame('step-2', $steps[1]->node_key);
        $this->assertSame('waiting', $steps[1]->state);
        $this->assertSame('running', DB::table('workflow_instances')->where('id', $instance['id'])->value('state'));
    }

    public function test_stale_step_decision_does_not_write_side_effects(): void
    {
        [$instance, $step] = $this->startWorkflow();
        DB::table('workflow_step_instances')->where('id', $step->id)->update(['lock_version' => 2]);
        $beforeOutbox = DB::table('outbox_events')->count();

        $result = $this->mutator()->recordStepDecision(
            (array) $step,
            $instance,
            '1',
            'completed',
            'approve',
            'stale',
            self::ACTOR,
            self::CORRELATION,
            $this->idempotency('recordWorkflowDecision', 'stale-decision', ['step_id' => (string) $step->id]),
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('waiting', DB::table('workflow_step_instances')->where('id', $step->id)->value('state'));
        $this->assertSame('running', DB::table('workflow_instances')->where('id', $instance['id'])->value('state'));
        $this->assertSame(0, DB::table('workflow_decisions')->where('workflow_step_id', $step->id)->count());
        $this->assertSame($beforeOutbox, DB::table('outbox_events')->count());
        $this->assertSame(0, DB::table('workflow_idempotency_keys')->where('operation', 'recordWorkflowDecision')->count());
    }

    public function test_stale_step_action_does_not_write_side_effects(): void
    {
        [, $step] = $this->startWorkflow();
        DB::table('workflow_step_instances')->where('id', $step->id)->update(['lock_version' => 2]);
        $beforeOutbox = DB::table('outbox_events')->count();

        $result = $this->mutator()->actOnStep(
            (array) $step,
            '1',
            'reassign',
            self::REASSIGNED_TO,
            'stale',
            self::ACTOR,
            self::CORRELATION,
            $this->idempotency('actOnWorkflowStep.reassign', 'stale-action', ['step_id' => (string) $step->id]),
        );

        $this->assertFalse($result['ok']);
        $current = DB::table('workflow_step_instances')->where('id', $step->id)->sole();
        $this->assertSame($step->assignee_user_id, $current->assignee_user_id);
        $this->assertSame($beforeOutbox, DB::table('outbox_events')->count());
        $this->assertSame(0, DB::table('workflow_idempotency_keys')->where('operation', 'actOnWorkflowStep.reassign')->count());
    }

    public function test_stale_instance_cancel_does_not_write_side_effects(): void
    {
        [$instance, $step] = $this->startWorkflow();
        DB::table('workflow_instances')->where('id', $instance['id'])->update(['lock_version' => 2]);
        $beforeOutbox = DB::table('outbox_events')->count();

        $result = $this->mutator()->cancelInstance(
            $instance,
            '1',
            'stale',
            self::ACTOR,
            self::CORRELATION,
            $this->idempotency('cancelWorkflow', 'stale-cancel', ['instance_id' => $instance['id']]),
        );

        $this->assertFalse($result['ok']);
        $this->assertSame('running', DB::table('workflow_instances')->where('id', $instance['id'])->value('state'));
        $this->assertSame($step->state, DB::table('workflow_step_instances')->where('id', $step->id)->value('state'));
        $this->assertSame($beforeOutbox, DB::table('outbox_events')->count());
        $this->assertSame(0, DB::table('workflow_idempotency_keys')->where('operation', 'cancelWorkflow')->count());
    }

    public function test_idempotency_replay_preserves_same_and_different_fingerprint_semantics(): void
    {
        $draft = $this->createDraftWorkflow();
        $command = ['version_id' => $draft['version_id']];
        $key = 'publish-replay';
        $result = $this->mutator()->publishVersion(
            $draft['version_id'],
            $this->idempotency('publishWorkflowVersion', $key, $command),
        );

        $this->assertTrue($result['ok']);
        $same = $this->mutator()->replay(self::ACTOR, 'publishWorkflowVersion', $key, $command);
        $different = $this->mutator()->replay(self::ACTOR, 'publishWorkflowVersion', $key, ['version_id' => Str::uuid7()->toString()]);
        $this->assertTrue($same['match']);
        $this->assertSame($draft['version_id'], $same['resource_id']);
        $this->assertFalse($different['match']);
    }

    /** @return array{definition_id: string, version_id: string} */
    private function createDraftWorkflow(): array
    {
        $input = [
            'code' => 'atomicity-'.Str::uuid7()->toString(),
            'name' => 'Atomicity',
            'source_record_type' => 'work_record',
        ];
        $result = $this->mutator()->createDefinition(
            $input,
            self::ACTOR,
            $this->graph('draft'),
            hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)),
            hash('sha256', Str::uuid7()->toString()),
        );
        $this->assertTrue($result['ok']);

        return [
            'definition_id' => (string) $result['definition']['id'],
            'version_id' => (string) $result['version']['id'],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: object} */
    private function startWorkflow(): array
    {
        $version = $this->app->make(PublishWorkflowVersionHandler::class)->publish(
            'atomic-start-'.Str::uuid7()->toString(),
            'work_record',
            self::ACTOR,
            $this->graph('active'),
        );
        $instance = $this->app->make(StartWorkflowHandler::class)->start(
            $version['id'],
            'work_records',
            'record',
            Str::uuid7()->toString(),
            self::ACTOR,
        );
        $step = DB::table('workflow_step_instances')->where('workflow_instance_id', $instance['id'])->sole();

        return [$instance, $step];
    }

    private function startMultiStepWorkflow(): array
    {
        $version = $this->app->make(PublishWorkflowVersionHandler::class)->publish(
            'atomic-multi-step-'.Str::uuid7()->toString(),
            'work_record',
            self::ACTOR,
            [
                'nodes' => [
                    ['key' => 'start', 'type' => 'start'],
                    ['key' => 'step-1', 'type' => 'approval', 'assignee_user_id' => self::ACTOR],
                    ['key' => 'step-2', 'type' => 'approval', 'assignee_user_id' => self::REASSIGNED_TO],
                    ['key' => 'end', 'type' => 'end'],
                ],
                'transitions' => [
                    ['from' => 'start', 'to' => 'step-1'],
                    ['from' => 'step-1', 'to' => 'step-2'],
                    ['from' => 'step-2', 'to' => 'end'],
                ],
            ],
        );
        $instance = $this->app->make(StartWorkflowHandler::class)->start(
            $version['id'],
            'work_records',
            'record',
            Str::uuid7()->toString(),
            self::ACTOR,
        );
        $step = DB::table('workflow_step_instances')->where('workflow_instance_id', $instance['id'])->sole();

        return [$instance, $step];
    }

    /** @return array<string, mixed> */
    private function graph(string $title): array
    {
        return [
            'nodes' => [
                ['key' => 'start', 'type' => 'start'],
                ['key' => 'review', 'type' => 'work_item', 'configuration' => ['title' => $title]],
                ['key' => 'end', 'type' => 'end'],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'review'],
                ['from' => 'review', 'to' => 'end'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array{operation: string, key_hash: string, request_hash: string, principal_id: string}
     */
    private function idempotency(string $operation, string $key, array $command): array
    {
        return [
            'operation' => $operation,
            'key_hash' => hash('sha256', $key),
            'request_hash' => hash('sha256', json_encode($command, JSON_THROW_ON_ERROR)),
            'principal_id' => self::ACTOR,
        ];
    }

    private function bindFailingOutbox(): void
    {
        $this->app->instance(TransactionalOutbox::class, $this->failingOutbox());
    }

    private function failingOutbox(): TransactionalOutbox
    {
        return new class implements TransactionalOutbox
        {
            public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void
            {
                throw new RuntimeException('outbox unavailable');
            }
        };
    }

    private function mutator(): WorkflowLifecycleMutator
    {
        return $this->app->make(WorkflowLifecycleMutator::class);
    }
}
