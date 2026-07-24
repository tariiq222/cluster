<?php

namespace Modules\Tasks\Features\Http;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\RecordFacts;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Tasks\Infrastructure\Persistence\TaskHttpStore;

/**
 * Task engagement surface: participants and comments. Every operation is
 * gated by the central decision with facts built from the Tasks-owned row.
 */
final class TaskEngagementController
{
    use TaskHttpSupport;

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $resolver,
        private readonly DecideAccess $access,
        private readonly TaskHttpStore $store,
    ) {}

    public function addParticipant(Request $request, string $taskId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        }
        $task = $this->store->find($taskId);
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
        if (! is_string($v['user_id'] ?? null) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $v['user_id']) !== 1) {
            return $this->problem(422, 'invalid-task-participant', 'The request body is invalid.', $c);
        }
        $role = is_string($v['role'] ?? null) && mb_strlen($v['role']) <= 64 ? $v['role'] : 'participant';

        try {
            $participant = $this->store->addParticipant($task, $v['user_id'], $role, $p['user_id'], $expected);
        } catch (QueryException) {
            return $this->problem(409, 'task-participant-conflict', 'The participant already exists.', $c);
        }

        return $this->response($participant, 200, $c, $expected + 1);
    }

    public function listComments(Request $request, string $taskId): mixed
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

    public function addComment(Request $request, string $taskId): mixed
    {
        $c = $this->correlation($request);
        if ($c === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }
        $p = $this->principal($request, $this->resolver);
        if ($p === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $c);
        }
        $key = $this->commandHeaders($request);
        if ($key === '') {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $c);
        }
        $task = $this->store->find($taskId);
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

        $comment = $this->store->addComment((string) $task->id, $p['user_id'], $v['body'], $mentioned);

        return $this->response($this->serializeComment($comment), 201, $c);
    }

    private function denyUnlessAllowed(array $principal, \stdClass $task, string $capability, string $correlationId): ?JsonResponse
    {
        $participants = $this->store->participantIds((string) $task->id);
        $decision = $this->access->decide(
            [
                'user_id' => $principal['user_id'],
                'facility_id' => $principal['facility_id'] ?? null,
                'organization_unit_ids' => array_filter([$principal['facility_id'] ?? null]),
                'correlation_id' => $correlationId,
            ],
            $capability,
            new RecordFacts(
                ownerFacilityId: $task->owner_organization_unit_id,
                resourceType: 'task',
                classification: (string) ($task->classification ?? 'internal'),
                organizationUnitId: $task->owner_organization_unit_id,
                recordId: (string) $task->id,
                createdByUserId: (string) $task->created_by_user_id,
                responsibleUserId: (string) $task->assignee_user_id,
                participantIds: array_values(array_filter($participants, 'is_string')),
                lifecycleState: (string) $task->status,
                lockVersion: (int) $task->lock_version,
            ),
        );

        return $decision->isAllowed() ? null : $this->problem(403, 'access-denied', 'Access denied.', $correlationId);
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
}
