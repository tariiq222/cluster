<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Contracts\AuditEventInput;

/**
 * Strict persistence for Task 5/6 HTTP command response replay.
 *
 * Audit event replay is keyed directly by audit_events.id and never passes
 * through this store.
 *
 * Race safety: callers may invoke {@see findResponse()} from a separate
 * transaction than {@see storeResponse()}, so this store exposes a typed
 * conflict return path for {@see storeResponse()} when a concurrent
 * writer has already claimed the same (principalId, operation, keyHash)
 * triple. That is the only behaviour change; the table contract,
 * column list, and unique constraint are unchanged.
 */
final class AuditIdempotencyStore
{
    private const MAX_TRANSACTION_ATTEMPTS = 3;

    public function findResponse(
        string $principalId,
        string $operation,
        string $keyHash,
    ): ?object {
        return DB::table('audit_idempotency_keys')
            ->where('principal_id', $principalId)
            ->where('operation', $operation)
            ->where('key_hash', $keyHash)
            ->first([
                'id',
                'request_hash',
                'response_status',
                'response_payload',
                'resource_id',
                'created_at',
            ]);
    }

    /**
     * @param  array<string, mixed>  $responsePayload
     *
     * @throws AuditIdempotencyConflict when a concurrent writer already
     *                                  claimed the same triple. The caller
     *                                  must roll back the surrounding
     *                                  transaction so the losing side does
     *                                  not leave partial state behind.
     */
    public function storeResponse(
        string $id,
        string $principalId,
        string $operation,
        string $keyHash,
        string $requestHash,
        int $responseStatus,
        array $responsePayload,
        string $resourceId,
    ): void {
        AuditEventInput::assertUuidV7($id, 'id');
        AuditEventInput::assertUuidV7($principalId, 'principalId');
        AuditEventInput::assertUuidV7($resourceId, 'resourceId');
        if (preg_match('/\A[0-9a-f]{64}\z/', $keyHash) !== 1) {
            throw new \InvalidArgumentException('audit_idempotency_key_hash_invalid');
        }
        if (preg_match('/\A[0-9a-f]{64}\z/', $requestHash) !== 1) {
            throw new \InvalidArgumentException('audit_idempotency_request_hash_invalid');
        }
        if ($responseStatus < 100 || $responseStatus > 599) {
            throw new \InvalidArgumentException('audit_idempotency_response_status_invalid');
        }

        $now = self::now();

        for ($attempt = 1; $attempt <= self::MAX_TRANSACTION_ATTEMPTS; $attempt++) {
            try {
                DB::table('audit_idempotency_keys')->insert([
                    'id' => $id,
                    'principal_id' => $principalId,
                    'operation' => $operation,
                    'key_hash' => $keyHash,
                    'request_hash' => $requestHash,
                    'response_status' => $responseStatus,
                    'response_payload' => json_encode(
                        $responsePayload,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ),
                    'resource_id' => $resourceId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return;
            } catch (QueryException $exception) {
                if ($this->isUniqueKeyRace($exception)) {
                    throw new AuditIdempotencyConflict(
                        $principalId,
                        $operation,
                        $keyHash,
                        $exception,
                    );
                }

                throw $exception;
            }
        }
    }

    private function isUniqueKeyRace(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        if (! in_array($sqlState, ['23000', '23505'], true)) {
            return false;
        }
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        if ($driverCode === 1062 || $driverCode === 19) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'audit_idempotency_keys_principal_operation_key_unique')
            || str_contains($message, 'audit_idempotency_keys.principal_id, audit_idempotency_keys.operation, audit_idempotency_keys.key_hash');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
