<?php

namespace Modules\PlatformSettings\Features\Operations\Handler;

use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\PlatformSettings\Contracts\BackupOperationsGateway;
use Modules\PlatformSettings\Contracts\PlatformHealthGateway;
use Modules\PlatformSettings\Domain\PlatformHealthSnapshot;
use Modules\PlatformSettings\Infrastructure\Outbox\PlatformSettingsOutbox;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PlatformOperationsHandler
{
    public function __construct(
        private readonly PlatformHealthGateway $health,
        private readonly BackupOperationsGateway $backups,
        private readonly ?PlatformSettingsOutbox $outbox = null,
    ) {}

    public function health(): PlatformHealthSnapshot
    {
        return PlatformHealthSnapshot::fromChecks($this->health->snapshot());
    }

    /** @return array{status: string, last_successful_at: ?string, last_failed_at: ?string, last_validation_at: ?string} */
    public function backupStatus(): array
    {
        $status = $this->backups->status();

        return [
            'status' => $status->status,
            'last_successful_at' => $status->lastSuccessfulAt?->format(DATE_ATOM),
            'last_failed_at' => $status->lastFailedAt?->format(DATE_ATOM),
            'last_validation_at' => $status->lastValidationAt?->format(DATE_ATOM),
        ];
    }

    /** @return array{http_status: 202, operation_id: string, status: string} */
    public function requestBackup(string $requestedBy, string $idempotencyKey): array
    {
        $this->assertOperationsAvailable();
        if (trim($idempotencyKey) === '') {
            throw new DomainException('idempotency_key_required');
        }

        $keyHash = hash('sha256', $requestedBy.'|backup|'.$idempotencyKey);
        $operationId = (string) Str::uuid7();
        $operationId = DB::transaction(function () use ($operationId, $requestedBy, $keyHash): string {
            $now = now();
            $claimed = DB::table('platform_operation_requests')->insertOrIgnore([
                'id' => $operationId,
                'operation_type' => 'backup',
                'status' => 'requested',
                'requested_by' => $requestedBy,
                'confirmed_by' => null,
                'reason' => 'on_demand_backup',
                'operation_payload' => null,
                'idempotency_key_hash' => $keyHash,
                'dispatch_status' => 'queued',
                'dispatch_attempts' => 0,
                'dispatch_claimed_at' => null,
                'dispatch_completed_at' => null,
                'confirmed_at' => null,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($claimed === 0) {
                $existing = DB::table('platform_operation_requests')
                    ->where('requested_by', $requestedBy)
                    ->where('operation_type', 'backup')
                    ->where('idempotency_key_hash', $keyHash)
                    ->lockForUpdate()
                    ->first();
                if ($existing === null) {
                    throw new \LogicException('The backup idempotency claim could not be resolved.');
                }

                return (string) $existing->id;
            }

            $this->appendDispatchOutbox($operationId, 'backup');

            return $operationId;
        });

        return ['http_status' => 202, 'operation_id' => $operationId, 'status' => 'requested'];
    }

    /** @param list<string> $grantedCapabilities @return array{http_status: 202, operation_id: string, status: string} */
    public function requestRestore(string $requestedBy, string $backupId, string $reason, array $grantedCapabilities): array
    {
        $this->requireCapability($grantedCapabilities, 'platform_operations.restore.request');
        $this->assertReason($reason);
        if (trim($backupId) === '') {
            throw new DomainException('backup_id_required');
        }
        $this->assertOperationsAvailable();

        $operationId = (string) Str::uuid7();
        DB::transaction(function () use ($operationId, $requestedBy, $reason, $backupId): void {
            $now = now();
            DB::table('platform_operation_requests')->insert([
                'id' => $operationId,
                'operation_type' => 'restore_validation',
                'status' => 'requested',
                'requested_by' => $requestedBy,
                'confirmed_by' => null,
                'reason' => trim($reason),
                'operation_payload' => json_encode(['backup_id' => $backupId], JSON_THROW_ON_ERROR),
                'idempotency_key_hash' => null,
                'dispatch_status' => 'not_required',
                'dispatch_attempts' => 0,
                'dispatch_claimed_at' => null,
                'dispatch_completed_at' => null,
                'confirmed_at' => null,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->captureSnapshot($operationId, 'requested');
            DB::table('platform_operation_requests')->where('id', $operationId)->update([
                'status' => 'awaiting_confirmation',
                'lock_version' => 2,
                'updated_at' => $now,
            ]);
            $this->captureSnapshot($operationId, 'awaiting_confirmation');
        });

        return ['http_status' => 202, 'operation_id' => $operationId, 'status' => 'awaiting_confirmation'];
    }

    /** @param list<string> $grantedCapabilities @return array{http_status: 202, operation_id: string, status: string} */
    public function confirmRestore(string $operationId, string $confirmedBy, array $grantedCapabilities): array
    {
        $this->requireCapability($grantedCapabilities, 'platform_operations.restore.confirm');
        $this->assertOperationsAvailable();
        $backupId = DB::transaction(function () use ($operationId, $confirmedBy): string {
            $operation = DB::table('platform_operation_requests')->where('id', $operationId)->lockForUpdate()->first();
            if ($operation === null || (string) $operation->operation_type !== 'restore_validation') {
                throw new NotFoundHttpException('Restore validation request was not found.');
            }
            if ((string) $operation->status !== 'awaiting_confirmation') {
                throw new DomainException('restore_not_awaiting_confirmation');
            }
            if ((string) $operation->requested_by === $confirmedBy) {
                throw new DomainException('restore_requires_second_actor');
            }

            /** @var array{backup_id?: mixed} $payload */
            $payload = json_decode((string) $operation->operation_payload, true, 512, JSON_THROW_ON_ERROR);
            $backupId = $payload['backup_id'] ?? null;
            if (! is_string($backupId) || $backupId === '') {
                throw new DomainException('restore_backup_reference_missing');
            }

            DB::table('platform_operation_requests')->where('id', $operationId)->update([
                'confirmed_by' => $confirmedBy,
                'confirmed_at' => now(),
                'status' => 'confirmed',
                'lock_version' => (int) $operation->lock_version + 1,
                'updated_at' => now(),
            ]);
            $this->captureSnapshot($operationId, 'confirmed');
            DB::table('platform_operation_requests')->where('id', $operationId)->update([
                'status' => 'validation_running',
                'dispatch_status' => 'queued',
                'lock_version' => (int) $operation->lock_version + 2,
                'updated_at' => now(),
            ]);
            $this->captureSnapshot($operationId, 'validation_running');
            $this->appendDispatchOutbox($operationId, 'restore_validation');

            return $backupId;
        });

        return ['http_status' => 202, 'operation_id' => $operationId, 'status' => 'validation_running'];
    }

    /** @return array{operation_id: string, status: 'ready_for_operator'|'failed', runbook_reference?: string} */
    public function completeRestoreValidation(string $operationId, bool $valid): array
    {
        DB::transaction(function () use ($operationId, $valid): void {
            $operation = DB::table('platform_operation_requests')->where('id', $operationId)->lockForUpdate()->first();
            if ($operation === null || (string) $operation->operation_type !== 'restore_validation') {
                throw new NotFoundHttpException('Restore validation request was not found.');
            }
            if ((string) $operation->status !== 'validation_running') {
                throw new DomainException('restore_validation_not_running');
            }

            $status = $valid ? 'ready_for_operator' : 'failed';
            DB::table('platform_operation_requests')->where('id', $operationId)->update([
                'status' => $status,
                'lock_version' => (int) $operation->lock_version + 1,
                'updated_at' => now(),
            ]);
            $this->captureSnapshot($operationId, $status);
        });

        if (! $valid) {
            return ['operation_id' => $operationId, 'status' => 'failed'];
        }

        return [
            'operation_id' => $operationId,
            'status' => 'ready_for_operator',
            'runbook_reference' => (string) config('platform_operations.restore_operator_runbook_reference'),
        ];
    }

    /** @return array{operation_id: string, status: 'cancelled'} */
    public function cancelRestore(string $operationId): array
    {
        DB::transaction(function () use ($operationId): void {
            $operation = DB::table('platform_operation_requests')->where('id', $operationId)->lockForUpdate()->first();
            if ($operation === null || (string) $operation->operation_type !== 'restore_validation') {
                throw new NotFoundHttpException('Restore validation request was not found.');
            }
            if (! in_array((string) $operation->status, ['requested', 'awaiting_confirmation'], true)) {
                throw new DomainException('restore_cannot_be_cancelled');
            }

            DB::table('platform_operation_requests')->where('id', $operationId)->update([
                'status' => 'cancelled',
                'lock_version' => (int) $operation->lock_version + 1,
                'updated_at' => now(),
            ]);
            $this->captureSnapshot($operationId, 'cancelled');
        });

        return ['operation_id' => $operationId, 'status' => 'cancelled'];
    }

    private function assertOperationsAvailable(): void
    {
        if ($this->backups->status()->status !== 'available') {
            throw new DomainException('platform_operations_unavailable');
        }
    }

    /** @param list<string> $grantedCapabilities @return array{operation_id: string, status: string} */
    public function confirmDispatchRetry(string $operationId, string $confirmedBy, array $grantedCapabilities): array
    {
        return DB::transaction(function () use ($operationId, $confirmedBy, $grantedCapabilities): array {
            $operation = DB::table('platform_operation_requests')->where('id', $operationId)->lockForUpdate()->first();
            if ($operation === null || ! in_array((string) $operation->operation_type, ['backup', 'restore_validation'], true)) {
                throw new NotFoundHttpException('Platform operation request was not found.');
            }
            if ((string) $operation->dispatch_status !== 'reconciliation_required') {
                throw new DomainException('platform_operation_retry_not_reconciled');
            }
            if ((string) $operation->requested_by === $confirmedBy) {
                throw new DomainException('platform_operation_retry_requires_second_actor');
            }

            $capability = (string) $operation->operation_type === 'backup'
                ? 'platform_operations.backup.run'
                : 'platform_operations.restore.confirm';
            $this->requireCapability($grantedCapabilities, $capability);
            $status = (string) $operation->operation_type === 'backup' ? 'requested' : 'validation_running';
            DB::table('platform_operation_requests')->where('id', $operationId)->update([
                'status' => $status,
                'dispatch_status' => 'queued',
                'confirmed_by' => $confirmedBy,
                'confirmed_at' => now(),
                'dispatch_claimed_at' => null,
                'lock_version' => (int) $operation->lock_version + 1,
                'updated_at' => now(),
            ]);
            $this->captureSnapshot($operationId, 'retry_confirmed');

            return ['operation_id' => $operationId, 'status' => $status];
        });
    }

    /** @param list<string> $grantedCapabilities */
    private function requireCapability(array $grantedCapabilities, string $capability): void
    {
        if (! in_array($capability, $grantedCapabilities, true)) {
            throw new DomainException('capability_not_granted');
        }
    }

    private function assertReason(string $reason): void
    {
        $length = mb_strlen(trim($reason));
        if ($length < 10 || $length > 1000) {
            throw new DomainException('restore_reason_length_invalid');
        }
    }

    private function captureSnapshot(string $operationId, string $status): void
    {
        DB::table('platform_operation_snapshots')->insert([
            'id' => (string) Str::uuid7(),
            'operation_type' => 'restore_validation',
            'status' => $status,
            'source' => 'application',
            'snapshot_payload' => json_encode(['operation_id' => $operationId], JSON_THROW_ON_ERROR),
            'captured_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function appendDispatchOutbox(string $operationId, string $operationType): void
    {
        if ($this->outbox === null) {
            throw new \LogicException('PlatformSettingsOutbox is required for platform operation mutations.');
        }
        $this->outbox->appendOperationRequested($operationId, $operationType);
    }
}
