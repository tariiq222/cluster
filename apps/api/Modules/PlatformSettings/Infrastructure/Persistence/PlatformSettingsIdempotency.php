<?php

namespace Modules\PlatformSettings\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use JsonException;
use UnexpectedValueException;

final class PlatformSettingsIdempotency
{
    /**
     * @param  callable(): array<string, mixed>  $operation
     * @return array{request_hash_matches: bool, payload: array<string, mixed>}
     */
    public static function run(
        string $principalId,
        string $operationName,
        string $key,
        string $requestHash,
        callable $operation,
    ): array {
        return DB::transaction(function () use ($principalId, $operationName, $key, $requestHash, $operation): array {
            $keyHash = hash('sha256', $key);
            $query = DB::table('platform_settings_idempotency_keys')
                ->where('principal_id', $principalId)
                ->where('operation', $operationName)
                ->where('idempotency_key_hash', $keyHash);
            $existing = $query->lockForUpdate()->first();
            if ($existing !== null) {
                return self::replay($existing, $requestHash);
            }
            $claimed = DB::table('platform_settings_idempotency_keys')->insertOrIgnore([
                'principal_id' => $principalId,
                'operation' => $operationName,
                'idempotency_key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'response_payload' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($claimed !== 1) {
                $concurrent = $query->lockForUpdate()->first();
                if ($concurrent === null) {
                    throw new UnexpectedValueException('Platform settings idempotency claim is unavailable.');
                }

                return self::replay($concurrent, $requestHash);
            }
            $payload = $operation();
            $query->update([
                'response_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

            return ['request_hash_matches' => true, 'payload' => $payload];
        });
    }

    /** @return array{request_hash_matches: bool, payload: array<string, mixed>} */
    private static function replay(object $row, string $requestHash): array
    {
        if (! is_string($row->response_payload)) {
            throw new UnexpectedValueException('Stored platform settings idempotency state is incomplete.');
        }
        try {
            $payload = json_decode($row->response_payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnexpectedValueException('Stored platform settings idempotency response is invalid.');
        }
        if (! is_array($payload)) {
            throw new UnexpectedValueException('Stored platform settings idempotency response is invalid.');
        }

        return [
            'request_hash_matches' => is_string($row->request_hash) && hash_equals($row->request_hash, $requestHash),
            'payload' => $payload,
        ];
    }
}
