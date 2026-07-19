<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Tasks\Features\CompleteTask\Handler\CompleteTaskHandler;
use Modules\Tasks\Features\CreateTaskFromWorkflowStep\Handler\CreateTaskFromWorkflowStepHandler;
use Shared\Contracts\TransactionalOutbox;

final class TaskController
{
    use HttpSupport;

    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $resolver, private readonly CreateTaskFromWorkflowStepHandler $creator, private readonly CompleteTaskHandler $completer, private readonly TransactionalOutbox $outbox) {}

    public function index(Request $request): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }

        return response()->json(['items' => DB::table('tasks')->where('assignee_user_id', $p['user_id'])->orderBy('created_at')->get()->map(fn ($r) => (array) $r), 'next_cursor' => null])->header('X-Correlation-ID', $c);
    }

    public function store(Request $request): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        } $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        } $v = $request->json()->all();
        if (! is_string($v['title'] ?? null) || $v['title'] === '') {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        } $task = $this->creator->handle(['step_id' => (string) ($v['workflow_step_id'] ?? Str::uuid7()->toString()), 'title' => $v['title'], 'description' => $v['description'] ?? null, 'assignee_user_id' => $v['assignee_user_id'] ?? $p['user_id'], 'owner_organization_unit_id' => $v['owner_organization_unit_id'] ?? null], $p['user_id']);

        return $this->response($task, 201, $c, (int) ($task['lock_version'] ?? 1));
    }

    public function show(Request $request, string $taskId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        $task = DB::table('tasks')->where('id', $taskId)->where('assignee_user_id', $p['user_id'])->first();

        return $task === null
            ? $this->problem(404, 'resource-not-found', 'The task is not available.', $c)
            : $this->response((array) $task, 200, $c, (int) $task->lock_version);
    }

    public function fromStep(Request $request, string $stepId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        } $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        } $step = DB::table('workflow_step_instances')->where('id', $stepId)->first();
        if ($step === null) {
            return $this->problem(404, 'resource-not-found', 'The workflow step is not available.', $c);
        } $task = $this->creator->handle(['step_id' => $stepId, 'title' => $request->input('title', 'Workflow task'), 'assignee_user_id' => $p['user_id']], $p['user_id']);

        return $this->response($task, 201, $c, (int) ($task['lock_version'] ?? 1));
    }

    public function transition(Request $request, string $taskId, string $action): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        } $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        } $task = DB::table('tasks')->where('id', $taskId)->first();
        if ($task === null) {
            return $this->problem(404, 'resource-not-found', 'The task is not available.', $c);
        } $expected = $this->versionFromMatch($request);
        if ($expected === null || $expected !== (int) $task->lock_version) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        } if ($action === 'complete') {
            try {
                $result = $this->completer->handle($taskId, $p['user_id']);

                return $this->response($result['task'], 200, $c, (int) $result['task']['lock_version']);
            } catch (\Throwable $e) {
                return $this->problem(409, 'task-transition-failed', $e->getMessage(), $c);
            }
        } $status = match ($action) {
            'start' => 'in_progress', 'return', 'return-completion' => 'returned', 'submit-completion' => 'submitted', 'cancel' => 'cancelled', default => null
        };
        if ($status === null) {
            return $this->problem(409, 'invalid-task-transition', 'The task action is not supported.', $c);
        } DB::table('tasks')->where('id', $taskId)->where('lock_version', $expected)->update(['status' => $status, 'lock_version' => $expected + 1, 'updated_at' => now()]);
        $this->outbox->append(Str::uuid7()->toString(), $taskId, 'task.'.$action.'.v1', ['task_id' => $taskId, 'actor_user_id' => $p['user_id']]);

        return $this->response((array) DB::table('tasks')->where('id', $taskId)->first(), 200, $c, $expected + 1);
    }
}
