<?php

declare(strict_types=1);

namespace Modules\Tasks\Features\TransitionTask\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Tasks\Application\TaskAccessPolicy;
use Modules\Tasks\Contracts\RecordTaskNotifications;
use Modules\Tasks\Features\TransitionTask\Exception\TaskTransitionConflict;
use Modules\Tasks\Infrastructure\Persistence\TaskCommandIdempotency;
use Modules\Tasks\Infrastructure\Persistence\TaskHttpStore;
use Shared\Contracts\TransactionalOutbox;
use Shared\Infrastructure\Outbox\OutboxEventType;
use stdClass;

/**
 * Full task state machine.
 *
 * Valid transitions:
 *   open --start--> in_progress (assignee only)
 *   in_progress --block(reason)--> blocked (assignee only)
 *   blocked --unblock--> in_progress (assignee or creator)
 *   in_progress --complete(note)--> completed (assignee only)
 *   open|in_progress|blocked --cancel(reason)--> cancelled (creator only)
 *
 * Anything else is a 409 (controller maps the exception). Terminal states
 * (completed, cancelled) reject every action with the same 409.
 *
 * All mutations are CAS on `lock_version`, idempotent replay-safe, run inside
 * a single DB::transaction so outbox + notifications + the in-transaction
 * comment row for the reason/note are committed atomically.
 */
