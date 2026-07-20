<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Lifecycle of the one-row authorization bootstrap record. The record starts
 * `pending`; completion is an idempotent, audited, single transition guarded
 * by lock_version. All writes stay inside Authorization-owned tables.
 */
final class AuthorizationBootstrapState
{
    private const OPERATION = 'authorization.bootstrap.complete';

    /** @return array{state: string, completed_at: ?string, completed_by_user_id: ?string} */
    public function current(): array
    {
        $row = $this->row();

        return $this->project($row);
    }

    /**
     * @return array{status: 'completed'|'conflict'|'replay', payload: array{state: string, completed_at: ?string, completed_by_user_id: ?string}, version: int}
     */
    public function complete(string $principalId, string $reason, string $idempotencyKey, string $requestHash): array
    {
        $keyHash = hash('sha256', $idempotencyKey);
        $existing = DB::table('authorization_idempotency_keys')
            ->where('principal_id', $principalId)
            ->where('operation', self::OPERATION)
            ->where('key_hash', $keyHash)
            ->first();
        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                return ['status' => 'conflict', 'payload' => $this->current(), 'version' => 0];
            }

            $payload = is_string($existing->response_payload)
                ? json_decode($existing->response_payload, true, 512, JSON_THROW_ON_ERROR)
                : $this->current();

            return ['status' => 'replay', 'payload' => $payload, 'version' => (int) ($existing->response_status ?? 200)];
        }

        $row = $this->row();
        if ($row->state === 'complete') {
            return ['status' => 'conflict', 'payload' => $this->project($row), 'version' => 0];
        }

        $now = now()->utc();
        $updated = DB::table('authorization_bootstrap')
            ->where('id', $row->id)
            ->where('state', 'pending')
            ->where('lock_version', (int) $row->lock_version)
            ->update([
                'state' => 'complete',
                'completed_by_user_id' => $principalId,
                'completed_at' => $now,
                'reason' => $reason,
                'lock_version' => (int) $row->lock_version + 1,
                'updated_at' => $now,
            ]);
        if ($updated !== 1) {
            return ['status' => 'conflict', 'payload' => $this->current(), 'version' => 0];
        }

        $payload = $this->current();
        try {
            DB::table('authorization_idempotency_keys')->insert([
                'principal_id' => $principalId,
                'operation' => self::OPERATION,
                'key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'resource_id' => (string) $row->id,
                'response_status' => 200,
                'response_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
        }

        return ['status' => 'completed', 'payload' => $payload, 'version' => 200];
    }

    private function row(): stdClass
    {
        $row = DB::table('authorization_bootstrap')->orderBy('created_at')->first();
        if ($row === null) {
            throw new InvalidArgumentException('authorization_bootstrap_missing');
        }

        return $row;
    }

    /** @return array{state: string, completed_at: ?string, completed_by_user_id: ?string} */
    private function project(stdClass $row): array
    {
        return [
            'state' => (string) $row->state,
            'completed_at' => $row->completed_at === null ? null : (string) $row->completed_at,
            'completed_by_user_id' => $row->completed_by_user_id === null ? null : (string) $row->completed_by_user_id,
        ];
    }
}
