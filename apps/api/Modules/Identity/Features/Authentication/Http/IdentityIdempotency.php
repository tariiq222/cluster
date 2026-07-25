<?php

namespace Modules\Identity\Features\Authentication\Http;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

final class IdentityIdempotency
{
    /**
     * @param  array{principal_id: string, operation: string, key_hash: string}  $scope
     * @return array{request_hash_matches: bool, response: array<string, mixed>|null}|null
     */
    public function find(array $scope, string $requestHash): ?array
    {
        $row = $this->query($scope)->first();
        if (! $row instanceof stdClass) {
            return null;
        }

        $response = null;
        if (is_string($row->response_payload)) {
            $decoded = json_decode($row->response_payload, true);
            $response = is_array($decoded) ? $decoded : null;
        }

        return [
            'request_hash_matches' => hash_equals((string) $row->request_hash, $requestHash),
            'response' => $response,
        ];
    }

    /**
     * @param  array{principal_id: string, operation: string, key_hash: string, resource_type: string, resource_id: string}  $scope
     */
    public function claim(array $scope, string $requestHash): bool
    {
        return DB::table('identity_idempotency_keys')->insertOrIgnore([
            'principal_id' => $scope['principal_id'],
            'operation' => $scope['operation'],
            'idempotency_key_hash' => $scope['key_hash'],
            'request_hash' => $requestHash,
            'resource_type' => $scope['resource_type'],
            'resource_id' => $scope['resource_id'],
            'response_payload' => null,
            'response_version' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    /**
     * @param  array{principal_id: string, operation: string, key_hash: string}  $scope
     * @param  array<string, mixed>  $response
     */
    public function store(array $scope, array $response): void
    {
        $updated = $this->query($scope)->update([
            'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
            'response_version' => 1,
            'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('The Identity idempotency response could not be stored.');
        }
    }

    /**
     * @param  array{principal_id: string, operation: string, key_hash: string}  $scope
     */
    private function query(array $scope): mixed
    {
        return DB::table('identity_idempotency_keys')
            ->where('principal_id', $scope['principal_id'])
            ->where('operation', $scope['operation'])
            ->where('idempotency_key_hash', $scope['key_hash']);
    }
}
