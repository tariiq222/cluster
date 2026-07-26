<?php

namespace Modules\Tasks\Features\TransitionTask\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tasks\Features\CompleteTask\Handler\StaleTaskVersion;
use Modules\Tasks\Infrastructure\Persistence\TaskCommandIdempotency;
use Modules\Tasks\Infrastructure\Persistence\TaskHttpStore;
use Shared\Contracts\TransactionalOutbox;
use stdClass;

final readonly class TransitionTaskHandler
{
    private const IDEMPOTENCY_OPERATION = 'transitionTask';

    public function __construct(
        private TaskHttpStore $store,
        private TransactionalOutbox $outbox,
        private TaskCommandIdempotency $idempotency,
    ) {}

    public function handle(
        string $taskId,
        int $expectedVersion,
        string $action,
        string $actorUserId,
        string $idempotencyKey,
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

        $requestHash = hash('sha256', json_encode([
            'task_id' => $taskId,
            'expected_version' => $expectedVersion,
            'action' => $action,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $taskId,
            $expectedVersion,
            $action,
            $actorUserId,
            $idempotencyKey,
            $requestHash,
            $status,
        ): ?stdClass {
            $current = $this->store->findForUpdate($taskId);
            if ($current === null) {
                return null;
            }

            $replay = $this->idempotency->replay(
                $actorUserId,
                self::IDEMPOTENCY_OPERATION,
                $idempotencyKey,
                $requestHash,
            );
            if ($replay !== null) {
                return (object) $replay;
            }
            if ((int) $current->lock_version !== $expectedVersion) {
                throw new StaleTaskVersion($taskId, $expectedVersion);
            }

            $task = $this->store->transition($taskId, $expectedVersion, $status);
            if ($task === null) {
                throw new StaleTaskVersion($taskId, $expectedVersion);
            }

            $this->outbox->append(
                Str::uuid7()->toString(),
                $taskId,
                'task.'.$action.'.v1',
                ['task_id' => $taskId, 'actor_user_id' => $actorUserId],
            );
            $this->idempotency->store(
                $actorUserId,
                self::IDEMPOTENCY_OPERATION,
                $idempotencyKey,
                $requestHash,
                $taskId,
                (array) $task,
            );

            return $task;
        });
    }
}
