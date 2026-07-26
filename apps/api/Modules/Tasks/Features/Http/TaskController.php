<?php

namespace Modules\Tasks\Features\Http;

use Illuminate\Http\Request;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Tasks\Domain\TaskIdempotencyConflict;
use Modules\Tasks\Features\CompleteTask\Handler\CompleteTaskHandler;
use Modules\Tasks\Features\CompleteTask\Handler\StaleTaskVersion;
use Modules\Tasks\Features\CreateTaskFromWorkflowStep\Handler\CreateTaskFromWorkflowStepHandler;
use Modules\Tasks\Features\TransitionTask\Handler\TransitionTaskHandler;
use Modules\Tasks\Infrastructure\Persistence\TaskHttpStore;

final class TaskController
{
    use TaskHttpSupport;

    public function __construct(private readonly ResolveDevelopmentFixturePrincipal $resolver, private readonly CreateTaskFromWorkflowStepHandler $creator, private readonly CompleteTaskHandler $completer, private readonly TransitionTaskHandler $transitioner, private readonly DecideAccess $access, private readonly TaskHttpStore $store) {}

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
        foreach ($this->store->listForAssignee($p['user_id']) as $row) {
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
        }

        $v = $request->json()->all();
        $title = $v['title'] ?? null;
        $description = array_key_exists('description', $v) ? $v['description'] : null;
        $ownerUnitId = $v['owner_organization_unit_id'] ?? null;
        $assigneeUserId = array_key_exists('assignee_user_id', $v) ? $v['assignee_user_id'] : $p['user_id'];
        $priority = $v['priority'] ?? null;
        $dueAt = $v['due_at'] ?? null;
        $classification = $v['classification'] ?? null;
        $completionPolicy = array_key_exists('completion_policy', $v) ? $v['completion_policy'] : 'direct';
        $source = array_key_exists('source', $v) ? $this->normalizeSourceReference($v['source']) : null;

