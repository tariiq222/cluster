<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Contracts\AuthorizationIdempotencyKeyLookup;

/**
 * Reads and writes the Authorization-owned authorization_idempotency_keys table
 * on behalf of the Organization module. The interface is owned by the lower-ranked
 * Organization module; the implementation lives here in Authorization where the
 * higher-ranked module is allowed to import Organization contracts.
 */
final class DatabaseAuthorizationIdempotencyKeyLookup implements AuthorizationIdempotencyKeyLookup
{
    public function findExistingKey(string $principalId, string $operation, string $keyHash): ?array
    {
        $row = DB::table('authorization_idempotency_keys')->where([
            'principal_id' => $principalId,
            'operation' => $operation,
            'key_hash' => $keyHash,
        ])->first();

        if ($row === null) {
            return null;
        }

        return [
            'principal_id' => (string) $row->principal_id,
            'operation' => (string) $row->operation,
            'key_hash' => (string) $row->key_hash,
            'request_hash' => (string) $row->request_hash,
            'resource_id' => (string) $row->resource_id,
            'response_status' => (int) $row->response_status,
            'response_payload' => (string) $row->response_payload,
            'created_at' => (string) $row->created_at,
            'updated_at' => (string) $row->updated_at,
        ];
    }

    public function recordKey(array $row): void
    {
        try {
            DB::table('authorization_idempotency_keys')->insert([
                'principal_id' => $row['principal_id'],
                'operation' => $row['operation'],
                'key_hash' => $row['key_hash'],
                'request_hash' => $row['request_hash'],
                'resource_id' => $row['resource_id'],
                'response_status' => $row['response_status'],
                'response_payload' => $row['response_payload'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // The unique-key collision is the safe replay path; the caller treats
            // any failure as "must recompute", which is correct for a write that
            // races against a concurrent submission.
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
        }
    }
}