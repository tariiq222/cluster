<?php

namespace Modules\Tasks\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Workflow\Contracts\WorkflowStepExists;
use stdClass;

final class TaskHttpStore
{
    public function __construct(
        private readonly WorkflowStepExists $workflowStepExists,
    ) {}

    /** @return list<stdClass> */
    public function listForAssignee(string $userId): array
    {
        return DB::table('tasks')
            ->where('assignee_user_id', $userId)
            ->orderBy('created_at')
            ->get()
            ->all();
    }

    public function find(string $taskId): ?stdClass
    {
        return DB::table('tasks')->where('id', $taskId)->first();
    }

    public function findForUpdate(string $taskId): ?stdClass
    {
        return DB::table('tasks')->where('id', $taskId)->lockForUpdate()->first();
    }

    public function findForAssignee(string $taskId, string $userId): ?stdClass
    {
        return DB::table('tasks')
            ->where('id', $taskId)
            ->where('assignee_user_id', $userId)
            ->first();
    }

    /** @return list<string> */
    public function participantIds(string $taskId): array
    {
        return DB::table('task_participants')
            ->where('task_id', $taskId)
            ->pluck('user_id')
            ->filter(static fn (mixed $value): bool => is_string($value))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $updates */
    public function update(string $taskId, int $expectedVersion, array $updates): ?stdClass
    {
        $updates['lock_version'] = $expectedVersion + 1;
        $updates['updated_at'] = now();
        $affected = DB::table('tasks')
            ->where('id', $taskId)
            ->where('lock_version', $expectedVersion)
            ->update($updates);

        return $affected === 1 ? $this->find($taskId) : null;
    }

    public function workflowStepExists(string $stepId): bool
    {
        return $this->workflowStepExists->exists($stepId);
    }

    public function transition(string $taskId, int $expectedVersion, string $status): ?stdClass
    {
        return $this->update($taskId, $expectedVersion, ['status' => $status]);
    }

    /**
     * @return array{id: string, task_id: string, user_id: string, role: string, lock_version: int}
     */
    public function addParticipant(
        stdClass $task,
        string $userId,
        string $role,
        string $actorUserId,
        int $expectedVersion,
    ): array {
        $id = Str::uuid7()->toString();
        DB::transaction(function () use ($id, $task, $userId, $role, $actorUserId, $expectedVersion): void {
            DB::table('task_participants')->insert([
                'id' => $id,
                'task_id' => $task->id,
                'user_id' => $userId,
                'role' => $role,
                'added_by_user_id' => $actorUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('tasks')
                ->where('id', $task->id)
                ->where('lock_version', $expectedVersion)
                ->update(['lock_version' => $expectedVersion + 1, 'updated_at' => now()]);
        });

        return [
            'id' => $id,
            'task_id' => (string) $task->id,
            'user_id' => $userId,
            'role' => $role,
            'lock_version' => $expectedVersion + 1,
        ];
    }

    /** @return array{items: list<stdClass>, next_cursor: ?string} */
    public function listComments(string $taskId, int $limit, ?string $cursor): array
    {
        $query = DB::table('task_comments')
            ->where('task_id', $taskId)
            ->orderBy('created_at')
            ->orderBy('id');
        if ($cursor !== null && $cursor !== '') {
            $query->where('id', '>', $cursor);
        }
        $rows = $query->limit($limit + 1)->get()->all();
        $hasNextPage = count($rows) > $limit;
        if ($hasNextPage) {
            array_pop($rows);
        }

        return [
            'items' => $rows,
            'next_cursor' => $hasNextPage && $rows !== [] ? (string) end($rows)->id : null,
        ];
    }

    public function addComment(string $taskId, string $authorUserId, string $body, array $mentionedUserIds): stdClass
    {
        $id = Str::uuid7()->toString();
        DB::table('task_comments')->insert([
            'id' => $id,
            'task_id' => $taskId,
            'author_user_id' => $authorUserId,
            'body' => $body,
            'mentioned_user_ids' => json_encode(array_values($mentionedUserIds), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return DB::table('task_comments')->where('id', $id)->firstOrFail();
    }
}
