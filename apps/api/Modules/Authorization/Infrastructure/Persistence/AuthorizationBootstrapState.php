<?php

namespace Modules\Authorization\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    /** @return array{state: string, completed_at: ?string, completed_by_user_id: ?string, version: int} */
    public function current(): array
    {
        $row = $this->row();

        return $this->project($row);
    }

    public function isPending(): bool
    {
        return $this->row()->state === 'pending';
    }

    /**
     * @return array{status: 'completed'|'conflict'|'replay', payload: array{state: string, completed_at: ?string, completed_by_user_id: ?string}, version: int}
     */
    public function complete(string $principalId, string $reason, string $idempotencyKey, string $requestHash): array
    {
        return DB::transaction(function () use ($principalId, $reason, $idempotencyKey, $requestHash): array {
            $keyHash = hash('sha256', $idempotencyKey);
            $existing = DB::table('authorization_idempotency_keys')
                ->where('principal_id', $principalId)
                ->where('operation', self::OPERATION)
                ->where('key_hash', $keyHash)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $requestHash) {
                    return ['status' => 'conflict', 'payload' => $this->current(), 'version' => 0];
                }

                $payload = json_decode((string) $existing->response_payload, true, 512, JSON_THROW_ON_ERROR);
                $payloadVersion = is_array($payload) && isset($payload['version']) ? (int) $payload['version'] : 0;

                return [
                    'status' => 'replay',
                    'payload' => $payload,
                    'version' => $payloadVersion,
                ];
            }

            $row = DB::table('authorization_bootstrap')->orderBy('created_at')->lockForUpdate()->first();
            if ($row === null) {
                throw new InvalidArgumentException('authorization_bootstrap_missing');
            }
            if ($row->state !== 'pending') {
                return ['status' => 'conflict', 'payload' => $this->project($row), 'version' => 0];
            }

            $now = now()->utc();
            DB::table('authorization_bootstrap')->where('id', $row->id)->update([
                'state' => 'complete',
                'completed_by_user_id' => $principalId,
                'completed_at' => $now,
                'reason' => $reason,
                'lock_version' => (int) $row->lock_version + 1,
                'updated_at' => $now,
            ]);
            $payload = $this->project(DB::table('authorization_bootstrap')->where('id', $row->id)->first());
            DB::table('authorization_idempotency_keys')->insert([
                'principal_id' => $principalId,
                'operation' => self::OPERATION,
                'key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'resource_id' => (string) $row->id,
                'response_status' => 200,
                'response_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('access_decisions')->insert([
                'id' => Str::uuid7()->toString(),
                'decision' => 'allow',
                'action' => self::OPERATION,
                'resource_type' => 'authorization_bootstrap',
                'resource_id' => $row->id,
                'reason_codes' => json_encode(['bootstrap_completed'], JSON_THROW_ON_ERROR),
                'policy_version' => 'bootstrap-gate-v1',
                'facts_version' => 'bootstrap-'.((int) $row->lock_version + 1),
                'authorization_trace_id' => Str::uuid7()->toString(),
                'evaluated_at' => $now,
                'correlation_id' => Str::uuid7()->toString(),
                'classification' => 'internal',
                'access_context' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
                'actor_user_id' => $principalId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['status' => 'completed', 'payload' => $payload, 'version' => (int) $row->lock_version + 1];
        });
    }

    private function row(): stdClass
    {
        $row = DB::table('authorization_bootstrap')->orderBy('created_at')->first();
        if ($row === null) {
            throw new InvalidArgumentException('authorization_bootstrap_missing');
        }

        return $row;
    }

    /** @return array{state: string, completed_at: ?string, completed_by_user_id: ?string, version: int} */
    private function project(stdClass $row): array
    {
        return [
            'state' => (string) $row->state,
            'completed_at' => $row->completed_at === null ? null : (string) $row->completed_at,
            'completed_by_user_id' => $row->completed_by_user_id === null ? null : (string) $row->completed_by_user_id,
            'version' => (int) $row->lock_version,
        ];
    }
}
