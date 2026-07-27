<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Audit\Events\AuditExportCompletedV1;
use Shared\Contracts\TransactionalOutbox;

/**
 * Repository for audit_export_jobs. Writes the export job, appends the
 * canonical outbox event in the same transaction, and exposes helpers
 * for the bounded collection/detail queries.
 */
final class AuditExportRepository
{
    public function __construct(
        private readonly TransactionalOutbox $outbox,
    ) {}

    public function create(
        string $id,
        string $requesterId,
        string $correlationId,
        array $filters,
        string $idempotencyKey,
        string $expiresAtUtc,
    ): void {
        DB::transaction(function () use (
            $id, $requesterId, $correlationId, $filters, $idempotencyKey, $expiresAtUtc,
        ): void {
            DB::table('audit_export_jobs')->insert([
                'id' => $id,
                'requester_id' => $requesterId,
                'correlation_id' => $correlationId,
                'status' => 'pending',
                'filters' => json_encode($filters, JSON_THROW_ON_ERROR),
                'idempotency_key' => $idempotencyKey,
                'expires_at' => $expiresAtUtc,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        });
    }

    public function markCompleted(
        string $id,
        string $artifactReference,
        string $sha256,
        int $eventCount,
        string $expiresAtUtc,
        string $correlationId,
    ): void {
        DB::transaction(function () use (
            $id, $artifactReference, $sha256, $eventCount, $expiresAtUtc, $correlationId,
        ): void {
            $now = now('UTC');
            DB::table('audit_export_jobs')
                ->where('id', $id)
                ->update([
                    'status' => 'completed',
                    'artifact_reference' => $artifactReference,
                    'artifact_sha256' => $sha256,
                    'event_count' => $eventCount,
                    'expires_at' => $expiresAtUtc,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->outbox->append(
                'audit-export-'.$id,
                $id,
                AuditExportCompletedV1::EVENT_TYPE,
                [
                    'exportId' => $id,
                    'requesterId' => (string) (DB::table('audit_export_jobs')->where('id', $id)->value('requester_id') ?? ''),
                    'correlationId' => $correlationId,
                    'artifactReference' => $artifactReference,
                    'sha256' => $sha256,
                    'eventCount' => $eventCount,
                    'completedAt' => $now->format('Y-m-d\TH:i:s.v\Z'),
                    'expiresAt' => $expiresAtUtc,
                ],
            );
        });
    }

    public function find(string $id): ?object
    {
        return DB::table('audit_export_jobs')->where('id', $id)->first();
    }

    public function findByIdempotencyKey(string $requesterId, string $key): ?object
    {
        return DB::table('audit_export_jobs')
            ->where('requester_id', $requesterId)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * @return list<object>
     */
    public function list(string $requesterId, int $limit, ?string $cursor): array
    {
        $builder = DB::table('audit_export_jobs')
            ->where('requester_id', $requesterId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1);
        if ($cursor !== null) {
            $builder->where('id', '<', $cursor);
        }
        $rows = $builder->get();

        return $rows->slice(0, $limit)->values()->all();
    }
}
