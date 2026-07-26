<?php

namespace Modules\Tasks\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tasks\Domain\TaskIdempotencyConflict;
use Modules\Tasks\Features\CompleteTask\Handler\CompleteTaskHandler;
use Modules\Tasks\Features\CompleteTask\Handler\StaleTaskVersion;
use Modules\Tasks\Features\CreateTaskFromWorkflowStep\Handler\CreateTaskFromWorkflowStepHandler;
use Modules\Tasks\Features\TransitionTask\Handler\TransitionTaskHandler;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

final class TaskWorkflowCoreTest extends TestCase
{
    use RefreshDatabase;

    private const USER = '0197f0e0-0000-7000-8000-000000000001';

    public function test_task_step_is_assigned_and_completion_is_idempotent(): void
    {
        $version = $this->app->make('Modules\\Workflow\\Features\\PublishWorkflowVersion\\Handler\\PublishWorkflowVersionHandler')->publish('task-flow', 'record', self::USER, [
            'nodes' => [['key' => 'review', 'type' => 'work_item']],
        ]);
        $instance = $this->app->make('Modules\\Workflow\\Features\\StartWorkflow\\Handler\\StartWorkflowHandler')->start($version['id'], 'work_records', 'record', '0197f0e0-0000-7000-8000-000000000099', self::USER);
        $step = DB::table('workflow_step_instances')->where('workflow_instance_id', $instance['id'])->first();
        $task = $this->app->make(CreateTaskFromWorkflowStepHandler::class)->handle(['step_id' => $step->id], self::USER);
        $handler = $this->app->make(CompleteTaskHandler::class);
        $first = $handler->handle($task['id'], self::USER, 1, 'complete-task');
        $second = $handler->handle($task['id'], self::USER, 1, 'complete-task');

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($first['task'], $second['task']);
        $this->assertSame('completed', DB::table('tasks')->where('id', $task['id'])->value('status'));
        $this->assertSame('completed', DB::table('workflow_instances')->where('id', $instance['id'])->value('state'));
        $this->assertSame(1, DB::table('workflow_step_instances')->where('id', $step->id)->where('state', 'completed')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'task.completed.v1')->count());
        $this->assertSame(1, DB::table('task_idempotency_keys')->where('operation', 'completeTask')->count());
    }

    public function test_completion_outbox_failure_rolls_back_task_outbox_and_idempotency(): void
    {
        $realOutbox = $this->app->make(TransactionalOutbox::class);
        $this->app->instance(TransactionalOutbox::class, new class($realOutbox) implements TransactionalOutbox
        {
            public function __construct(private readonly TransactionalOutbox $inner) {}

            public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void
            {
                $this->inner->append($eventId, $aggregateId, $eventType, $payload);
                throw new \RuntimeException('outbox unavailable');
            }
        });
        $taskId = $this->seedTask();

        try {
            $this->app->make(CompleteTaskHandler::class)->handle($taskId, self::USER, 1, 'completion-failure');
            $this->fail('Expected the injected outbox failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('outbox unavailable', $exception->getMessage());
        }

        $this->assertSame('open', DB::table('tasks')->where('id', $taskId)->value('status'));
        $this->assertSame(1, (int) DB::table('tasks')->where('id', $taskId)->value('lock_version'));
        $this->assertSame(0, DB::table('outbox_events')->where('aggregate_id', $taskId)->count());
        $this->assertSame(0, DB::table('task_idempotency_keys')->where('task_id', $taskId)->count());
    }

    public function test_transition_outbox_failure_rolls_back_task_outbox_and_idempotency(): void
    {
        $realOutbox = $this->app->make(TransactionalOutbox::class);
        $this->app->instance(TransactionalOutbox::class, new class($realOutbox) implements TransactionalOutbox
        {
            public function __construct(private readonly TransactionalOutbox $inner) {}

            public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void
            {
                $this->inner->append($eventId, $aggregateId, $eventType, $payload);
                throw new \RuntimeException('outbox unavailable');
            }
        });
        $taskId = $this->seedTask();

        try {
            $this->app->make(TransitionTaskHandler::class)->handle($taskId, 1, 'start', self::USER, 'transition-failure');
            $this->fail('Expected the injected outbox failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('outbox unavailable', $exception->getMessage());
        }

        $this->assertSame('open', DB::table('tasks')->where('id', $taskId)->value('status'));
        $this->assertSame(1, (int) DB::table('tasks')->where('id', $taskId)->value('lock_version'));
        $this->assertSame(0, DB::table('outbox_events')->where('aggregate_id', $taskId)->count());
        $this->assertSame(0, DB::table('task_idempotency_keys')->where('task_id', $taskId)->count());
    }

    public function test_transition_replay_appends_once_and_changed_request_conflicts(): void
    {
        $taskId = $this->seedTask();
        $handler = $this->app->make(TransitionTaskHandler::class);

        $first = $handler->handle($taskId, 1, 'start', self::USER, 'transition-once');
        $replay = $handler->handle($taskId, 1, 'start', self::USER, 'transition-once');

        $this->assertSame((array) $first, (array) $replay);
        $this->assertSame('in_progress', DB::table('tasks')->where('id', $taskId)->value('status'));
        $this->assertSame(2, (int) DB::table('tasks')->where('id', $taskId)->value('lock_version'));
        $this->assertSame(1, DB::table('outbox_events')->where('aggregate_id', $taskId)->where('event_type', 'task.start.v1')->count());
        $this->assertSame(1, DB::table('task_idempotency_keys')->where('task_id', $taskId)->count());

        try {
            $handler->handle($taskId, 2, 'cancel', self::USER, 'transition-once');
            $this->fail('Expected an idempotency conflict.');
        } catch (TaskIdempotencyConflict) {
            $this->assertSame('in_progress', DB::table('tasks')->where('id', $taskId)->value('status'));
        }

        $this->assertSame(1, DB::table('outbox_events')->where('aggregate_id', $taskId)->count());
        $this->assertSame(1, DB::table('task_idempotency_keys')->where('task_id', $taskId)->count());
    }

    public function test_completion_replay_appends_once_and_changed_request_conflicts(): void
    {
        $taskId = $this->seedTask();
        $handler = $this->app->make(CompleteTaskHandler::class);

        $first = $handler->handle($taskId, self::USER, 1, 'complete-once');
        $replay = $handler->handle($taskId, self::USER, 1, 'complete-once');

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($replay['idempotent']);
        $this->assertSame($first['task'], $replay['task']);

        try {
            $handler->handle($taskId, self::USER, 2, 'complete-once');
            $this->fail('Expected an idempotency conflict.');
        } catch (TaskIdempotencyConflict) {
            $this->assertSame('completed', DB::table('tasks')->where('id', $taskId)->value('status'));
        }

        $this->assertSame(2, (int) DB::table('tasks')->where('id', $taskId)->value('lock_version'));
        $this->assertSame(1, DB::table('outbox_events')->where('aggregate_id', $taskId)->where('event_type', 'task.completed.v1')->count());
        $this->assertSame(1, DB::table('task_idempotency_keys')->where('task_id', $taskId)->count());
    }

    public function test_completion_rejects_a_stale_handler_version_without_side_effects(): void
    {
        $taskId = $this->seedTask(2);

        try {
            $this->app->make(CompleteTaskHandler::class)->handle($taskId, self::USER, 1, 'stale-completion');
            $this->fail('Expected a stale task version.');
        } catch (StaleTaskVersion $exception) {
            $this->assertSame($taskId, $exception->taskId);
            $this->assertSame(1, $exception->expectedVersion);
        }

        $this->assertSame('open', DB::table('tasks')->where('id', $taskId)->value('status'));
        $this->assertSame(2, (int) DB::table('tasks')->where('id', $taskId)->value('lock_version'));
        $this->assertSame(0, DB::table('outbox_events')->where('aggregate_id', $taskId)->count());
        $this->assertSame(0, DB::table('task_idempotency_keys')->where('task_id', $taskId)->count());
    }

    private function seedTask(int $lockVersion = 1): string
    {
        $taskId = Str::uuid7()->toString();
        DB::table('tasks')->insert([
            'id' => $taskId,
            'title' => 'Atomic task',
            'created_by_user_id' => self::USER,
            'assignee_user_id' => self::USER,
            'status' => 'open',
            'priority' => 'normal',
            'classification' => 'internal',
            'completion_policy' => 'direct',
            'lock_version' => $lockVersion,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $taskId;
    }
}
