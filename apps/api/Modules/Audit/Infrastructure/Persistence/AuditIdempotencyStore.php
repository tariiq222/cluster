<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;

/**
 * Idempotency store for audit export and console commands. A second call
 * with the same (principal_id, operation, key_hash) tuple returns the
 * stored eventId without re-running the work.
 */
final class AuditIdempotencyStore
{
    public function reserve(
        string $principalId,
        string $operation,
        string $keyHash,
        string $requestHash,
        string $eventId,
    ): ?string {
        $existing = DB::table('audit_idempotency_keys')
            ->where('principal_id', $principalId)
            ->where('operation', $operation)
            ->where('key_hash', $keyHash)
            ->first();
        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                throw new \RuntimeException('audit_idempotency_key_request_hash_mismatch');
            }

            return $existing->event_id;
        }
        DB::table('audit_idempotency_keys')->insert([
            'principal_id' => $principalId,
            'operation' => $operation,
            'key_hash' => $keyHash,
            'request_hash' => $requestHash,
            'event_id' => $eventId,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return null;
    }
}
