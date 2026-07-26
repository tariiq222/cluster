<?php

namespace Modules\Tasks\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Tasks\Domain\TaskIdempotencyConflict;
use UnexpectedValueException;

final class TaskCommandIdempotency
{
    /** @return array<string, mixed>|null */
    public function replay(
        string $principalId,
        string $operation,
        string $key,
        string $requestHash,
    ): ?array {
        $row = DB::table('task_idempotency_keys')->where([
            'principal_id' => $principalId,
            'operation' => $operation,
            'key_hash' => hash('sha256', $key),
        ])->first();

        if ($row === null) {
            return null;
        }
        if (! hash_equals((string) $row->request_hash, $requestHash)) {
            throw new TaskIdempotencyConflict;
        }
        if (! is_string($row->response_payload)) {
            throw new UnexpectedValueException('Stored task command response is unavailable.');
        }

        $response = json_decode($row->response_payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($response)) {
            throw new UnexpectedValueException('Stored task command response is invalid.');
        }

        return $response;
    }

    /** @param array<string, mixed> $response */
    public function store(
        string $principalId,
        string $operation,
        string $key,
        string $requestHash,
        string $taskId,
        array $response,
    ): void {
        $now = now();
        $inserted = DB::table('task_idempotency_keys')->insertOrIgnore([
            'principal_id' => $principalId,
            'operation' => $operation,
            'key_hash' => hash('sha256', $key),
            'request_hash' => $requestHash,
            'task_id' => $taskId,
            'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted !== 1) {
            throw new TaskIdempotencyConflict;
        }
    }
}
