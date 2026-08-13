<?php

declare(strict_types=1);

namespace Modules\Tasks\Features\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Authorization\Contracts\ResolveActiveCapabilityScopesForUser;
use Modules\Identity\Contracts\ListUserDisplayLabels;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Contracts\ListOrganizationScopeTargets;
use Modules\Organization\Contracts\ResolveScopeDescendants;
use Modules\Tasks\Application\TaskAccessPolicy;
use Modules\Tasks\Domain\TaskIdempotencyConflict;
use Modules\Tasks\Features\CreateTask\Handler\CreateTaskHandler;
use Modules\Tasks\Features\TransitionTask\Exception\TaskTransitionConflict;
use Modules\Tasks\Features\TransitionTask\Handler\StaleTaskVersion;
use Modules\Tasks\Features\TransitionTask\Handler\TransitionTaskHandler;
use Modules\Tasks\Features\UpdateTask\Handler\UpdateTaskHandler;
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
        private readonly UpdateTaskHandler $updater,
        private readonly TaskAccessPolicy $policy,
        private readonly ResolveScopeDescendants $scopeDescendants,
        private readonly ListUserDisplayLabels $displayLabels,
        private readonly ListOrganizationScopeTargets $scopeTargets,
        private readonly ResolveActiveCapabilityScopesForUser $capabilityScopes,
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

        $view = $request->query('view', 'mine');
        $hasScopeType = $request->query->has('scope_type');
        $hasScopeId = $request->query->has('scope_id');
        if (! is_string($view) || ! in_array($view, ['mine', 'scope'], true)) {
            return $this->problem(400, 'invalid-task-view', 'The collection parameters are invalid.', $c);
        }
        if ($view === 'mine' && ($hasScopeType || $hasScopeId)) {
            return $this->problem(400, 'invalid-task-view', 'The collection parameters are invalid.', $c);
        }
        if ($view === 'scope' && ($hasScopeType !== true || $hasScopeId !== true || $request->query->has('relationship'))) {
            return $this->problem(400, 'invalid-task-view', 'The collection parameters are invalid.', $c);
        }

        $stateFilter = is_string($state) && $state !== '' ? $state : null;
        $relationshipFilter = is_string($relationship) && $relationship !== '' ? $relationship : null;
        $cursorFilter = is_string($cursor) && $cursor !== '' ? $cursor : null;

        $limit = (int) $request->query('limit', 50);
        if ($limit < 1 || $limit > 100) {
            return $this->problem(400, 'invalid-pagination', 'The collection parameters are invalid.', $c);
        }

        $ownerScopeIds = null;
        if ($view === 'scope') {
            $scopeType = $request->query('scope_type');
            $scopeId = $request->query('scope_id');
            if (! is_string($scopeType) || ! in_array($scopeType, ['cluster', 'facility', 'unit'], true)
                || ! is_string($scopeId) || ! $this->isUuidV7($scopeId)) {
                return $this->problem(400, 'invalid-task-view', 'The collection parameters are invalid.', $c);
            }

            $scopeFacts = $this->policy->factsForRequestedScope($scopeType, $scopeId);
            $actor = [
                'user_id' => $p['user_id'],
                'facility_id' => $p['facility_id'] ?? null,
                'organization_unit_ids' => array_filter([$p['facility_id'] ?? null]),
                'correlation_id' => $c,
            ];
            if ($scopeFacts === null || ! $this->access->decide($actor, 'tasks.read', $scopeFacts)->isAllowed()) {
                return $this->problem(403, 'access-denied', 'Access denied.', $c);
            }

            $ownerScopeIds = $this->ownerScopeIds($scopeType, $scopeId);
        }

        [$rows, $hasNextPage, $nextCursor] = $this->authorizedPage(
            $p,
            $c,
            $stateFilter,
            $relationshipFilter,
            $cursorFilter,
            $limit,
            $ownerScopeIds,
        );

        $projection = $this->serializeCollection(
            $rows,
            $p,
            $c,
            $this->availableTaskScopes($p, $c),
        );

        return response()->json([
            'items' => $projection['items'],
            'next_cursor' => $hasNextPage ? $nextCursor : null,
            'available_scopes' => $projection['available_scopes'],
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
        if (array_key_exists('source', $payload)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $c);
        }
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
        $ownerUnitId = $payload['owner_organization_unit_id'] ?? ($p['facility_id'] ?? null);
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
            if (! $this->access->decide($actor, 'tasks.assign', $this->policy->factsFor($placeholder, []))->isAllowed()) {
                return $this->problem(403, 'access-denied', 'Access denied.', $c);
            }
        }

        if (! $this->policy->participantIsWithinOwnerFacility($placeholder, $assigneeUserId)) {
            return $this->problem(422, 'invalid-task', "The assignee is outside the task's facility scope.", $c);
        }

        foreach (array_values(array_unique($participants)) as $participantUserId) {
            if (! $this->policy->participantIsWithinOwnerFacility($placeholder, $participantUserId)) {
                return $this->problem(422, 'invalid-task', "A participant is outside the task's facility scope.", $c);
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

        $task = $this->store->findVisible($taskId, $p['user_id']);
        if ($task === null) {
            // Unrelated users must not learn whether the task exists. A
            // manager holding tasks.assign may still edit it, so probe the
            // capability gate before answering 404.
            $probe = $this->store->find($taskId);
            if ($probe === null) {
                return $this->problem(404, 'resource-not-found', 'The task is not available.', $c);
            }
            $probeActor = [
                'user_id' => $p['user_id'],
                'facility_id' => $p['facility_id'] ?? null,
                'organization_unit_ids' => array_filter([$p['facility_id'] ?? null]),
                'correlation_id' => $c,
            ];
            $probeFacts = $this->policy->factsFor($probe, $this->store->participantIds($taskId));
            if (! $this->access->decide($probeActor, 'tasks.update', $probeFacts)->isAllowed()
                && ! $this->access->decide($probeActor, 'tasks.assign', $probeFacts)->isAllowed()) {
                return $this->problem(404, 'resource-not-found', 'The task is not available.', $c);
            }
            $task = $probe;
        }

        // Terminal states are immutable: completed/cancelled tasks are a
        // closed record. Only the transition endpoint may move them, and it
        // refuses terminal-source transitions, so edits must be rejected here
        // regardless of the caller's role.
        if (in_array((string) $task->status, ['completed', 'cancelled'], true)) {
            return $this->problem(409, 'task-terminal-state', 'The task is completed or cancelled and cannot be edited.', $c);
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

        $facts = $this->policy->factsFor($task, $participants);
        $hasFieldUpdates = array_diff(array_keys($updates), ['assignee_user_id']) !== [];
        if ($hasFieldUpdates && ! $this->access->decide($actor, 'tasks.update', $facts)->isAllowed()) {
            return $this->problem(403, 'access-denied', 'Access denied.', $c);
        }

        if (array_key_exists('assignee_user_id', $updates)) {
            if (! $this->access->decide($actor, 'tasks.assign', $facts)->isAllowed()) {
                return $this->problem(403, 'access-denied', 'Access denied.', $c);
            }
        }

        if ($isReassignment) {
            $newAssignee = (string) $payload['assignee_user_id'];
            if (! $this->policy->participantIsWithinOwnerFacility($task, $newAssignee)) {
                return $this->problem(422, 'invalid-task', "The assignee is outside the task's facility scope.", $c);
            }
        }

        $changedNotificationFields = array_values(array_filter([
            $notifyOnTitle ? 'title' : null,
            $notifyOnPriority ? 'priority' : null,
            $notifyOnDueAt ? 'due_at' : null,
        ]));
        try {
            $updated = $this->updater->handle($taskId, $expected, $updates, $p['user_id'], $changedNotificationFields);
        } catch (InvalidArgumentException) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
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

        $capability = match ($action) {
            'start' => 'tasks.start',
            'block', 'unblock' => 'tasks.update',
            'complete' => 'tasks.complete',
            'cancel' => 'tasks.cancel',
            default => null,
        };
        if ($capability === null) {
            return $this->problem(404, 'resource-not-found', 'The task action is not supported.', $c);
        }
        $transitionAllowed = $this->access->decide([
            'user_id' => $p['user_id'],
            'facility_id' => $p['facility_id'] ?? null,
            'organization_unit_ids' => array_filter([$p['facility_id'] ?? null]),
            'correlation_id' => $c,
        ], $capability, $this->policy->factsFor($task, $this->store->participantIds($taskId)))->isAllowed();
        if (! $transitionAllowed) {
            return $this->problem(403, 'access-denied', 'Access denied.', $c);
        }
        $isManager = $action === 'cancel' && $p['user_id'] !== (string) $task->created_by_user_id;

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
     * Keeps collection-only presentation enrichment batched. Detail and
     * mutation responses intentionally retain their existing row serializer,
     * so this does not add repeated cross-module reads to those paths.
     *
     * @param  list<array{task: stdClass, facts: RecordFacts}>  $records
     * @param  array{user_id: string, facility_id?: ?string}  $principal
     * @param  list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}>  $availableScopes
     * @return array{items: list<array<string, mixed>>, available_scopes: list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string, label: string}>}
     */
    private function serializeCollection(array $records, array $principal, string $correlation, array $availableScopes): array
    {
        $userIds = [];
        $scopeCandidates = ['cluster' => [], 'facility' => [], 'unit' => []];
        $candidateByTaskId = [];

        foreach ($availableScopes as $scope) {
            $scopeCandidates[$scope['scope_type']][$scope['scope_id']] = $scope;
        }

        foreach ($records as $record) {
            $task = $record['task'];
            $userIds[] = (string) $task->assignee_user_id;
            $userIds[] = (string) $task->created_by_user_id;
            $candidate = $this->ownerScopeCandidate($task, $record['facts']);
            if ($candidate !== null) {
                $scopeCandidates[$candidate['scope_type']][$candidate['scope_id']] = $candidate;
                $candidateByTaskId[(string) $task->id] = $candidate;
            }
        }

        $labels = $this->displayLabels->labelsFor(array_values(array_unique($userIds)));
        $scopeLabels = $this->scopeLabels($scopeCandidates);
        $items = [];

        foreach ($records as $record) {
            $task = $record['task'];
            $assigneeUserId = (string) $task->assignee_user_id;
            $creatorUserId = (string) $task->created_by_user_id;
            $item = $this->serializeRow($task, $principal, $correlation);
            $item['assignee'] = [
                'user_id' => $assigneeUserId,
                'display_name' => $this->displayName($labels, $assigneeUserId),
            ];
            $item['creator'] = [
                'user_id' => $creatorUserId,
                'display_name' => $this->displayName($labels, $creatorUserId),
            ];

            $candidate = $candidateByTaskId[(string) $task->id] ?? null;
            if ($candidate !== null) {
                $key = $candidate['scope_type'].'|'.$candidate['scope_id'];
                $resolved = $scopeLabels[$key] ?? null;
                $code = is_array($resolved) ? $resolved['code'] : null;
                $item['owner_scope'] = [
                    'scope_type' => $candidate['scope_type'],
                    'scope_id' => $candidate['scope_id'],
                    'label' => $resolved['label'] ?? $candidate['scope_id'],
                    ...($code !== null ? ['code' => $code] : []),
                ];
            }

            $items[] = $item;
        }

        $scopeOptions = [];
        foreach ($availableScopes as $scope) {
            $key = $scope['scope_type'].'|'.$scope['scope_id'];
            $scopeOptions[] = [
                ...$scope,
                'label' => $scopeLabels[$key]['label'] ?? $scope['scope_id'],
            ];
        }

        return ['items' => $items, 'available_scopes' => $scopeOptions];
    }

    /**
     * @param  array{user_id: string, facility_id?: ?string}  $principal
     * @return list<array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}>
     */
    private function availableTaskScopes(array $principal, string $correlation): array
    {
        $actor = [
            'user_id' => $principal['user_id'],
            'facility_id' => $principal['facility_id'] ?? null,
            'organization_unit_ids' => array_filter([$principal['facility_id'] ?? null]),
            'correlation_id' => $correlation,
        ];

        $available = [];
        foreach ($this->capabilityScopes->roots($principal['user_id'], 'tasks.read') as $scope) {
            $facts = $this->policy->factsForRequestedScope($scope['scope_type'], $scope['scope_id']);
            if ($facts !== null && $this->access->decide($actor, 'tasks.read', $facts)->isAllowed()) {
                $available[] = $scope;
            }
        }

        return $available;
    }

    /**
     * The access policy facts were already computed for the authorization
     * decision. Their exact owner field identifies the stored owner type, so
     * the projection does not infer it by probing Organization tables.
     *
     * @return array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}|null
     */
    private function ownerScopeCandidate(stdClass $task, RecordFacts $facts): ?array
    {
        $ownerId = $task->owner_organization_unit_id;
        if (! is_string($ownerId) || $ownerId === '') {
            return null;
        }

        if ($facts->organizationUnitId !== null && hash_equals($ownerId, $facts->organizationUnitId)) {
            return ['scope_type' => 'unit', 'scope_id' => $ownerId];
        }
        if ($facts->ownerFacilityId !== null && hash_equals($ownerId, $facts->ownerFacilityId)) {
            return ['scope_type' => 'facility', 'scope_id' => $ownerId];
        }
        if ($facts->clusterId !== null && hash_equals($ownerId, $facts->clusterId)) {
            return ['scope_type' => 'cluster', 'scope_id' => $ownerId];
        }

        return null;
    }

    /**
     * @param  array{'cluster': array<string, array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}>, 'facility': array<string, array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}>, 'unit': array<string, array{scope_type: 'cluster'|'facility'|'unit', scope_id: string}>}  $candidatesByType
     * @return array<string, array{label: string, code: ?string}>
     */
    private function scopeLabels(array $candidatesByType): array
    {
        $labels = [];
        foreach ($candidatesByType as $scopeType => $candidates) {
            if ($candidates === []) {
                continue;
            }

            foreach ($this->scopeTargets->labelCandidates($scopeType, array_values($candidates), null) as $resolved) {
                $label = trim($resolved['label_ar']);
                if ($label === '') {
                    continue;
                }
                $code = trim((string) ($resolved['code'] ?? ''));
                $labels[$resolved['scope_type'].'|'.$resolved['scope_id']] = [
                    'label' => $label,
                    'code' => $code === '' ? null : $code,
                ];
            }
        }

        return $labels;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function displayName(array $labels, string $userId): string
    {
        $label = $labels[$userId] ?? null;

        return is_string($label) && trim($label) !== '' ? trim($label) : $userId;
    }

    /**
     * @param  array{user_id: string, facility_id?: ?string}  $principal
     * @param  list<string>|null  $ownerScopeIds
     * @return array{0: list<array{task: stdClass, facts: RecordFacts}>, 1: bool, 2: ?string}
     */
    private function authorizedPage(
        array $principal,
        string $correlation,
        ?string $state,
        ?string $relationship,
        ?string $cursor,
        int $limit,
        ?array $ownerScopeIds = null,
    ): array {
        $actor = [
            'user_id' => $principal['user_id'],
            'facility_id' => $principal['facility_id'] ?? null,
            'organization_unit_ids' => array_filter([$principal['facility_id'] ?? null]),
            'correlation_id' => $correlation,
        ];
        $authorized = [];
        $scanCursor = $cursor;
        $remainingCandidates = min(500, max(100, ($limit + 1) * 5));
        $hasMoreCandidates = false;

        do {
            $batchLimit = min(100, $remainingCandidates);
            $candidates = $ownerScopeIds === null
                ? $this->store->listVisibleFor(
                    $principal['user_id'],
                    $state,
                    $relationship,
                    $scanCursor,
                    $batchLimit,
                )
                : $this->store->listForOwnerScopeIds(
                    $ownerScopeIds,
                    $state,
                    $scanCursor,
                    $batchLimit,
                );
            $hasMoreCandidates = count($candidates) > $batchLimit;
            if ($hasMoreCandidates) {
                array_pop($candidates);
            }
            $remainingCandidates -= count($candidates);

            foreach ($candidates as $candidate) {
                $scanCursor = (string) $candidate->id;
                $facts = $this->policy->factsFor($candidate, $this->store->participantIds($scanCursor));
                if ($this->access->decide($actor, 'tasks.read', $facts)->isAllowed()) {
                    $authorized[] = ['task' => $candidate, 'facts' => $facts];
                }
            }
        } while (count($authorized) <= $limit && $hasMoreCandidates && $remainingCandidates > 0);

        $hasNextPage = count($authorized) > $limit || ($hasMoreCandidates && $remainingCandidates <= 0);
        if (count($authorized) > $limit) {
            $authorized = array_slice($authorized, 0, $limit);
        }
        $nextCursor = $hasNextPage
            ? ($authorized !== [] ? (string) end($authorized)['task']->id : $scanCursor)
            : null;

        return [$authorized, $hasNextPage, $nextCursor];
    }

    /** @return list<string> */
    private function ownerScopeIds(string $scopeType, string $scopeId): array
    {
        $ownerScopeIds = match ($scopeType) {
            'unit' => [$scopeId],
            'facility' => array_merge(
                [$scopeId],
                $this->descendantScopeIds('facility', $scopeId, ['unit']),
            ),
            'cluster' => array_merge(
                [$scopeId],
                $this->descendantScopeIds('cluster', $scopeId, ['facility', 'unit']),
            ),
            default => [],
        };

        return array_values(array_unique(array_filter(
            $ownerScopeIds,
            static fn (string $scopeId): bool => $scopeId !== '',
        )));
    }

    /**
     * @param  list<'facility'|'unit'>  $allowedScopeTypes
     * @return list<string>
     */
    private function descendantScopeIds(string $scopeType, string $scopeId, array $allowedScopeTypes): array
    {
        return array_values(array_filter(array_map(
            static fn (array $scope): ?string => in_array($scope['scope_type'], $allowedScopeTypes, true)
                ? $scope['scope_id']
                : null,
            $this->scopeDescendants->descendants($scopeType, $scopeId),
        ), static fn (?string $scopeId): bool => $scopeId !== null && $scopeId !== ''));
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
