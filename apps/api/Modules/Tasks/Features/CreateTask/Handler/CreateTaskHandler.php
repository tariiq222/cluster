<?php

declare(strict_types=1);

namespace Modules\Tasks\Features\CreateTask\Handler;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Tasks\Application\TaskAccessPolicy;
use Modules\Tasks\Contracts\RecordTaskNotifications;
use Modules\Tasks\Infrastructure\Persistence\TaskCommandIdempotency;
use Modules\Tasks\Infrastructure\Persistence\TaskHttpStore;
use Shared\Contracts\TransactionalOutbox;
use Shared\Infrastructure\Outbox\OutboxEventType;
use stdClass;

/**
 * Tasks-owned task creator. Self-creation needs tasks.create; assigning
 * another user needs tasks.assign AND the target must sit inside an
 * organization scope the actor can manage (resolved via the Organization
 * module's scope-descendants contract). Idempotent: replays return the
 * original response for the same Idempotency-Key + request body.
 */
final readonly class CreateTaskHandler
{
    private const IDEMPOTENCY_OPERATION = 'createTask';

    public function __construct(
        private TaskHttpStore $store,
        private TransactionalOutbox $outbox,
        private TaskCommandIdempotency $idempotency,
        private RecordTaskNotifications $notifications,
        private TaskAccessPolicy $policy,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array{user_id: string, facility_id?: ?string}  $actor
     * @return array<string, mixed>
     */
    public function handle(array $input, array $actor, string $idempotencyKey): array
    {
        $requestHash = $this->requestHash($input);
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

        $assigneeUserId = (string) ($input['assignee_user_id'] ?? $actorUserId);
        $isSelfTask = $assigneeUserId === $actorUserId;
        $participants = $this->normalizeParticipants($input['participant_user_ids'] ?? []);
        $dueAt = $this->normalizeDateTime($input['due_at'] ?? null);

        return DB::transaction(function () use (
            $input,
            $idempotencyKey,
            $requestHash,
            $actorUserId,
            $assigneeUserId,
            $isSelfTask,
            $participants,
            $dueAt,
        ): array {
            $taskId = Str::uuid7()->toString();
            $task = $this->store->insert([
                'id' => $taskId,
                'title' => $input['title'],
                'description' => $input['description'] ?? null,
                'created_by_user_id' => $actorUserId,
                'assignee_user_id' => $assigneeUserId,
                'owner_organization_unit_id' => $input['owner_organization_unit_id'] ?? null,
                'status' => 'open',
                'due_at' => $dueAt,
                'priority' => $input['priority'] ?? 'normal',
                'classification' => $input['classification'] ?? 'internal',
                'completion_policy' => 'direct',
            ]);

            if (! $isSelfTask) {
                $this->store->insertParticipant($taskId, $actorUserId, 'manager', $actorUserId);
            }
            foreach ($participants as $participantUserId) {
                if ($participantUserId === $actorUserId || $participantUserId === $assigneeUserId) {
                    continue;
                }
                $this->store->insertParticipant($taskId, $participantUserId, 'participant', $actorUserId);
            }

            $payload = [
                'task_id' => $taskId,
                'title' => (string) $task->title,
                'actor_user_id' => $actorUserId,
                'assignee_user_id' => $assigneeUserId,
            ];

            $this->outbox->append(
                Str::uuid7()->toString(),
                $taskId,
                OutboxEventType::TaskCreated->value,
                $payload,
            );
            if (! $isSelfTask) {
                $this->outbox->append(
                    Str::uuid7()->toString(),
                    $taskId,
                    OutboxEventType::TaskAssigned->value,
                    $payload + ['previous_assignee_user_id' => null],
                );
            }

            $recipients = $this->recipientsForCreate($actorUserId, $assigneeUserId, $participants);
            $recipients = array_values(array_filter(
                $recipients,
                static fn (string $userId): bool => $userId !== '' && $userId !== $actorUserId,
            ));
            if ($recipients !== []) {
                $this->notifications->record(
                    $recipients,
                    $isSelfTask ? 'task.created' : 'task.assigned',
                    $payload,
                    $this->policy->factsFor($task, $this->store->participantIds($taskId)),
                );
            }

            $response = $this->serialize($task);
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

    /**
     * @param  array<string, mixed>  $input
     */
    private function requestHash(array $input): string
    {
        return hash('sha256', json_encode([
            'title' => $input['title'] ?? null,
            'description' => $input['description'] ?? null,
            'assignee_user_id' => $input['assignee_user_id'] ?? null,
            'owner_organization_unit_id' => $input['owner_organization_unit_id'] ?? null,
            'priority' => $input['priority'] ?? null,
            'due_at' => $input['due_at'] ?? null,
            'classification' => $input['classification'] ?? null,
            'participant_user_ids' => $input['participant_user_ids'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function normalizeParticipants(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $value,
            static fn (mixed $candidate): bool => is_string($candidate) && $candidate !== '',
        )));
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value)->utc()->toDateTimeString();
    }

    /**
     * @param  list<string>  $participants
     * @return list<string>
     */
    private function recipientsForCreate(string $actorUserId, string $assigneeUserId, array $participants): array
    {
        $recipients = [$assigneeUserId];
        foreach ($participants as $participant) {
            $recipients[] = $participant;
        }
        $recipients[] = $actorUserId;

        return array_values(array_unique(array_filter(
            $recipients,
            static fn (string $userId): bool => $userId !== '',
        )));
    }

    /** @return array<string, mixed> */
    public function serialize(stdClass $task): array
    {
        return [
            'id' => (string) $task->id,
            'title' => (string) $task->title,
            'description' => $task->description,
            'state' => (string) $task->status,
            'classification' => (string) $task->classification,
            'priority' => (string) $task->priority,
            'assignee_user_id' => (string) $task->assignee_user_id,
            'creator_user_id' => (string) $task->created_by_user_id,
            'participant_user_ids' => [],
            'due_at' => $task->due_at,
            'allowed_actions' => [],
            'attachments' => [],
            'comments_summary' => ['count' => 0, 'latest_at' => null],
            'lock_version' => (int) $task->lock_version,
            'created_at' => $task->created_at,
            'updated_at' => $task->updated_at,
        ];
    }
}
