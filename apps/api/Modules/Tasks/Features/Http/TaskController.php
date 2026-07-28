<?php

declare(strict_types=1);

namespace Modules\Tasks\Features\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Identity\Contracts\ResolvePersonForUser;
use Modules\Organization\Contracts\ResolvePersonOrganizationScope;
use Modules\Organization\Contracts\ResolveScopeDescendants;
use Modules\Tasks\Application\TaskAccessPolicy;
use Modules\Tasks\Contracts\RecordTaskNotifications;
use Modules\Tasks\Domain\TaskIdempotencyConflict;
use Modules\Tasks\Features\CreateTask\Handler\CreateTaskHandler;
use Modules\Tasks\Features\TransitionTask\Exception\TaskTransitionConflict;
use Modules\Tasks\Features\TransitionTask\Handler\StaleTaskVersion;
use Modules\Tasks\Features\TransitionTask\Handler\TransitionTaskHandler;
use Modules\Tasks\Infrastructure\Persistence\TaskHttpStore;
use stdClass;

/**
 * Task surface: list/create/show/update/transition. The handler is built
 * around the Tasks-owned store, the central authorization decision engine,
 * the task state machine in TransitionTaskHandler, the Tasks-owned
 * CreateTaskHandler, and the in-transaction notifications contract.
 */
