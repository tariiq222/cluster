<?php

namespace Modules\PlatformSettings\Features\Operations\Handler;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\PlatformSettings\Contracts\BackupOperationsGateway;
use Throwable;

final class PlatformOperationsDispatchHandler
{
    public function __construct(private readonly BackupOperationsGateway $backups) {}

    public function run(int $limit): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('platform_operations_dispatch_limit_invalid');
        }

        $processed = 0;
        $claimExpiredAt = now()->subSeconds((int) config('platform_operations.dispatch_claim_timeout_seconds', 120));
        $this->reconcileExpiredClaims($claimExpiredAt, $limit);
        $ids = DB::table('platform_operation_requests')
            ->whereIn('operation_type', ['backup', 'restore_validation'])
            ->where('dispatch_status', 'queued')
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $operation = $this->claim((string) $id);
            if ($operation === null) {
                continue;
            }

            try {
                if ($operation->operation_type === 'backup') {
                    $this->backups->requestBackup((string) $operation->id);
                } else {
                    /** @var array{backup_id?: mixed} $payload */
                    $payload = json_decode((string) $operation->operation_payload, true, 512, JSON_THROW_ON_ERROR);
                    $backupId = $payload['backup_id'] ?? null;
                    if (! is_string($backupId) || $backupId === '') {
                        throw new \RuntimeException('restore_backup_reference_missing');
                    }
                    $this->backups->requestRestoreValidation((string) $operation->id, $backupId);
                }
                $this->complete($operation);
                $processed++;
            } catch (Throwable) {
                $this->fail($operation);
            }
        }

        return $processed;
    }

    private function claim(string $operationId): ?object
    {
        return DB::transaction(function () use ($operationId): ?object {
            $operation = DB::table('platform_operation_requests')->where('id', $operationId)->lockForUpdate()->first();
            if ($operation === null || (string) $operation->dispatch_status !== 'queued') {
                return null;
            }

            $updated = DB::table('platform_operation_requests')->where('id', $operationId)->where('dispatch_status', 'queued')
                ->update([
                    'dispatch_status' => 'running',
                    'dispatch_attempts' => (int) $operation->dispatch_attempts + 1,
                    'dispatch_claimed_at' => now(),
                    'lock_version' => (int) $operation->lock_version + 1,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                return null;
            }

            return DB::table('platform_operation_requests')->where('id', $operationId)->first();
        });
    }

    private function complete(object $operation): void
    {
        DB::transaction(function () use ($operation): void {
            $status = (string) $operation->operation_type === 'backup' ? 'completed' : 'ready_for_operator';
            DB::table('platform_operation_requests')->where('id', $operation->id)->where('dispatch_status', 'running')->update([
                'status' => $status,
                'dispatch_status' => 'completed',
                'dispatch_completed_at' => now(),
                'lock_version' => (int) $operation->lock_version + 1,
                'updated_at' => now(),
            ]);
            $this->snapshot((string) $operation->id, (string) $operation->operation_type, $status);
            DB::table('platform_settings_outbox')->where('aggregate_id', $operation->id)->whereNull('published_at')->update([
                'published_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function fail(object $operation): void
    {
        DB::transaction(function () use ($operation): void {
            DB::table('platform_operation_requests')->where('id', $operation->id)->where('dispatch_status', 'running')->update([
                'status' => 'failed',
                'dispatch_status' => 'reconciliation_required',
                'dispatch_claimed_at' => null,
                'lock_version' => (int) $operation->lock_version + 1,
                'updated_at' => now(),
            ]);
            $this->snapshot((string) $operation->id, (string) $operation->operation_type, 'reconciliation_required');
        });
    }

    private function reconcileExpiredClaims(mixed $claimExpiredAt, int $limit): void
    {
        $ids = DB::table('platform_operation_requests')
            ->whereIn('operation_type', ['backup', 'restore_validation'])
            ->where('dispatch_status', 'running')
            ->where('dispatch_claimed_at', '<=', $claimExpiredAt)
            ->orderBy('dispatch_claimed_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $claimExpiredAt): void {
                $operation = DB::table('platform_operation_requests')->where('id', $id)->lockForUpdate()->first();
                if ($operation === null
                    || (string) $operation->dispatch_status !== 'running'
                    || $operation->dispatch_claimed_at === null
                    || strtotime((string) $operation->dispatch_claimed_at) > $claimExpiredAt->getTimestamp()) {
                    return;
                }

                DB::table('platform_operation_requests')->where('id', $id)->where('dispatch_status', 'running')->update([
                    'dispatch_status' => 'reconciliation_required',
                    'lock_version' => (int) $operation->lock_version + 1,
                    'updated_at' => now(),
                ]);
                $this->snapshot((string) $id, (string) $operation->operation_type, 'reconciliation_required');
            });
        }
    }

    private function snapshot(string $operationId, string $operationType, string $status): void
    {
        DB::table('platform_operation_snapshots')->insert([
            'id' => (string) Str::uuid7(),
            'operation_type' => $operationType,
            'status' => $status,
            'source' => 'dispatch_worker',
            'snapshot_payload' => json_encode(['operation_id' => $operationId], JSON_THROW_ON_ERROR),
            'captured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
