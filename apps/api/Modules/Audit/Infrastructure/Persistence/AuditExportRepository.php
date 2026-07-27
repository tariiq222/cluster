<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Events\AuditExportCompletedV1;
use Shared\Contracts\TransactionalOutbox;

/**
 * Repository for audit_export_jobs. Implements the synchronous, ready-only
 * export descriptor contract from M01 Task 5:
 *
 *  - A successful POST commits exactly one row with `status = 'ready'` and
 *    a frozen `snapshot_recorded_at` upper bound inside a single DB
 *    transaction, atomically appending one immutable Audit creation
 *    activity and exactly one `AuditExportCompletedV1` outbox event.
 *  - The repository never persists bytes, paths, hashes, or an artifact
 *    reference. Downloads re-authorize and re-read against `audit_events`
 *    under the frozen snapshot bound.
 *  - Expiry transitions the row to `status = 'expired'` and never mutates
 *    any other column. There is no `pending`, `completed`, or update path
 *    that overwrites the creation activity.
 *  - The legacy `requester_id` / `correlation_id` / `artifact_reference`
 *    / `artifact_sha256` / `completed_at` columns no longer exist; the
 *    table contract is fixed by `CreateAuditTables.php`.
 */
final class AuditExportRepository
{
    public const STATUS_READY = 'ready';

    public const STATUS_EXPIRED = 'expired';

    public const FORMAT_CSV = 'csv';

    public const FORMAT_NDJSON = 'ndjson';

    private const MAX_TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly TransactionalOutbox $outbox,
    ) {}

    /**
     * Insert the synchronous ready descriptor inside one transaction.
     *
     * The transaction boundary is the unit that protects all three
     * effects from the M01 Task 5 contract:
     *   1. audit_export_jobs row with status = 'ready',
     *   2. one immutable Audit creation activity,
     *   3. exactly one AuditExportCompletedV1 outbox event.
     *
     * The caller is responsible for inserting the Audit creation
     * activity via RecordAuditEvent before invoking this method; the
     * append of AuditExportCompletedV1 happens here so the outbox row
     * shares the same DB transaction as the descriptor.
     *
     * @param  array<string, mixed>  $query  canonical redacted filter set
     */
    public function create(
        string $id,
        string $principalId,
        ?string $facilityId,
        string $correlationId,
        array $query,
        string $queryHash,
        string $reasonRedacted,
        string $format,
        DateTimeImmutable $snapshotRecordedAt,
        int $eventCount,
        DateTimeImmutable $expiresAt,
        AuditExportCompletedV1 $completion,
    ): void {
        for ($attempt = 1; $attempt <= self::MAX_TRANSACTION_ATTEMPTS; $attempt++) {
            try {
                DB::transaction(function () use (
                    $id,
                    $principalId,
                    $facilityId,
                    $query,
                    $queryHash,
                    $reasonRedacted,
                    $format,
                    $snapshotRecordedAt,
                    $eventCount,
                    $expiresAt,
                    $completion,
                ): void {
                    DB::table('audit_export_jobs')->insert([
                        'id' => $id,
                        'principal_id' => $principalId,
                        'facility_id' => $facilityId,
                        'query' => json_encode($query, JSON_THROW_ON_ERROR),
                        'query_hash' => $queryHash,
                        'reason_redacted' => $reasonRedacted,
                        'format' => $format,
                        'snapshot_recorded_at' => self::databaseTimestamp($snapshotRecordedAt),
                        'status' => self::STATUS_READY,
                        'event_count' => $eventCount,
                        'lock_version' => 1,
                        'expires_at' => self::databaseTimestamp($expiresAt),
                        'created_at' => self::databaseTimestamp($snapshotRecordedAt),
                        'updated_at' => self::databaseTimestamp($snapshotRecordedAt),
                    ]);

                    $this->outbox->append(
                        $completion->eventId,
                        $id,
                        $completion->eventType(),
                        $completion->payload(),
                    );
                });

                return;
            } catch (QueryException $exception) {
                if ($attempt === self::MAX_TRANSACTION_ATTEMPTS || ! $this->isRetryableRace($exception)) {
                    throw $exception;
                }

                usleep(25_000 * $attempt);
            }
        }
    }

    /**
     * Idempotently transition the descriptor from `ready` to `expired`
     * using a CAS predicate on `(id, lock_version)`. The row's
     * `lock_version` is incremented on every successful transition so
     * cached ETags and pre-staged cursors can detect the change.
     *
     * The predicate ensures concurrent first observations only
     * advance the descriptor once: a loser observes `lock_version`
     * already > the snapshot it loaded and skips the update.
     */
    public function markExpired(string $id, int $expectedLockVersion): bool
    {
        $nextLockVersion = $expectedLockVersion + 1;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $affected = DB::table('audit_export_jobs')
            ->where('id', $id)
            ->where('status', self::STATUS_READY)
            ->where('lock_version', $expectedLockVersion)
            ->update([
                'status' => self::STATUS_EXPIRED,
                'lock_version' => $nextLockVersion,
                'updated_at' => self::databaseTimestamp($now),
            ]);

        return $affected === 1;
    }

    private static function databaseTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function isRetryableRace(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        if (in_array($sqlState, ['40001', '40P01'], true)
            || in_array($driverCode, [1205, 1213], true)) {
            return true;
        }

        return false;
    }
}
