<?php

namespace Modules\Tasks\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Tasks\Features\CompleteTask\Handler\CompleteTaskHandler;
use Modules\Tasks\Features\CreateTaskFromWorkflowStep\Handler\CreateTaskFromWorkflowStepHandler;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

final class TaskWorkflowCoreTest extends TestCase
{
    use RefreshDatabase;

    private const USER = '0197f0e0-0000-7000-8000-000000000001';

    public function test_task_step_is_assigned_and_completion_is_idempotent(): void
    {
        $version = $this->app->make('Modules\\Workflow\\Features\\PublishWorkflowVersion\\Handler\\PublishWorkflowVersionHandler')->publish('task-flow', 'record', self::USER, [
            'nodes' => [['key' => 'task', 'type' => 'task']],
        ]);
        $instance = $this->app->make('Modules\\Workflow\\Features\\StartWorkflow\\Handler\\StartWorkflowHandler')->start($version['id'], 'work_records', 'record', '0197f0e0-0000-7000-8000-000000000099', self::USER);
        $step = DB::table('workflow_step_instances')->where('workflow_instance_id', $instance['id'])->first();
        $task = $this->app->make(CreateTaskFromWorkflowStepHandler::class)->handle(['step_id' => $step->id], self::USER);
        $handler = $this->app->make(CompleteTaskHandler::class);
        $first = $handler->handle($task['id'], self::USER);
        $second = $handler->handle($task['id'], self::USER);

        $this->assertFalse($first['idempotent']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame('completed', DB::table('tasks')->where('id', $task['id'])->value('status'));
        $this->assertSame('completed', DB::table('workflow_instances')->where('id', $instance['id'])->value('state'));
        $this->assertSame(1, DB::table('workflow_step_instances')->where('id', $step->id)->where('state', 'completed')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'task.completed.v1')->count());
    }

    public function test_completion_outbox_failure_rolls_back_task_and_workflow(): void
    {
        $this->app->instance(TransactionalOutbox::class, new class implements TransactionalOutbox
        {
            public function append(string $eventId, string $aggregateId, string $eventType, array $payload): void
            {
                if ($eventType === 'task.completed.v1') {
                    throw new \RuntimeException('outbox unavailable');
                }
            }
        });
        DB::table('tasks')->insert([
            'id' => '0197f0e0-0000-7000-8000-000000000091', 'title' => 'Rollback', 'created_by_user_id' => self::USER,
            'assignee_user_id' => self::USER, 'status' => 'open', 'priority' => 'normal', 'classification' => 'internal',
            'completion_policy' => 'direct', 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(\RuntimeException::class);
        try {
            $this->app->make(CompleteTaskHandler::class)->handle('0197f0e0-0000-7000-8000-000000000091', self::USER);
        } finally {
            $this->assertSame('open', DB::table('tasks')->where('id', '0197f0e0-0000-7000-8000-000000000091')->value('status'));
        }
    }
}
