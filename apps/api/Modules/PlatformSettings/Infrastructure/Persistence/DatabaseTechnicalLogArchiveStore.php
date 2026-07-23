<?php

namespace Modules\PlatformSettings\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\PlatformSettings\Contracts\TechnicalLogArchiveStore;
use Modules\PlatformSettings\Domain\ArchiveBatch;
use Modules\PlatformSettings\Domain\ArchiveManifest;
use RuntimeException;

final class DatabaseTechnicalLogArchiveStore implements TechnicalLogArchiveStore
{
    public function manifest(string $manifestId): ?ArchiveManifest
    {
        $row = DB::table('technical_log_archive_manifests')->where('id', $manifestId)->first();

        return $row === null ? null : $this->manifestFromRow($row);
    }

    public function recordVerifiedArchive(ArchiveBatch $batch, ArchiveManifest $manifest): ArchiveManifest
    {
        return DB::transaction(function () use ($batch, $manifest): ArchiveManifest {
            $existing = DB::table('technical_log_archive_batches')->where('id', $batch->id)->lockForUpdate()->first();
            if ($existing !== null) {
                if ((string) $existing->status !== 'archived' || (string) $existing->manifest_id !== $manifest->id) {
                    throw new RuntimeException('Technical log archive batch state is invalid.');
                }
                $persisted = $this->manifest($manifest->id);
                if ($persisted === null || $persisted->status !== 'verified') {
                    throw new RuntimeException('Technical log archive manifest state is invalid.');
                }

                return $persisted;
            }

            $now = now();
            DB::table('technical_log_archive_manifests')->insert([
                'id' => $manifest->id,
                'status' => 'verified',
                'entry_count' => $manifest->count,
                'first_occurred_at' => $manifest->firstOccurredAt,
                'last_occurred_at' => $manifest->lastOccurredAt,
                'sha256' => $manifest->sha256,
                'storage_reference' => $manifest->storageReference,
                'manifest_reference' => $manifest->manifestReference,
                'verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('technical_log_archive_batches')->insert([
                'id' => $batch->id,
                'status' => 'archived',
                'manifest_id' => $manifest->id,
                'active_log_months' => $batch->activeLogMonths,
                'source_entry_ids' => json_encode(array_map(static fn ($entry): string => $entry->id, $batch->entries), JSON_THROW_ON_ERROR),
                'archived_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return new ArchiveManifest(
                $manifest->id,
                $manifest->count,
                $manifest->firstOccurredAt,
                $manifest->lastOccurredAt,
                $manifest->sha256,
                $manifest->storageReference,
                $manifest->manifestReference,
                'verified',
            );
        });
    }

    public function createRestoreRequest(string $jobId, string $manifestId, string $actorId, string $reason, array $readModel, DateTimeImmutable $expiresAt): void
    {
        $now = now();
        DB::table('technical_log_archive_restore_requests')->insert([
            'id' => $jobId,
            'manifest_id' => $manifestId,
            'status' => 'available',
            'requested_by' => $actorId,
            'reason' => $reason,
            'read_model' => json_encode($readModel, JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function activeRestoreReadModel(string $jobId, DateTimeImmutable $now): ?array
    {
        return DB::transaction(function () use ($jobId, $now): ?array {
            $restore = DB::table('technical_log_archive_restore_requests')->where('id', $jobId)->lockForUpdate()->first();
            if ($restore === null || (string) $restore->status !== 'available') {
                return null;
            }
            if (new DateTimeImmutable((string) $restore->expires_at) <= $now) {
                DB::table('technical_log_archive_restore_requests')->where('id', $jobId)->update([
                    'status' => 'expired',
                    'read_model' => null,
                    'updated_at' => now(),
                ]);

                return null;
            }

            $readModel = json_decode((string) $restore->read_model, true, 512, JSON_THROW_ON_ERROR);

            return is_array($readModel) ? $readModel : null;
        });
    }

    public function restoreStatus(string $jobId): ?string
    {
        $status = DB::table('technical_log_archive_restore_requests')->where('id', $jobId)->value('status');

        return $status === null ? null : (string) $status;
    }

    private function manifestFromRow(object $row): ArchiveManifest
    {
        return new ArchiveManifest(
            (string) $row->id,
            (int) $row->entry_count,
            new DateTimeImmutable((string) $row->first_occurred_at),
            new DateTimeImmutable((string) $row->last_occurred_at),
            (string) $row->sha256,
            (string) $row->storage_reference,
            (string) $row->manifest_reference,
            (string) $row->status,
        );
    }
}
