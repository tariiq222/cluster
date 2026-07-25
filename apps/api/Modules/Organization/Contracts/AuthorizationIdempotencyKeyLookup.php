<?php

namespace Modules\Organization\Contracts;

/**
 * Read surface for the Authorization-owned idempotency store. Owned by the
 * Organization module so HTTP controllers can delegate the lookup without
 * importing the higher-ranked Authorization module or reading the underlying
 * table directly; the Authorization module provides the implementation that
 * performs the DB query.
 */
interface AuthorizationIdempotencyKeyLookup
{
    /**
     * @return array{principal_id: string, operation: string, key_hash: string, request_hash: string, resource_id: string, response_status: int, response_payload: string, created_at: string, updated_at: string}|null
     */
    public function findExistingKey(string $principalId, string $operation, string $keyHash): ?array;

    /**
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string, resource_id: string, response_status: int, response_payload: string}  $row
     */
    public function recordKey(array $row): void;
}
