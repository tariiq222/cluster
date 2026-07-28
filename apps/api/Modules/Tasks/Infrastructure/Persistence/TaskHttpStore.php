<?php

declare(strict_types=1);

namespace Modules\Tasks\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final class TaskHttpStore
{
    public const RELATIONSHIP_ALL = 'all';

    public const RELATIONSHIP_ASSIGNED = 'assigned';

    public const RELATIONSHIP_CREATED = 'created';

    public const RELATIONSHIP_PARTICIPATING = 'participating';

    /** @var list<string> */
    private const STATES = ['open', 'in_progress', 'blocked', 'completed', 'cancelled'];

    /** @return list<stdClass> */
    public function listVisibleFor(string $userId, ?string $state, ?string $relationship, ?string $cursor, int $limit = 50): array
    {
        $query = DB::table('tasks as t')
            ->leftJoin('task_participants as p', function ($join) use ($userId): void {
                $join->on('p.task_id', '=', 't.id')->where('p.user_id', '=', $userId);
            })
            ->where(function ($builder) use ($userId): void {
                $builder->where('t.created_by_user_id', $userId)
                    ->orWhere('t.assignee_user_id', $userId)
                    ->orWhereNotNull('p.id');
            })
            ->orderBy('t.created_at')
            ->orderBy('t.id')
            ->select('t.*');

        if ($state !== null && $state !== '' && in_array($state, self::STATES, true)) {
            $query->where('t.status', $state);
        }
        if ($relationship !== null && $relationship !== '' && $relationship !== self::RELATIONSHIP_ALL) {
            $query->where(function ($builder) use ($relationship, $userId): void {
                if ($relationship === self::RELATIONSHIP_ASSIGNED) {
                    $builder->where('t.assignee_user_id', $userId);
                } elseif ($relationship === self::RELATIONSHIP_CREATED) {
                    $builder->where('t.created_by_user_id', $userId);
                } elseif ($relationship === self::RELATIONSHIP_PARTICIPATING) {
                    $builder->whereNotNull('p.id');
                }
            });
        }
        if ($cursor !== null && $cursor !== '') {
            $query->where('t.id', '>', $cursor);
        }

        return $query->limit($limit + 1)->get()->all();
    }

    public function find(string $taskId): ?stdClass
    {
        return DB::table('tasks')->where('id', $taskId)->first();
    }

    public function findForUpdate(string $taskId): ?stdClass
    {
        return DB::table('tasks')->where('id', $taskId)->lockForUpdate()->first();
    }

    public function findVisible(string $taskId, string $userId): ?stdClass
    {
        $query = DB::table('tasks as t')
            ->leftJoin('task_participants as p', function ($join) use ($userId): void {
                $join->on('p.task_id', '=', 't.id')->where('p.user_id', '=', $userId);
            })
            ->where('t.id', $taskId)
            ->where(function ($builder) use ($userId): void {
                $builder->where('t.created_by_user_id', $userId)
                    ->orWhere('t.assignee_user_id', $userId)
                    ->orWhereNotNull('p.id');
            })
            ->select('t.*');

        return $query->first();
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

    /** @param array<string, mixed> $fields */
    public function insert(array $fields): stdClass
    {
        $now = now();
        $id = is_string($fields['id'] ?? null) && $fields['id'] !== '' ? (string) $fields['id'] : Str::uuid7()->toString();
        DB::table('tasks')->insert([
            'id' => $id,
            'title' => $fields['title'],
            'description' => $fields['description'] ?? null,
            'created_by_user_id' => $fields['created_by_user_id'],
            'assignee_user_id' => $fields['assignee_user_id'],
            'owner_organization_unit_id' => $fields['owner_organization_unit_id'] ?? null,
            'status' => $fields['status'] ?? 'open',
            'due_at' => $fields['due_at'] ?? null,
            'priority' => $fields['priority'] ?? 'normal',
            'classification' => $fields['classification'] ?? 'internal',
            'completion_policy' => $fields['completion_policy'] ?? 'direct',
            'source_module' => $fields['source_module'] ?? null,
            'source_type' => $fields['source_type'] ?? null,
            'source_id' => $fields['source_id'] ?? null,
            'workflow_step_id' => $fields['workflow_step_id'] ?? null,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = $this->find($id);
        if ($row === null) {
            throw new \RuntimeException('Task insert failed for id '.$id);
        }

        return $row;
    }

    /**
     * Creation-path participant insert (no lock_version bump — the task row
     * itself is brand new). Duplicates are ignored.
     */
    public function insertParticipant(string $taskId, string $userId, string $role, string $actorUserId): void
    {
        DB::table('task_participants')->insertOrIgnore([
            'id' => Str::uuid7()->toString(),
            'task_id' => $taskId,
            'user_id' => $userId,
            'role' => $role,
            'added_by_user_id' => $actorUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Inserts the participant and bumps the task lock_version in one
     * transaction (CAS on $expectedVersion). A duplicate (task_id, user_id)
     * raises QueryException so the caller can answer 409; a CAS miss returns
     * null so the caller answers 412 with no partial state.
     */
    public function addParticipant(string $taskId, string $userId, string $role, string $actorUserId, int $expectedVersion): ?stdClass
    {
        return DB::transaction(function () use ($taskId, $userId, $role, $actorUserId, $expectedVersion): ?stdClass {
            DB::table('task_participants')->insert([
                'id' => Str::uuid7()->toString(),
                'task_id' => $taskId,
                'user_id' => $userId,
                'role' => $role,
                'added_by_user_id' => $actorUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->update($taskId, $expectedVersion, []);
        });
    }

    public function insertComment(string $taskId, string $authorUserId, string $body, array $mentionedUserIds): stdClass
    {
        $id = Str::uuid7()->toString();
        DB::table('task_comments')->insert([
            'id' => $id,
            'task_id' => $taskId,
            'author_user_id' => $authorUserId,
            'body' => $body,
            'mentioned_user_ids' => json_encode(array_values(array_unique(array_filter(
                $mentionedUserIds,
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            ))), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $row = DB::table('task_comments')->where('id', $id)->first();
        if ($row === null) {
            throw new \RuntimeException('Task comment insert failed for id '.$id);
        }

        return $row;
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

    /**
     * Attachments are owned by the Documents module; this helper only projects
     * the active links that already point at this task. The link itself is
     * created through Modules\Documents\Contracts\LinkDocument.
     *
     * @return list<stdClass>
     */
    public function attachmentsFor(string $taskId): array
    {
        return DB::table('document_links as links')
            ->join('documents as docs', 'docs.id', '=', 'links.document_id')
            ->where('links.source_module', 'tasks')
            ->where('links.source_type', 'task')
            ->where('links.source_id', $taskId)
            ->where('links.status', 'active')
            ->orderBy('links.created_at')
            ->orderBy('links.id')
            ->get([
                'docs.public_id as document_id',
                'docs.name as title',
                'links.linked_by_user_id',
                'links.created_at',
            ])
            ->all();
    }

    public function commentsSummary(string $taskId): array
    {
        $count = (int) DB::table('task_comments')->where('task_id', $taskId)->count();
        $latest = DB::table('task_comments')
            ->where('task_id', $taskId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return [
            'count' => $count,
            'latest_at' => $latest?->created_at,
        ];
    }

    public function participantsWithRoles(string $taskId): array
    {
        return DB::table('task_participants')
            ->where('task_id', $taskId)
            ->orderBy('created_at')
            ->get(['id', 'task_id', 'user_id', 'role', 'added_by_user_id', 'created_at'])
            ->all();
    }
}
