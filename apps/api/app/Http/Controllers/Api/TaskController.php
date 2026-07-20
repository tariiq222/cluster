<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Tasks\Features\CompleteTask\Handler\CompleteTaskHandler;
use Modules\Tasks\Features\CreateTaskFromWorkflowStep\Handler\CreateTaskFromWorkflowStepHandler;
use Shared\Contracts\TransactionalOutbox;

final class TaskController
{
    use HttpSupport;

    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $resolver, private readonly CreateTaskFromWorkflowStepHandler $creator, private readonly CompleteTaskHandler $completer, private readonly TransactionalOutbox $outbox, private readonly DecideAccess $access) {}

    public function index(Request $request): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        } $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }

        $items = [];
        foreach (DB::table('tasks')->where('assignee_user_id', $p['user_id'])->orderBy('created_at')->get() as $row) {
            if ($this->allowed($p, $row, 'tasks.read', $c)) {
                $items[] = (array) $row;
            }
        }

        return response()->json(['items' => $items, 'next_cursor' => null])->header('X-Correlation-ID', $c);
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
        }
        if (! $this->allowed($p, null, 'tasks.create', $c, is_string($v['owner_organization_unit_id'] ?? null) ? $v['owner_organization_unit_id'] : null)) {
            return $this->problem(403, 'access-denied', 'Access denied.', $c);
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

        if ($task === null) {
            return $this->problem(404, 'resource-not-found', 'The task is not available.', $c);
        }
        if (! $this->allowed($p, $task, 'tasks.read', $c)) {
            return $this->problem(403, 'access-denied', 'Access denied.', $c);
        }

        return $this->response((array) $task, 200, $c, (int) $task->lock_version);
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
        }
        if (! $this->allowed($p, null, 'tasks.create', $c)) {
            return $this->problem(403, 'access-denied', 'Access denied.', $c);
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
        }
        $taskCapability = match ($action) {
            'start' => 'tasks.start',
            'return', 'return-completion', 'submit-completion' => 'tasks.update',
            'complete' => 'tasks.complete',
            'cancel' => 'tasks.cancel',
            default => null,
        };
        if ($taskCapability === null) {
            return $this->problem(409, 'invalid-task-transition', 'The task action is not supported.', $c);
        }
        if (! $this->allowed($p, $task, $taskCapability, $c)) {
            return $this->problem(403, 'access-denied', 'Access denied.', $c);
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
            'start' => 'in_progress', 'return', 'return-completion' => 'returned', 'submit-completion' => 'submitted', default => 'cancelled'
        };
        DB::table('tasks')->where('id', $taskId)->where('lock_version', $expected)->update(['status' => $status, 'lock_version' => $expected + 1, 'updated_at' => now()]);
        $this->outbox->append(Str::uuid7()->toString(), $taskId, 'task.'.$action.'.v1', ['task_id' => $taskId, 'actor_user_id' => $p['user_id']]);

        return $this->response((array) DB::table('tasks')->where('id', $taskId)->first(), 200, $c, $expected + 1);
    }

    private function allowed(array $principal, ?\stdClass $task, string $capability, string $correlationId, ?string $ownerUnitId = null): bool
    {
        $scopeId = $ownerUnitId ?? ($task->owner_organization_unit_id ?? null) ?? ($principal['facility_id'] ?? null);
        $participants = $task === null ? [] : DB::table('task_participants')->where('task_id', $task->id)->pluck('user_id')->all();

        return $this->access->decide(
            [
                'user_id' => $principal['user_id'],
                'facility_id' => $principal['facility_id'] ?? null,
                'organization_unit_ids' => array_filter([$principal['facility_id'] ?? null]),
                'correlation_id' => $correlationId,
            ],
            $capability,
            new RecordFacts(
                ownerFacilityId: $scopeId,
                resourceType: 'task',
                classification: (string) ($task->classification ?? 'internal'),
                organizationUnitId: $scopeId,
                recordId: $task === null ? null : (string) $task->id,
                createdByUserId: $task === null ? null : (string) $task->created_by_user_id,
                responsibleUserId: $task === null ? null : (string) $task->assignee_user_id,
                participantIds: array_values(array_filter($participants, 'is_string')),
                lifecycleState: $task === null ? null : (string) $task->status,
                lockVersion: $task === null ? null : (int) $task->lock_version,
            ),
        )->isAllowed();
    }
}