final class TaskController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $resolver,
        private readonly DecideAccess $access,
        private readonly TaskHttpStore $store,
        private readonly CreateTaskHandler $creator,
        private readonly TransitionTaskHandler $transitioner,
        private readonly RecordTaskNotifications $notifications,
        private readonly TaskAccessPolicy $policy,
        private readonly ResolveScopeDescendants $scopeDescendants,
        private readonly ResolvePersonOrganizationScope $personScope,
        private readonly ResolvePersonForUser $personForUser,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }

        $state = $request->query('state');
        $relationship = $request->query('relationship');
        $cursor = $request->query('cursor');

        $stateFilter = is_string($state) && $state !== '' ? $state : null;
        $relationshipFilter = is_string($relationship) && $relationship !== '' ? $relationship : null;
        $cursorFilter = is_string($cursor) && $cursor !== '' ? $cursor : null;

        $limit = (int) $request->query('limit', 50);
        if ($limit < 1 || $limit > 100) {
            return $this->problem(400, 'invalid-pagination', 'The collection parameters are invalid.', $c);
        }

        $rows = $this->store->listVisibleFor($p['user_id'], $stateFilter, $relationshipFilter, $cursorFilter, $limit);
        $hasNextPage = count($rows) > $limit;
        if ($hasNextPage) {
            array_pop($rows);
        }

        return response()->json([
            'items' => array_map(fn (stdClass $row): array => $this->serializeRow($row, $p, $c), $rows),
            'next_cursor' => $hasNextPage && $rows !== [] ? (string) end($rows)->id : null,
        ])->header('X-Correlation-ID', $c);
    }

    public function store(Request $request): JsonResponse
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }

        $key = $this->commandKey($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        }

        $payload = $request->json()->all();
        $title = $payload['title'] ?? null;
        if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 255) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        $assigneeUserId = array_key_exists('assignee_user_id', $payload) ? $payload['assignee_user_id'] : $p['user_id'];
        if (! $this->isUuidV7($assigneeUserId)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        $priority = $payload['priority'] ?? 'normal';
        if (! $this->isPriority($priority)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        $classification = $payload['classification'] ?? 'internal';
        if (! $this->isClassification($classification)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        $dueAt = $payload['due_at'] ?? null;
        if ($dueAt !== null && ! $this->isUtcDateTime($dueAt)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        $ownerUnitId = $payload['owner_organization_unit_id'] ?? null;
        if ($ownerUnitId !== null && ! $this->isUuidV7($ownerUnitId)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
        $participants = $payload['participant_user_ids'] ?? [];
        if (! is_array($participants) || array_filter($participants, fn ($m): bool => ! is_string($m) || ! $this->isUuidV7($m)) !== []) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }

        $isSelfTask = $assigneeUserId === $p['user_id'];
        $placeholder = $this->placeholderTask($ownerUnitId, $p, $assigneeUserId, $classification);
        $actor = [
            'user_id' => $p['user_id'],
            'facility_id' => $p['facility_id'] ?? null,
            'organization_unit_ids' => array_filter([$p['facility_id'] ?? null]),
            'correlation_id' => $c,
        ];

        if ($isSelfTask) {
            if (! $this->access->decide($actor, 'tasks.create', $this->policy->factsFor($placeholder, []))->isAllowed()) {
                return $this->problem(403, 'access-denied', 'Access denied.', $c);
            }
        } else {
            // Assigning another user requires tasks.assign AND the target must
            // belong to a facility/unit inside the actor's manageable scope.
            if (! $this->access->decide($actor, 'tasks.assign', $this->policy->factsFor($placeholder, []))->isAllowed()) {
                return $this->problem(403, 'access-denied', 'Access denied.', $c);
            }
            if (! $this->isTargetWithinActorScope($p, $assigneeUserId)) {
                return $this->problem(422, 'invalid-task', "The assignee is outside the actor's manageable scope.", $c);
            }
        }

        try {
            $result = $this->creator->handle([
                'title' => $title,
                'description' => $payload['description'] ?? null,
                'assignee_user_id' => $assigneeUserId,
                'owner_organization_unit_id' => $ownerUnitId,
                'priority' => $priority,
                'due_at' => $dueAt,
                'classification' => $classification,
                'participant_user_ids' => $participants,
                'source' => $payload['source'] ?? null,
            ], $p, $key);
        } catch (TaskIdempotencyConflict $e) {
            return $this->problem(409, 'idempotency-conflict', $e->getMessage(), $c);
        }

        return response()->json(['data' => $result], 201)
            ->header('X-Correlation-ID', $c)
            ->header('ETag', '"'.((int) $result['lock_version']).'"');
    }

    public function show(Request $request, string $taskId): JsonResponse
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        if (! $this->isUuidV7($taskId)) {
            return $this->problem(404, 'resource-not-found', 'The task is not available.', $c);
        }
        $task = $this->store->findVisible($taskId, $p['user_id']);
        if ($task === null) {
            return $this->problem(404, 'resource-not-found', 'The task is not available.', $c);
        }
        $actor = [
            'user_id' => $p['user_id'],
            'facility_id' => $p['facility_id'] ?? null,
            'organization_unit_ids' => array_filter([$p['facility_id'] ?? null]),
            'correlation_id' => $c,
        ];
        if (! $this->access->decide($actor, 'tasks.read', $this->policy->factsFor($task, $this->store->participantIds($taskId)))->isAllowed()) {
            return $this->problem(403, 'access-denied', 'Access denied.', $c);
        }

        $payload = $this->serializeRow($task, $p, $c, true);

        return response()->json(['data' => $payload], 200)
            ->header('X-Correlation-ID', $c)
            ->header('ETag', '"'.((int) $task->lock_version).'"');
    }

    public function update(Request $request, string $taskId): JsonResponse
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }

        if (! $this->isUuidV7($taskId)) {
            return $this->problem(404, 'resource-not-found', 'The task is not available.', $c);
        }

        $task = $this->store->find($taskId);
        if ($task === null) {
            return $this->problem(404, 'resource-not-found', 'The task is not available.', $c);
        }

        $expected = $this->versionFromMatch($request);
        if ($expected === null) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }
        if ($expected !== (int) $task->lock_version) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }

        $payload = $request->json()->all();
        $updates = [];
        $notifyOnTitle = false;
        $notifyOnPriority = false;
        $notifyOnDueAt = false;
        $previousAssignee = (string) $task->assignee_user_id;
        $isReassignment = false;

        if (array_key_exists('title', $payload)) {
            if (! is_string($payload['title']) || trim($payload['title']) === '' || mb_strlen($payload['title']) > 255) {
                return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
            }
            $updates['title'] = $payload['title'];
            $notifyOnTitle = true;
        }
        if (array_key_exists('description', $payload)) {
            if (! is_string($payload['description']) || mb_strlen($payload['description']) > 4000) {
                return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
            }
            $updates['description'] = $payload['description'];
        }
        if (array_key_exists('priority', $payload)) {
            if (! $this->isPriority($payload['priority'])) {
                return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
            }
            $updates['priority'] = $payload['priority'];
            $notifyOnPriority = true;
        }
        if (array_key_exists('due_at', $payload)) {
            if (! $this->isUtcDateTime($payload['due_at'])) {
                return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
            }
            $updates['due_at'] = $payload['due_at'];
            $notifyOnDueAt = true;
        }
        if (array_key_exists('assignee_user_id', $payload)) {
            if (! $this->isUuidV7($payload['assignee_user_id'])) {
                return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
            }
            $isReassignment = $payload['assignee_user_id'] !== $previousAssignee;
            $updates['assignee_user_id'] = $payload['assignee_user_id'];
        }

        if ($updates === []) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }

        $actor = [
            'user_id' => $p['user_id'],
            'facility_id' => $p['facility_id'] ?? null,
            'organization_unit_ids' => array_filter([$p['facility_id'] ?? null]),
            'correlation_id' => $c,
        ];
        $participants = $this->store->participantIds($taskId);

        // Field edits: creator OR authorized manager (tasks.assign path).
        $canEdit = $p['user_id'] === (string) $task->created_by_user_id
            || $this->access->decide($actor, 'tasks.assign', $this->policy->factsFor($task, $participants))->isAllowed();
        if (! $canEdit) {
            return $this->problem(403, 'access-denied', 'Access denied.', $c);
        }

        if ($isReassignment) {
            if (! $this->access->decide($actor, 'tasks.assign', $this->policy->factsFor($task, $participants))->isAllowed()) {
                return $this->problem(403, 'access-denied', 'Access denied.', $c);
            }
            $newAssignee = (string) $payload['assignee_user_id'];
            if (! $this->isTargetWithinActorScope($p, $newAssignee)) {
                return $this->problem(422, 'invalid-task', "The assignee is outside the actor's manageable scope.", $c);
            }
        }

        try {
            $updated = $this->store->update($taskId, $expected, $updates);
        } catch (InvalidArgumentException) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }
        if ($updated === null) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }

        if ($notifyOnTitle || $notifyOnPriority || $notifyOnDueAt) {
            $this->notifications->record(
                $this->recipientsExcludingActor($updated, $p['user_id']),
                'task.updated',
                [
                    'task_id' => $taskId,
                    'title' => (string) $updated->title,
                    'actor_user_id' => $p['user_id'],
                    'changed' => array_values(array_filter([
                        $notifyOnTitle ? 'title' : null,
                        $notifyOnPriority ? 'priority' : null,
                        $notifyOnDueAt ? 'due_at' : null,
                    ])),
                ],
            );
        }

        if ($isReassignment) {
            $this->notifications->record(
                array_values(array_unique(array_filter([$previousAssignee, (string) $updated->assignee_user_id]))),
                'task.reassigned',
                [
                    'task_id' => $taskId,
                    'title' => (string) $updated->title,
                    'actor_user_id' => $p['user_id'],
                    'previous_assignee_user_id' => $previousAssignee,
                    'new_assignee_user_id' => (string) $updated->assignee_user_id,
                ],
            );
        }

        $serialized = $this->serializeRow($updated, $p, $c, true);

        return response()->json(['data' => $serialized], 200)
            ->header('X-Correlation-ID', $c)
            ->header('ETag', '"'.((int) $updated->lock_version).'"');
    }

    public function transition(Request $request, string $taskId, string $action): JsonResponse
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }

        $key = $this->commandKey($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        }
        if (! $this->isUuidV7($taskId)) {
            return $this->problem(404, 'resource-not-found', 'The task is not available.', $c);
        }
        $task = $this->store->find($taskId);
        if ($task === null) {
            return $this->problem(404, 'resource-not-found', 'The task is not available.', $c);
        }

        $expected = $this->versionFromMatch($request);
        if ($expected === null) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }

        $body = $request->json()->all();
        $reason = $body['reason'] ?? null;
        $note = $body['note'] ?? null;

        // Cancel additionally allows an authorized manager (tasks.assign at a
        // covering scope); the handler receives the result as a flag.
        $isManager = false;
        if ($action === 'cancel' && $p['user_id'] !== (string) $task->created_by_user_id) {
            $isManager = $this->access->decide(
                [
                    'user_id' => $p['user_id'],
                    'facility_id' => $p['facility_id'] ?? null,
                    'organization_unit_ids' => array_filter([$p['facility_id'] ?? null]),
                    'correlation_id' => $c,
                ],
                'tasks.assign',
                $this->policy->factsFor($task, $this->store->participantIds($taskId)),
            )->isAllowed();
        }

        try {
            $result = $this->transitioner->handle(
                $taskId,
                $expected,
                $action,
                $p,
                $key,
                is_string($reason) ? $reason : null,
                is_string($note) ? $note : null,
                $isManager,
            );
        } catch (StaleTaskVersion) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        } catch (TaskIdempotencyConflict $e) {
            return $this->problem(409, 'idempotency-conflict', $e->getMessage(), $c);
        } catch (TaskTransitionConflict $e) {
            $code = $e->getMessage();
            if ($code === 'unknown_action') {
                return $this->problem(404, 'resource-not-found', 'The task action is not supported.', $c);
            }
            if ($code === 'reason_required' || $code === 'note_required') {
                return $this->problem(422, 'invalid-task', $code === 'reason_required'
                    ? 'A reason is required for this action.'
                    : 'A completion note is required for this action.', $c);
            }
            if ($code === 'actor_not_authorized') {
                return $this->problem(403, 'access-denied', 'Access denied.', $c);
            }

            return $this->problem(409, 'invalid-task-transition', 'The task transition is not permitted.', $c);
        } catch (InvalidArgumentException) {
            return $this->problem(404, 'resource-not-found', 'The task is not available.', $c);
        }

        return response()->json(['data' => $result], 200)
            ->header('X-Correlation-ID', $c)
            ->header('ETag', '"'.((int) $result['lock_version']).'"');
    }

    /**
     * @param  array{user_id: string, facility_id?: ?string}  $principal
     * @return array<string, mixed>
     */
    private function serializeRow(stdClass $task, array $principal, string $correlation, bool $withDetails = false): array
    {
        $participants = $this->store->participantIds((string) $task->id);
        $details = [
            'description' => $task->description,
            'due_at' => $task->due_at,
            'source_module' => $task->source_module,
            'source_type' => $task->source_type,
            'source_id' => $task->source_id,
            'workflow_step_id' => $task->workflow_step_id,
        ];

        if ($withDetails) {
            $details['participants'] = array_map(static fn (stdClass $row): array => [
                'user_id' => (string) $row->user_id,
                'role' => (string) $row->role,
                'added_by_user_id' => (string) $row->added_by_user_id,
                'created_at' => $row->created_at,
            ], $this->store->participantsWithRoles((string) $task->id));
            $details['attachments'] = array_map(static fn (stdClass $row): array => [
                'document_id' => (string) $row->document_id,
                'title' => $row->title,
                'linked_by_user_id' => (string) $row->linked_by_user_id,
                'created_at' => $row->created_at,
            ], $this->store->attachmentsFor((string) $task->id));
            $details['comments_summary'] = $this->store->commentsSummary((string) $task->id);
            $details['allowed_actions'] = $this->policy->allowedActions($task, $principal, $correlation);
        }

        return [
            'id' => (string) $task->id,
            'title' => (string) $task->title,
            'state' => (string) $task->status,
            'classification' => (string) $task->classification,
            'priority' => (string) $task->priority,
            'assignee_user_id' => (string) $task->assignee_user_id,
            'creator_user_id' => (string) $task->created_by_user_id,
            'participant_user_ids' => array_values(array_unique($participants)),
            ...$details,
            'lock_version' => (int) $task->lock_version,
            'created_at' => $task->created_at,
            'updated_at' => $task->updated_at,
        ];
    }

    /**
     * @param  array{user_id: string, facility_id?: ?string}  $principal
     */
    private function placeholderTask(?string $ownerUnitId, array $principal, string $assigneeUserId, string $classification): stdClass
    {
        $row = new stdClass;
        $row->id = '';
        $row->title = 'placeholder';
        $row->description = null;
        $row->created_by_user_id = $principal['user_id'];
        $row->assignee_user_id = $assigneeUserId;
        $row->owner_organization_unit_id = $ownerUnitId ?? ($principal['facility_id'] ?? null);
        $row->status = 'open';
        $row->priority = 'normal';
        $row->classification = $classification;
        $row->lock_version = 0;
        $row->created_at = null;
        $row->updated_at = null;

        return $row;
    }

    /**
     * True when the target user sits inside the actor's manageable scope:
     * the actor's facility or any of its descendant scopes. Resolved through
     * the Organization contracts (users → person → person scope) — never raw
     * cross-module SQL.
     *
     * @param  array{user_id: string, facility_id?: ?string}  $principal
     */
    private function isTargetWithinActorScope(array $principal, string $targetUserId): bool
    {
        $facilityId = $principal['facility_id'] ?? null;
        if (! is_string($facilityId) || $facilityId === '') {
            return false;
        }

        $allowed = [$facilityId];
        foreach ($this->scopeDescendants->descendants('facility', $facilityId) as $scope) {
            $allowed[] = $scope['scope_id'];
        }
        $allowed = array_values(array_unique($allowed));

        $personId = $this->personForUser->forUser($targetUserId);
        if ($personId === null) {
            return false;
        }

        $target = $this->personScope->forPerson($personId);

        return array_intersect($allowed, [...$target['facility_ids'], ...$target['organization_unit_ids']]) !== [];
    }

    /**
     * @return list<string>
     */
    private function recipientsExcludingActor(stdClass $task, string $actorUserId): array
    {
        $recipients = [(string) $task->created_by_user_id, (string) $task->assignee_user_id];
        foreach ($this->store->participantIds((string) $task->id) as $userId) {
            $recipients[] = $userId;
        }

        return array_values(array_unique(array_filter(
            $recipients,
            static fn (string $userId): bool => $userId !== '' && $userId !== $actorUserId,
        )));
    }

    private function principal(Request $request): ?array
    {
        return $this->resolver->resolve($request);
    }

    private function correlation(Request $request): ?string
    {
        $value = $request->header('X-Correlation-ID');

        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1 ? $value : null;
    }

    private function commandKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');

        return is_string($key) && preg_match('/\A[\x21-\x7E]{1,255}\z/', $key) === 1 ? $key : '';
    }

    private function versionFromMatch(Request $request): ?int
    {
        $raw = $request->header('If-Match');
        if (! is_string($raw) || preg_match('/\A"([0-9]+)"\z/', $raw, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function problem(int $status, string $type, string $detail, ?string $correlation = null): JsonResponse
    {
        $response = response()->json([
            'type' => 'https://cluster.example/problems/'.$type,
            'title' => match ($status) {
                400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found', 409 => 'Conflict', 412 => 'Precondition Failed', default => 'Unprocessable Content'
            },
            'status' => $status,
            'detail' => $detail,
        ], $status)->header('Content-Type', 'application/problem+json');

        return $correlation === null ? $response : $response->header('X-Correlation-ID', $correlation);
    }

    private function isUuidV7(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
    }

    private function isUtcDateTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/', $value) === 1;
    }

    private function isPriority(mixed $value): bool
    {
        return is_string($value) && in_array($value, ['low', 'normal', 'high', 'urgent'], true);
    }

    private function isClassification(mixed $value): bool
    {
        return is_string($value) && in_array($value, ['public', 'internal', 'confidential', 'top_secret'], true);
    }
}