        if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 255) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        if (array_key_exists('description', $v) && (! is_string($description) || mb_strlen($description) > 4000)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        if (! $this->isUuidV7($ownerUnitId)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        if (! $this->isUuidV7($assigneeUserId)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        if (! $this->isTaskPriority($priority)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        if (! $this->isUtcDateTime($dueAt)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        if (! $this->isTaskClassification($classification)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        if (! $this->isTaskCompletionPolicy($completionPolicy)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        if ($source === null && array_key_exists('source', $v)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        if (! $this->allowed($p, null, 'tasks.create', $c, $ownerUnitId)) {
            return $this->problem(403, 'access-denied', 'Access denied.', $c);
        } $task = $this->creator->handle([
            'title' => $title,
            'description' => $description,
            'assignee_user_id' => $assigneeUserId,
            'owner_organization_unit_id' => $ownerUnitId,
            'priority' => $priority,
            'due_at' => $dueAt,
            'classification' => $classification,
            'completion_policy' => $completionPolicy,
            'source' => $source,
        ], $p['user_id']);

        return $this->response($task, 201, $c, (int) ($task['lock_version'] ?? 1));
    }

    public function update(Request $request, string $taskId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }

        $task = $this->store->find($taskId);
        if ($task === null) {
            return $this->problem(404, 'resource-not-found', 'The task is not available.', $c);
        }
        if (! $this->allowed($p, $task, 'tasks.update', $c)) {
            return $this->problem(403, 'access-denied', 'Access denied.', $c);
        }

        $expected = $this->versionFromMatch($request);
        if ($expected === null || $expected !== (int) $task->lock_version) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }

        $v = $request->json()->all();
        $updates = [];
        if (array_key_exists('title', $v)) {
            if (! is_string($v['title']) || trim($v['title']) === '' || mb_strlen($v['title']) > 255) {
                return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
            }
            $updates['title'] = $v['title'];
        }
        if (array_key_exists('description', $v)) {
            if (! is_string($v['description']) || mb_strlen($v['description']) > 4000) {
                return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
            }
            $updates['description'] = $v['description'];
        }
        if (array_key_exists('assignee_user_id', $v)) {
            if (! $this->isUuidV7($v['assignee_user_id'])) {
                return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
            }
            $updates['assignee_user_id'] = $v['assignee_user_id'];
        }
        if (array_key_exists('priority', $v)) {
            if (! $this->isTaskPriority($v['priority'])) {
                return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
            }
            $updates['priority'] = $v['priority'];
        }
        if (array_key_exists('due_at', $v)) {
            if (! $this->isUtcDateTime($v['due_at'])) {
                return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
            }
            $updates['due_at'] = $v['due_at'];
        }
        if ($updates === []) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }

        $updated = $this->store->update($taskId, $expected, $updates);
        if ($updated === null) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }

        return $this->response((array) $updated, 200, $c, $expected + 1);
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
        $task = $this->store->findForAssignee($taskId, $p['user_id']);

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
        }
        if (! $this->store->workflowStepExists($stepId)) {
            return $this->problem(404, 'resource-not-found', 'The workflow step is not available.', $c);
        }
        if (! $this->allowed($p, null, 'tasks.create', $c)) {
            return $this->problem(403, 'access-denied', 'Access denied.', $c);
        } $task = $this->creator->handle([
            'step_id' => $stepId,
            'title' => $request->input('title', 'Workflow task'),
            'description' => $request->input('description'),
            'owner_organization_unit_id' => $p['facility_id'] ?? null,
            'assignee_user_id' => $p['user_id'],
            'source_module' => 'workflow',
            'source_type' => 'workflow_step',
            'source_id' => $stepId,
            'completion_policy' => 'direct',
        ], $p['user_id']);

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
        } $task = $this->store->find($taskId);
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
        if ($expected === null) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        } if ($action === 'complete') {
            try {
                $result = $this->completer->handle($taskId, $p['user_id'], $expected, $key);

                return $this->response($result['task'], 200, $c, (int) $result['task']['lock_version']);
            } catch (StaleTaskVersion) {
                return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
            } catch (TaskIdempotencyConflict $e) {
                return $this->problem(409, 'idempotency-conflict', $e->getMessage(), $c);
            } catch (\Throwable $e) {
                return $this->problem(409, 'task-transition-failed', $e->getMessage(), $c);
            }
        }
        try {
            $updated = $this->transitioner->handle($taskId, $expected, $action, $p['user_id'], $key);
        } catch (StaleTaskVersion) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        } catch (TaskIdempotencyConflict $e) {
            return $this->problem(409, 'idempotency-conflict', $e->getMessage(), $c);
        }
        if ($updated === null) {
            return $this->problem(409, 'invalid-task-transition', 'The task action is not supported.', $c);
        }

        return $this->response((array) $updated, 200, $c, (int) $updated->lock_version);
    }

    private function isUuidV7(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }

    private function isUtcDateTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/', $value) === 1;
    }

    private function isTaskPriority(mixed $value): bool
    {
        return is_string($value) && in_array($value, ['low', 'normal', 'high', 'critical'], true);
    }

    private function isTaskClassification(mixed $value): bool
    {
        return is_string($value) && in_array($value, ['public', 'internal', 'confidential', 'top_secret'], true);
    }

    private function isTaskCompletionPolicy(mixed $value): bool
    {
        return is_string($value) && in_array($value, ['direct', 'requires_acceptance'], true);
    }

    private function normalizeSourceReference(mixed $source): ?array
    {
        if ($source === null) {
            return null;
        }
        if (! is_array($source)) {
            return null;
        }
        if (! is_string($source['source_module'] ?? null) || preg_match('/\A[a-z][a-z0-9_]{1,63}\z/', $source['source_module']) !== 1) {
            return null;
        }
        if (! is_string($source['record_type'] ?? null) || $source['record_type'] === '' || mb_strlen($source['record_type']) > 128) {
            return null;
        }
        if (! $this->isUuidV7($source['record_id'] ?? null)) {
            return null;
        }

        return [
            'source_module' => $source['source_module'],
            'record_type' => $source['record_type'],
            'record_id' => $source['record_id'],
        ];
    }

    private function allowed(array $principal, ?\stdClass $task, string $capability, string $correlationId, ?string $ownerUnitId = null): bool
    {
        $scopeId = $ownerUnitId ?? ($task->owner_organization_unit_id ?? null) ?? ($principal['facility_id'] ?? null);
        $participants = $task === null ? [] : $this->store->participantIds((string) $task->id);

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
