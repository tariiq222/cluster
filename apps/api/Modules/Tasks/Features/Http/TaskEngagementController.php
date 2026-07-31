<?php

declare(strict_types=1);

namespace Modules\Tasks\Features\Http;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\ResolveActiveFacilityScopesForUser;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Tasks\Application\TaskAccessPolicy;
use Modules\Tasks\Contracts\RecordTaskNotifications;
use Modules\Tasks\Infrastructure\Persistence\TaskHttpStore;

/**
 * Task engagement surface: participants and comments. Every operation is
 * gated by the central decision with facts built from the Tasks-owned row.
 * Adds notifications: adding a participant notifies the new participant
 * (task.participant_added); adding a comment notifies creator+assignee+
 * participants minus actor plus any newly-authorized mentioned users
 * (task.commented / task.mentioned).
 */
final class TaskEngagementController
{
    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $resolver,
        private readonly DecideAccess $access,
        private readonly TaskHttpStore $store,
        private readonly RecordTaskNotifications $notifications,
        private readonly TaskAccessPolicy $policy,
        private readonly ResolveActiveFacilityScopesForUser $facilityScopes,
    ) {}

    public function addParticipant(Request $request, string $taskId): JsonResponse
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
        if (($deny = $this->denyUnlessAllowed($p, $task, 'tasks.participant-manage', $c)) !== null) {
            return $deny;
        }
        $expected = $this->versionFromMatch($request);
        if ($expected === null || $expected !== (int) $task->lock_version) {
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }
        $v = $request->json()->all();
        if (! is_string($v['user_id'] ?? null) || ! $this->isUuidV7($v['user_id'])) {
            return $this->problem(422, 'invalid-task-participant', 'The request body is invalid.', $c);
        }
        $role = is_string($v['role'] ?? null) && mb_strlen($v['role']) <= 64 ? $v['role'] : 'participant';

        try {
            $updated = $this->store->addParticipant((string) $task->id, $v['user_id'], $role, $p['user_id'], $expected);
        } catch (QueryException) {
            return $this->problem(409, 'task-participant-conflict', 'The participant already exists.', $c);
        }
        if ($updated === null) {
            // CAS race: a concurrent mutation happened; nothing was written.
            return $this->problem(412, 'precondition-failed', 'If-Match does not match the current version.', $c);
        }

        $newParticipant = $v['user_id'];
        $this->notifications->record(
            array_values(array_unique(array_filter(
                [$newParticipant],
                static fn (string $userId): bool => $userId !== $p['user_id'],
            ))),
            'task.participant_added',
            [
                'task_id' => $taskId,
                'title' => (string) $task->title,
                'actor_user_id' => $p['user_id'],
                'participant_user_id' => $newParticipant,
                'role' => $role,
            ],
        );

        return $this->response([
            'id' => (string) $task->id,
            'task_id' => (string) $task->id,
            'user_id' => $newParticipant,
            'role' => $role,
            'lock_version' => $expected + 1,
        ], 200, $c, $expected + 1);
    }

    public function listComments(Request $request, string $taskId): JsonResponse
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
        if (($deny = $this->denyUnlessAllowed($p, $task, 'tasks.read', $c)) !== null) {
            return $deny;
        }
        $limit = (int) $request->query('limit', 50);
        if ($limit < 1 || $limit > 100) {
            return $this->problem(400, 'invalid-pagination', 'The collection parameters are invalid.', $c);
        }
        $cursor = $request->query('cursor');
        $page = $this->store->listComments((string) $task->id, $limit, is_string($cursor) ? $cursor : null);

        return response()->json([
            'items' => array_map(fn (\stdClass $row): array => $this->serializeComment($row), $page['items']),
            'next_cursor' => $page['next_cursor'],
        ])->header('X-Correlation-ID', $c);
    }

    public function addComment(Request $request, string $taskId): JsonResponse
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
        if (($deny = $this->denyUnlessAllowed($p, $task, 'tasks.comment', $c)) !== null) {
            return $deny;
        }
        $v = $request->json()->all();
        if (! is_string($v['body'] ?? null) || trim($v['body']) === '' || mb_strlen($v['body']) > 4000) {
            return $this->problem(422, 'invalid-task-comment', 'The request body is invalid.', $c);
        }
        $mentioned = $v['mentioned_user_ids'] ?? [];
        if (! is_array($mentioned) || array_filter($mentioned, fn ($m): bool => ! is_string($m)) !== []) {
            return $this->problem(422, 'invalid-task-comment', 'The request body is invalid.', $c);
        }

        $comment = $this->store->insertComment((string) $task->id, $p['user_id'], $v['body'], $mentioned);

        $payload = [
            'task_id' => $taskId,
            'title' => (string) $task->title,
            'actor_user_id' => $p['user_id'],
            'comment_id' => (string) $comment->id,
        ];
        $this->notifications->record(
            $this->recipientsExcludingActor($task, $p['user_id']),
            'task.commented',
            $payload,
        );
        foreach (array_values(array_unique(array_filter($mentioned, 'is_string'))) as $mentionedUserId) {
            if ($mentionedUserId === $p['user_id']) {
                continue;
            }
            // Gate the mention notification against the MENTIONED user's
            // authorization on the task — never against the author's. The
            // gate must answer "can $mentionedUserId read this task?" so the
            // notification only lands in the inboxes of users who would
            // actually be allowed to see the task.
            if (! $this->isAllowedToRead($task, $mentionedUserId, $c)) {
                continue;
            }
            $this->notifications->record(
                [$mentionedUserId],
                'task.mentioned',
                $payload + ['mentioned_user_id' => $mentionedUserId],
            );
        }

        return $this->response($this->serializeComment($comment), 201, $c);
    }

    private function denyUnlessAllowed(array $principal, \stdClass $task, string $capability, string $correlationId): ?JsonResponse
    {
        return $this->isAllowed($principal, $task, $capability, $correlationId)
            ? null
            : $this->problem(403, 'access-denied', 'Access denied.', $correlationId);
    }

    /**
     * Authorization gate for an arbitrary mentioned user — used by the
     * task.mentioned notification so recipients only include users who would
     * actually be allowed to read the task. Resolves the user's active
     * facility scopes through the Authorization-owned contract (no direct
     * cross-module SQL) and asks the central decision engine via the
     * non-persisting evaluator.
     */
    private function isAllowedToRead(\stdClass $task, string $userId, string $correlationId): bool
    {
        $facilityScopeIds = $this->facilityScopes->facilityScopeIds($userId);

        return $this->access->evaluateOnly(
            [
                'user_id' => $userId,
                'facility_id' => $facilityScopeIds[0] ?? null,
                'organization_unit_ids' => $facilityScopeIds,
                'correlation_id' => $correlationId,
            ],
            'tasks.read',
            $this->policy->factsFor($task, $this->store->participantIds((string) $task->id)),
        )->isAllowed();
    }

    private function isAllowed(array $principal, \stdClass $task, string $capability, string $correlationId): bool
    {
        $participants = $this->store->participantIds((string) $task->id);

        return $this->access->decide(
            [
                'user_id' => $principal['user_id'],
                'facility_id' => $principal['facility_id'] ?? null,
                'organization_unit_ids' => array_filter([$principal['facility_id'] ?? null]),
                'correlation_id' => $correlationId,
            ],
            $capability,
            $this->policy->factsFor($task, $participants),
        )->isAllowed();
    }

    /**
     * @return list<string>
     */
    private function recipientsExcludingActor(\stdClass $task, string $actorUserId): array
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

    private function serializeComment(\stdClass $row): array
    {
        $mentioned = is_string($row->mentioned_user_ids) ? json_decode($row->mentioned_user_ids, true) : [];

        return [
            'id' => $row->id,
            'task_id' => $row->task_id,
            'author_user_id' => $row->author_user_id,
            'body' => $row->body,
            'mentioned_user_ids' => is_array($mentioned) ? $mentioned : [],
            'created_at' => $row->created_at,
        ];
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

    private function versionFromMatch(Request $request): ?int
    {
        $raw = $request->header('If-Match');
        if (! is_string($raw) || preg_match('/\A"([0-9]+)"\z/', $raw, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function response(array $data, int $status, string $correlation, ?int $version = null): JsonResponse
    {
        $response = response()->json(['data' => $data], $status)->header('X-Correlation-ID', $correlation);
        if ($version !== null) {
            $response->header('ETag', '"'.$version.'"');
        }

        return $response;
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
}
