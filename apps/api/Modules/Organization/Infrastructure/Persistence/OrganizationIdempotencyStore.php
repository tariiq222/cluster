<?php

namespace Modules\Organization\Infrastructure\Persistence;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use JsonException;
use stdClass;
use UnexpectedValueException;

/**
 * Owns the organization_idempotency_keys table access for the create-flow
 * handlers in the Organization module. The store does NOT open transactions
 * of its own — every operation runs inside the caller's DB::transaction so
 * each handler can keep its own write boundaries.
 */
final class OrganizationIdempotencyStore
{
    /**
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     */
    public function query(array $idempotency): Builder
    {
        return DB::table('organization_idempotency_keys')
            ->where('principal_id', $idempotency['principal_id'])
            ->where('operation', $idempotency['operation'])
            ->where('idempotency_key_hash', $idempotency['key_hash']);
    }

    /**
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     */
    public function claim(array $idempotency, string $resourceType, string $resourceId): bool
    {
        $rows = DB::table('organization_idempotency_keys')->insertOrIgnore([
            'principal_id' => $idempotency['principal_id'],
            'operation' => $idempotency['operation'],
            'idempotency_key_hash' => $idempotency['key_hash'],
            'request_hash' => $idempotency['request_hash'],
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $rows === 1;
    }

    /**
     * @param  array{principal_id: string, operation: string, key_hash: string, request_hash: string}  $idempotency
     * @param  array<string, mixed>  $payload
     */
    public function storeResponse(array $idempotency, array $payload): void
    {
        $this->query($idempotency)->update([
            'response_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    /**
     * Decodes the stored response_payload on a previously-claimed idempotency
     * key row. The caller is responsible for verifying the resource_type and
     * for building the public replay result shape.
     *
     * @return array<string, mixed>
     */
    public function decodeResponse(stdClass $key, string $resourceLabel): array
    {
        if (! is_string($key->response_payload)) {
            throw new UnexpectedValueException('Stored '.$resourceLabel.' idempotency state is incomplete.');
        }
        try {
            $payload = json_decode($key->response_payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('Stored '.$resourceLabel.' idempotency response is invalid.');
        }
        if (! is_array($payload)) {
            throw new UnexpectedValueException('Stored '.$resourceLabel.' idempotency response is invalid.');
        }

        return $payload;
    }

    /**
     * Timing-safe comparison of the stored request_hash against the incoming
     * hash. Handles non-string stored values defensively — a non-string stored
     * hash forces request_hash_matches=false rather than throwing.
     */
    public function hashMatches(stdClass $key, string $requestHash): bool
    {
        return is_string($key->request_hash ?? null)
            && hash_equals($key->request_hash, $requestHash);
    }
}
