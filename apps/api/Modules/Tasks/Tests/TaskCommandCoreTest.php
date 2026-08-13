<?php

declare(strict_types=1);

namespace Modules\Tasks\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tasks\Domain\TaskIdempotencyConflict;
use Modules\Tasks\Features\CreateTask\Handler\CreateTaskHandler;
use Modules\Tasks\Features\TransitionTask\Exception\TaskTransitionConflict;
use Modules\Tasks\Features\TransitionTask\Handler\StaleTaskVersion;
use Modules\Tasks\Features\TransitionTask\Handler\TransitionTaskHandler;
use Shared\Contracts\TransactionalOutbox;
use Tests\TestCase;

/**
 * Atomicity, replay, and stale-version guarantees for the Tasks command
 * handlers (no Workflow bridge — pure task domain).
 */
final class TaskCommandCoreTest extends TestCase
{
    use RefreshDatabase;

    private const USER = '0197f0e0-0000-7000-8000-000000000001';

    public function test_creation_replay_appends_once_and_changed_request_conflicts(): void
    {
        $handler = $this->app->make(CreateTaskHandler::class);

        $first = $handler->handle([
            'title' => 'Atomic create',
            'assignee_user_id' => self::USER,
            'priority' => 'normal',
            'classification' => 'internal',
        ], ['user_id' => self::USER], 'create-once');
        $replay = $handler->handle([
            'title' => 'Atomic create',
            'assignee_user_id' => self::USER,
            'priority' => 'normal',
            'classification' => 'internal',
        ], ['user_id' => self::USER], 'create-once');

        $this->assertSame($first, $replay);
        $this->assertSame(1, DB::table('tasks')->where('title', 'Atomic create')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'com.cluster.tasks.created.v1')->count());
        $this->assertSame(1, DB::table('task_idempotency_keys')->where('operation', 'createTask')->count());

        try {
            $handler->handle([
                'title' => 'Atomic create',
                'assignee_user_id' => self::USER,
                'priority' => 'high',
                'classification' => 'internal',
            ], ['user_id' => self::USER], 'create-once');
            $this->fail('Expected an idempotency conflict.');
        } catch (TaskIdempotencyConflict) {
            $this->assertSame(1, DB::table('tasks')->where('title', 'Atomic create')->count());
        }
    }

    public function test_creation_outbox_failure_rolls_back_task_and_idempotency(): void
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

        try {
            $this->app->make(CreateTaskHandler::class)->handle([
                'title' => 'Will fail',
                'assignee_user_id' => self::USER,
                'priority' => 'normal',
                'classification' => 'internal',
            ], ['user_id' => self::USER], 'create-fail');
            $this->fail('Expected the injected outbox failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('outbox unavailable', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('tasks')->count());
        $this->assertSame(0, DB::table('outbox_events')->count());
        $this->assertSame(0, DB::table('task_idempotency_keys')->count());
    }

    public function test_transition_replay_appends_once_and_changed_request_conflicts(): void
    {
        $taskId = $this->seedTask();
        $handler = $this->app->make(TransitionTaskHandler::class);

        $first = $handler->handle($taskId, 1, 'start', ['user_id' => self::USER], 'transition-once');
        $replay = $handler->handle($taskId, 1, 'start', ['user_id' => self::USER], 'transition-once');

        $this->assertSame($first, $replay);
        $this->assertSame('in_progress', DB::table('tasks')->where('id', $taskId)->value('status'));
        $this->assertSame(2, (int) DB::table('tasks')->where('id', $taskId)->value('lock_version'));
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'com.cluster.tasks.started.v1')->count());
        $this->assertSame(1, DB::table('task_idempotency_keys')->where('operation', 'transitionTask')->count());

        try {
            $handler->handle($taskId, 2, 'block', ['user_id' => self::USER], 'transition-once', 'changed-request');
            $this->fail('Expected an idempotency conflict.');
        } catch (TaskIdempotencyConflict) {
            $this->assertSame('in_progress', DB::table('tasks')->where('id', $taskId)->value('status'));
        }

        $this->assertSame(1, DB::table('outbox_events')->where('aggregate_id', $taskId)->count());
        $this->assertSame(1, DB::table('task_idempotency_keys')->count());
    }

    public function test_transition_outbox_failure_rolls_back_task_and_idempotency(): void
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
            $this->app->make(TransitionTaskHandler::class)->handle($taskId, 1, 'start', ['user_id' => self::USER], 'transition-failure');
            $this->fail('Expected the injected outbox failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('outbox unavailable', $exception->getMessage());
        }

        $this->assertSame('open', DB::table('tasks')->where('id', $taskId)->value('status'));
        $this->assertSame(1, (int) DB::table('tasks')->where('id', $taskId)->value('lock_version'));
        $this->assertSame(0, DB::table('outbox_events')->where('aggregate_id', $taskId)->count());
        $this->assertSame(0, DB::table('task_idempotency_keys')->where('task_id', $taskId)->count());
    }

    public function test_transition_rejects_a_stale_handler_version_without_side_effects(): void
    {
        $taskId = $this->seedTask(2);

        try {
            $this->app->make(TransitionTaskHandler::class)->handle($taskId, 1, 'start', ['user_id' => self::USER], 'stale-start');
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

    public function test_transition_rejects_invalid_state_change(): void
    {
        $taskId = $this->seedTask();

        try {
            $this->app->make(TransitionTaskHandler::class)->handle($taskId, 1, 'complete', ['user_id' => self::USER], 'shortcut', null, 'done');
            $this->fail('Expected an invalid transition.');
        } catch (TaskTransitionConflict $exception) {
            $this->assertSame('invalid_transition', $exception->getMessage());
        }

        $this->assertSame('open', DB::table('tasks')->where('id', $taskId)->value('status'));
        $this->assertSame(0, DB::table('outbox_events')->where('aggregate_id', $taskId)->count());
    }

    public function test_transition_rejects_missing_reason(): void
    {
        $taskId = $this->seedTask();
        $this->app->make(TransitionTaskHandler::class)->handle($taskId, 1, 'start', ['user_id' => self::USER], 'warm-start');

        try {
            $this->app->make(TransitionTaskHandler::class)->handle($taskId, 2, 'block', ['user_id' => self::USER], 'no-reason');
            $this->fail('Expected reason_required.');
        } catch (TaskTransitionConflict $exception) {
            $this->assertSame('reason_required', $exception->getMessage());
        }
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
