<?php

declare(strict_types=1);

namespace Modules\Tasks\Features\DocumentLink\Http;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Contracts\DocumentSourceReference;
use Modules\Documents\Contracts\LinkDocument;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Tasks\Application\TaskAccessPolicy;
use Modules\Tasks\Contracts\RecordTaskNotifications;
use Modules\Tasks\Domain\TaskIdempotencyConflict;
use Modules\Tasks\Infrastructure\Persistence\TaskCommandIdempotency;
use Modules\Tasks\Infrastructure\Persistence\TaskHttpStore;

/**
 * POST /api/v1/tasks/{taskId}/documents
 *
 * Attaches an already-authorized document to a task. The Documents module
 * owns the link row and the authorization decision for both sides; the
 * Tasks module only resolves the task, the principal's relationship to it,
 * and emits the in-transaction notification.
 */
final class TaskDocumentController
{
    private const ATTACH_OPERATION = 'attachTaskDocument';

    public function __construct(
        private readonly ResolveDevelopmentFixturePrincipal $resolver,
        private readonly TaskHttpStore $store,
        private readonly DecideAccess $access,
        private readonly LinkDocument $link,
        private readonly RecordTaskNotifications $notifications,
        private readonly TaskAccessPolicy $policy,
        private readonly TaskCommandIdempotency $idempotency,
    ) {}

    public function attach(Request $request, string $taskId): JsonResponse
    {
        $correlation = $this->correlation($request);
        if ($correlation === null) {
            return $this->problem(400, 'invalid-correlation-id', 'X-Correlation-ID must be a lowercase UUIDv7.');
        }

        $principal = $this->resolver->resolve($request);
        if ($principal === null) {
            return $this->problem(401, 'authentication-required', 'Authentication is required.', $correlation);
        }

        if (! $this->isUuidV7($taskId)) {
            return $this->problem(422, 'invalid-task', 'The task id is invalid.', $correlation);
        }

        $task = $this->store->findVisible($taskId, $principal['user_id']);
        if ($task === null) {
            return $this->problem(404, 'resource-not-found', 'The task is not available.', $correlation);
        }

        $actor = [
            'user_id' => $principal['user_id'],
            'facility_id' => $principal['facility_id'],
            'correlation_id' => $correlation,
        ];

        if (! $this->access->decide($actor, 'tasks.update', $this->policy->factsFor($task, $this->store->participantIds((string) $task->id)))->isAllowed()) {
            return $this->problem(403, 'access-denied', 'Access denied.', $correlation);
        }

        $payload = $request->json()->all();
        $documentId = $payload['document_id'] ?? null;
        if (! is_string($documentId) || ! $this->isUuidV7($documentId)) {
            return $this->problem(422, 'invalid-task', 'The request body is invalid.', $correlation);
        }

        $requestBody = ['document_id' => $documentId];
        $replay = $this->replay($request, $principal['user_id'], self::ATTACH_OPERATION, $correlation, $requestBody);
        if ($replay !== null) {
            return $replay;
        }

        $response = [
            'data' => [
                'id' => null,
                'document_id' => $documentId,
                'task_id' => $taskId,
            ],
        ];
        try {
            $response = DB::transaction(function () use ($documentId, $taskId, $principal, $request, $requestBody, $response): array {
                $linkId = $this->link->link(
                    $documentId,
                    new DocumentSourceReference('tasks', 'task', $taskId),
                    'attachment',
                    $principal['user_id'],
                    $principal['facility_id'],
                );
                $response['data']['id'] = $linkId;
                $this->storeIdempotency($request, $principal['user_id'], self::ATTACH_OPERATION, $taskId, $requestBody, $response);

                return $response;
            });
        } catch (DomainException $exception) {
            if ($exception instanceof TaskIdempotencyConflict) {
                return $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $correlation);
            }
            $code = $exception->getMessage();
            if ($code === 'document_access_denied' || $code === 'document_not_found') {
                return $this->problem(403, 'access-denied', 'Access denied.', $correlation);
            }
            if ($code === 'document_not_available_for_link') {
                return $this->problem(404, 'resource-not-found', 'The document is not available.', $correlation);
            }

            return $this->problem(404, 'resource-not-found', 'The document is not available.', $correlation);
        } catch (QueryException) {
            return $this->problem(409, 'document-link-conflict', 'The document is already attached.', $correlation);
        }

        $this->notifications->record(
            $this->recipientsExcludingActor($task, $principal['user_id']),
            'task.document_attached',
            [
                'task_id' => $taskId,
                'title' => (string) $task->title,
                'actor_user_id' => $principal['user_id'],
                'document_id' => $documentId,
                'link_id' => $response['data']['id'],
            ],
        );

        return response()->json($response, 201)->header('X-Correlation-ID', $correlation);
    }

    private function replay(Request $request, string $principalId, string $operation, string $correlationId, array $requestBody): ?JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || preg_match('/\A[\x21-\x7E]{1,255}\z/', $key) !== 1) {
            return $this->problem(400, 'invalid-idempotency-key', 'Idempotency-Key is required.', $correlationId);
        }

        try {
            $replay = $this->idempotency->replay(
                $principalId,
                $operation,
                $key,
                hash('sha256', json_encode($requestBody, JSON_THROW_ON_ERROR)),
            );
        } catch (TaskIdempotencyConflict) {
            return $this->problem(409, 'idempotency-conflict', 'Idempotency-Key was already used for a different request.', $correlationId);
        }

        if ($replay === null) {
            return null;
        }

        return response()->json($replay, 201)->header('X-Correlation-ID', $correlationId);
    }

    private function storeIdempotency(Request $request, string $principalId, string $operation, string $taskId, array $requestBody, array $response): void
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key)) {
            return;
        }

        $this->idempotency->store(
            $principalId,
            $operation,
            $key,
            hash('sha256', json_encode($requestBody, JSON_THROW_ON_ERROR)),
            $taskId,
            $response,
        );
    }

    private function correlation(Request $request): ?string
    {
        $value = $request->header('X-Correlation-ID');

        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1 ? $value : null;
    }

    private function isUuidV7(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) === 1;
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
}
