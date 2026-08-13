<?php

declare(strict_types=1);

namespace Modules\Tasks\Features\UpdateTask\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Tasks\Application\TaskAccessPolicy;
use Modules\Tasks\Contracts\RecordTaskNotifications;
use Modules\Tasks\Infrastructure\Persistence\TaskHttpStore;
use Shared\Contracts\TransactionalOutbox;
use Shared\Infrastructure\Outbox\OutboxEventType;
use stdClass;

/** Keeps the task CAS, TaskAssigned event, and derived notifications atomic. */
final readonly class UpdateTaskHandler
{
    public function __construct(
        private TaskHttpStore $store,
        private TransactionalOutbox $outbox,
        private RecordTaskNotifications $notifications,
        private TaskAccessPolicy $policy,
    ) {}

    /**
     * @param  array<string, mixed>  $updates
     * @param  list<string>  $changedNotificationFields
     */
    public function handle(
        string $taskId,
        int $expectedVersion,
        array $updates,
        string $actorUserId,
        array $changedNotificationFields,
    ): stdClass {
        return DB::transaction(function () use ($taskId, $expectedVersion, $updates, $actorUserId, $changedNotificationFields): stdClass {
            $current = $this->store->findForUpdate($taskId);
            if ($current === null || (int) $current->lock_version !== $expectedVersion) {
                throw new InvalidArgumentException('stale_task_version');
            }

            $previousAssignee = (string) $current->assignee_user_id;
            $updated = $this->store->update($taskId, $expectedVersion, $updates);
            if ($updated === null) {
                throw new InvalidArgumentException('stale_task_version');
            }

            $participants = $this->store->participantIds($taskId);
            $facts = $this->policy->factsFor($updated, $participants);
            if ($changedNotificationFields !== []) {
                $this->notifications->record(
                    $this->recipientsExcludingActor($updated, $participants, $actorUserId),
                    'task.updated',
                    [
                        'task_id' => $taskId,
                        'title' => (string) $updated->title,
                        'actor_user_id' => $actorUserId,
                        'changed' => $changedNotificationFields,
                    ],
                    $facts,
                );
            }

            $newAssignee = (string) $updated->assignee_user_id;
            if (! hash_equals($previousAssignee, $newAssignee)) {
                $eventPayload = [
                    'task_id' => $taskId,
                    'title' => (string) $updated->title,
                    'actor_user_id' => $actorUserId,
                    'assignee_user_id' => $newAssignee,
                    'previous_assignee_user_id' => $previousAssignee,
                ];
                $this->outbox->append(
                    Str::uuid7()->toString(),
                    $taskId,
                    OutboxEventType::TaskAssigned->value,
                    $eventPayload,
                );
                $this->notifications->record(
                    array_values(array_unique(array_filter([$previousAssignee, $newAssignee]))),
                    'task.reassigned',
                    $eventPayload + ['new_assignee_user_id' => $newAssignee],
                    $facts,
                );
            }

            return $updated;
        });
    }

    /** @param list<string> $participants @return list<string> */
    private function recipientsExcludingActor(stdClass $task, array $participants, string $actorUserId): array
    {
        return array_values(array_unique(array_filter(
            [(string) $task->created_by_user_id, (string) $task->assignee_user_id, ...$participants],
            static fn (string $userId): bool => $userId !== '' && $userId !== $actorUserId,
        )));
    }
}