final readonly class TransitionTaskHandler
{
    private const IDEMPOTENCY_OPERATION = 'transitionTask';

    /** Notification type per action (spec §9 pinned strings). */
    private const NOTIFICATION_TYPES = [
        'start' => 'started',
        'block' => 'blocked',
        'unblock' => 'unblocked',
        'complete' => 'completed',
        'cancel' => 'cancelled',
    ];

    private const OUTBOX_TYPES = [
        'start' => OutboxEventType::TaskStarted,
        'block' => OutboxEventType::TaskBlocked,
        'unblock' => OutboxEventType::TaskUnblocked,
        'complete' => OutboxEventType::TaskCompleted,
        'cancel' => OutboxEventType::TaskCancelled,
    ];

    /** @var array<string, array{from: list<string>, to: string, requires_reason: bool, requires_note: bool}> */
    private const TRANSITIONS = [
        'start' => ['from' => ['open'], 'to' => 'in_progress', 'requires_reason' => false, 'requires_note' => false],
        'block' => ['from' => ['in_progress'], 'to' => 'blocked', 'requires_reason' => true, 'requires_note' => false],
        'unblock' => ['from' => ['blocked'], 'to' => 'in_progress', 'requires_reason' => false, 'requires_note' => false],
        'complete' => ['from' => ['in_progress'], 'to' => 'completed', 'requires_reason' => false, 'requires_note' => true],
        'cancel' => ['from' => ['open', 'in_progress', 'blocked'], 'to' => 'cancelled', 'requires_reason' => true, 'requires_note' => false],
    ];

    public function __construct(
        private TaskHttpStore $store,
        private TransactionalOutbox $outbox,
        private TaskCommandIdempotency $idempotency,
        private RecordTaskNotifications $notifications,
        private TaskAccessPolicy $policy,
    ) {}

    /**
     * @param  array{user_id: string, facility_id?: ?string}  $actor
     * @return array<string, mixed>
     */
    public function handle(
        string $taskId,
        int $expectedVersion,
        string $action,
        array $actor,
        string $idempotencyKey,
        ?string $reason = null,
        ?string $note = null,
        bool $isManager = false,
    ): array {
        if (! isset(self::TRANSITIONS[$action])) {
            throw new TaskTransitionConflict('unknown_action');
        }

        $transition = self::TRANSITIONS[$action];
        $reasonText = is_string($reason) ? trim($reason) : '';
        $noteText = is_string($note) ? trim($note) : '';

        if ($transition['requires_reason'] && $reasonText === '') {
            throw new TaskTransitionConflict('reason_required');
        }
        if ($transition['requires_note'] && $noteText === '') {
            throw new TaskTransitionConflict('note_required');
        }

        $requestHash = hash('sha256', json_encode([
            'task_id' => $taskId,
            'expected_version' => $expectedVersion,
            'action' => $action,
            'reason' => $transition['requires_reason'] ? $reasonText : null,
            'note' => $transition['requires_note'] ? $noteText : null,
        ], JSON_THROW_ON_ERROR));

        $actorUserId = (string) $actor['user_id'];

        $replay = $this->idempotency->replay(
            $actorUserId,
            self::IDEMPOTENCY_OPERATION,
            $idempotencyKey,
            $requestHash,
        );
        if ($replay !== null) {
            return $replay;
        }

        return DB::transaction(function () use (
            $taskId,
            $expectedVersion,
            $action,
            $actorUserId,
            $idempotencyKey,
            $requestHash,
            $transition,
            $reasonText,
            $noteText,
            $isManager,
        ): array {
            $current = $this->store->findForUpdate($taskId);
            if ($current === null) {
                throw new InvalidArgumentException('task_not_found');
            }

            $state = (string) $current->status;
            if ($state === 'completed' || $state === 'cancelled') {
                throw new TaskTransitionConflict('terminal_state');
            }
            if (! in_array($state, $transition['from'], true)) {
                throw new TaskTransitionConflict('invalid_transition');
            }

            $isCreator = $actorUserId === (string) $current->created_by_user_id;
            $isAssignee = $actorUserId === (string) $current->assignee_user_id;
            $this->assertActorAllowed($action, $isCreator, $isAssignee, $isManager);

            if ((int) $current->lock_version !== $expectedVersion) {
                throw new StaleTaskVersion($taskId, $expectedVersion);
            }

            $updates = ['status' => $transition['to']];
            if ($transition['to'] === 'completed') {
                $updates['completed_at'] = now();
            }
            $updated = $this->store->update($taskId, $expectedVersion, $updates);
            if ($updated === null) {
                throw new StaleTaskVersion($taskId, $expectedVersion);
            }

            $commentBody = $this->commentBodyFor($action, $reasonText, $noteText);
            if ($commentBody !== null) {
                $this->store->insertComment($taskId, $actorUserId, $commentBody, []);
            }

            $this->outbox->append(
                Str::uuid7()->toString(),
                $taskId,
                self::OUTBOX_TYPES[$action]->value,
                [
                    'task_id' => $taskId,
                    'actor_user_id' => $actorUserId,
                    'from_state' => $state,
                    'to_state' => $transition['to'],
                ],
            );

            $recipients = $this->recipientsFor($updated, $actorUserId);
            $this->notifications->record(
                $recipients,
                'task.'.self::NOTIFICATION_TYPES[$action],
                [
                    'task_id' => $taskId,
                    'title' => (string) $updated->title,
                    'actor_user_id' => $actorUserId,
                    'action' => $action,
                ],
                $this->policy->factsFor($updated, $this->store->participantIds($taskId)),
            );

            $response = [
                'id' => (string) $updated->id,
                'title' => (string) $updated->title,
                'state' => (string) $updated->status,
                'priority' => (string) $updated->priority,
                'classification' => (string) $updated->classification,
                'assignee_user_id' => (string) $updated->assignee_user_id,
                'creator_user_id' => (string) $updated->created_by_user_id,
                'lock_version' => (int) $updated->lock_version,
                'created_at' => $updated->created_at,
                'updated_at' => $updated->updated_at,
            ];

            $this->idempotency->store(
                $actorUserId,
                self::IDEMPOTENCY_OPERATION,
                $idempotencyKey,
                $requestHash,
                $taskId,
                $response,
            );

            return $response;
        });
    }

    private function assertActorAllowed(string $action, bool $isCreator, bool $isAssignee, bool $isManager = false): void
    {
        $allowed = match ($action) {
            'start' => $isAssignee,
            'block' => $isAssignee,
            'unblock' => $isAssignee || $isCreator,
            'complete' => $isAssignee,
            'cancel' => $isCreator || $isManager,
            default => false,
        };
        if (! $allowed) {
            throw new TaskTransitionConflict('actor_not_authorized');
        }
    }

    private function commentBodyFor(string $action, string $reasonText, string $noteText): ?string
    {
        return match ($action) {
            'block' => 'Block reason: '.$reasonText,
            'cancel' => 'Cancel reason: '.$reasonText,
            'complete' => 'Completion note: '.$noteText,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function recipientsFor(stdClass $task, string $actorUserId): array
    {
        $recipients = [
            (string) $task->created_by_user_id,
            (string) $task->assignee_user_id,
        ];
        foreach ($this->store->participantIds((string) $task->id) as $participantUserId) {
            $recipients[] = $participantUserId;
        }

        return array_values(array_unique(array_filter(
            $recipients,
            static fn (string $userId): bool => $userId !== '' && $userId !== $actorUserId,
        )));
    }
}
