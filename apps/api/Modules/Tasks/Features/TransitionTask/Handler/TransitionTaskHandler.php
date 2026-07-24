<?php

namespace Modules\Tasks\Features\TransitionTask\Handler;

use Illuminate\Support\Str;
use Modules\Tasks\Infrastructure\Persistence\TaskHttpStore;
use Shared\Contracts\TransactionalOutbox;
use stdClass;

final readonly class TransitionTaskHandler
{
    public function __construct(
        private TaskHttpStore $store,
        private TransactionalOutbox $outbox,
    ) {}

    public function handle(
        string $taskId,
        int $expectedVersion,
        string $action,
        string $actorUserId,
    ): ?stdClass {
        $status = match ($action) {
            'start' => 'in_progress',
            'return', 'return-completion' => 'returned',
            'submit-completion' => 'submitted',
            'cancel' => 'cancelled',
            default => null,
        };
        if ($status === null) {
            return null;
        }

        $task = $this->store->transition($taskId, $expectedVersion, $status);
        if ($task === null) {
            return null;
        }

        $this->outbox->append(
            Str::uuid7()->toString(),
            $taskId,
            'task.'.$action.'.v1',
            ['task_id' => $taskId, 'actor_user_id' => $actorUserId],
        );

        return $task;
    }
}
