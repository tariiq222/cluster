<?php

namespace Modules\Tasks\Features\CompleteTask\Handler;

use Illuminate\Support\Facades\DB;
use Modules\Tasks\Infrastructure\Persistence\TaskCommandIdempotency;
use Modules\Tasks\Infrastructure\Persistence\TaskHttpStore;
use Modules\Workflow\Contracts\AdvanceWorkflowStep;
use Shared\Contracts\TransactionalOutbox;

final class CompleteTaskHandler
{
    private const IDEMPOTENCY_OPERATION = 'completeTask';

    public function __construct(
        private readonly TaskHttpStore $store,
        private readonly AdvanceWorkflowStep $workflow,
        private readonly TransactionalOutbox $outbox,
        private readonly TaskCommandIdempotency $idempotency,
    ) {}

    /** @return array{task: array<string, mixed>, workflow: array<string, mixed>|null, idempotent: bool} */
    public function handle(
        string $taskId,
        string $actorUserId,
        int $expectedVersion,
        string $idempotencyKey,
    ): array {
        $requestHash = hash('sha256', json_encode([
            'task_id' => $taskId,
            'expected_version' => $expectedVersion,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $taskId,
            $actorUserId,
            $expectedVersion,
            $idempotencyKey,
            $requestHash,
        ): array {
            $task = $this->store->findForUpdate($taskId);
            if ($task === null) {
                throw new \InvalidArgumentException('Task not found.');
            }

            $replay = $this->idempotency->replay(
                $actorUserId,
                self::IDEMPOTENCY_OPERATION,
                $idempotencyKey,
                $requestHash,
            );
            if ($replay !== null) {
                $replay['idempotent'] = true;

                return $replay;
            }
            if ((int) $task->lock_version !== $expectedVersion) {
                throw new StaleTaskVersion($taskId, $expectedVersion);
            }
            if ($task->status === 'completed') {
                $result = ['task' => (array) $task, 'workflow' => null, 'idempotent' => true];
                $this->idempotency->store(
                    $actorUserId,
                    self::IDEMPOTENCY_OPERATION,
                    $idempotencyKey,
                    $requestHash,
                    $taskId,
                    $result,
                );

                return $result;
            }

            $now = now();
            $completed = $this->store->update($taskId, $expectedVersion, [
                'status' => 'completed',
                'completed_at' => $now,
            ]);
            if ($completed === null) {
                throw new StaleTaskVersion($taskId, $expectedVersion);
            }

            $this->outbox->append(
                $this->eventId('task.completed.v1', $taskId),
                $taskId,
                'task.completed.v1',
                [
                    'task_id' => $taskId,
                    'workflow_step_id' => $task->workflow_step_id,
                    'actor_user_id' => $actorUserId,
                ],
            );
            $workflow = $task->workflow_step_id === null
                ? null
                : $this->workflow->taskCompleted((string) $task->workflow_step_id, $taskId, $actorUserId);
            $result = [
                'task' => (array) $completed,
                'workflow' => $workflow,
                'idempotent' => false,
            ];
            $this->idempotency->store(
                $actorUserId,
                self::IDEMPOTENCY_OPERATION,
                $idempotencyKey,
                $requestHash,
                $taskId,
                $result,
            );

            return $result;
        });
    }

    private function eventId(string $type, string $id): string
    {
        $hex = substr(hash('sha256', $type.':'.$id), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-7'.substr($hex, 13, 3).'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
