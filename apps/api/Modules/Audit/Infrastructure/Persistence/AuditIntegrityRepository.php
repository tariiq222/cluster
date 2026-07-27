<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Modules\Audit\Domain\AuditEventCanonicalizer;
use Modules\Audit\Domain\AuditIntegrityHasher;
use Modules\Audit\Events\AuditIntegrityViolationDetectedV1;
use Shared\Contracts\TransactionalOutbox;

/**
 * Database adapter for Audit integrity verification and retention purge.
 *
 * Audit-event rows are append-only except for the retention path below;
 * integrity checkpoints are always append-only. Verification violations and
 * retention deletions are each committed atomically with their durable audit
 * evidence by the owning application handler. A failure at any step rolls the
 * entire transaction back so audit_events is byte-untouched.
 *
 * Public surface (Task 6 M01 Audit plan §5/§10):
 *  - verifyStream()             walks a single per-stream chain end-to-end
 *  - verifyRange()              walks a closed inclusive sequence range
 *  - latestCheckpointAnchorForStream()  finds the most recent verified anchor
 *  - purgeExpiredPrefix()       checks, checkpoints, then deletes a contiguous expired prefix
 *
 * The repository never logs or returns raw HMAC keys, raw canonical JSON,
 * raw previous_hash values, or raw event bodies — every response shape
 * carries only stream identity, sequence counts, status, and the same
 * anchor-relative position the public API contract promises.
 */
final class AuditIntegrityRepository
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_VIOLATED = 'violated';

    public const CHECKPOINT_KIND_VERIFICATION = 'verification';

    public const CHECKPOINT_KIND_RETENTION_PURGE = 'retention_purge';

    public const MAX_VERIFICATION_RANGE = 5000;

    public const MAX_PURGE_BATCH = 1000;

    public function __construct(
        private readonly AuditEventCanonicalizer $canonicalizer,
        private readonly AuditIntegrityHasher $hasher,
        private readonly TransactionalOutbox $outbox,
    ) {}

    /**
     * Verify an entire single stream chain end-to-end.
     *
     * @return array{
     *     verification_id: string,
     *     stream_key: string,
     *     first_sequence: int,
     *     last_sequence: int,
     *     verified_event_count: int,
     *     status: string,
     *     integrity_key_version: string,
     *     checkpoint_id: string,
     * }
     */
    public function verifyStream(
        string $verificationId,
        string $correlationId,
        string $streamKey,
        string $actorId,
    ): array {
        $this->assertUuidV7($verificationId, 'verificationId');
        $this->assertUuidV7($correlationId, 'correlationId');
        $this->assertStreamKey($streamKey);

        $boundedRows = DB::table('audit_events')
            ->where('stream_key', $streamKey)
            ->orderBy('stream_sequence')
            ->limit(self::MAX_VERIFICATION_RANGE + 1)
            ->get();

        if ($boundedRows->isEmpty()) {
            return $this->writeVerificationCheckpoint(
                verificationId: $verificationId,
                correlationId: $correlationId,
                streamKey: $streamKey,
                firstSequence: 0,
                lastSequence: 0,
                eventCount: 0,
                terminalHash: null,
                keyVersion: null,
                actorId: $actorId,
                status: self::STATUS_VERIFIED,
                details: ['reason' => 'empty_stream'],
            );
        }

        if ($boundedRows->count() > self::MAX_VERIFICATION_RANGE) {
            throw new InvalidArgumentException('audit_integrity_range_too_large');
        }

        $firstSequence = (int) $boundedRows->first()->stream_sequence;
        $lastSequence = (int) $boundedRows->last()->stream_sequence;

        return $this->walkAndCheckpoint(
            verificationId: $verificationId,
            correlationId: $correlationId,
            streamKey: $streamKey,
            actorId: $actorId,
            rows: $boundedRows,
            firstSequence: $firstSequence,
            lastSequence: $lastSequence,
            range: 'stream',
        );
    }

    /**
     * Verify a bounded inclusive sequence range on a single stream.
     *
     * @return array{
     *     verification_id: string,
     *     stream_key: string,
     *     first_sequence: int,
     *     last_sequence: int,
     *     verified_event_count: int,
     *     status: string,
     *     integrity_key_version: string,
     *     checkpoint_id: string,
     * }
     */
    public function verifyRange(
        string $verificationId,
        string $correlationId,
        string $streamKey,
        string $actorId,
        int $firstSequence,
        int $lastSequence,
    ): array {
        $this->assertUuidV7($verificationId, 'verificationId');
        $this->assertUuidV7($correlationId, 'correlationId');
        $this->assertStreamKey($streamKey);

        if ($firstSequence < 1
            || $lastSequence < $firstSequence
            || ($lastSequence - $firstSequence + 1) > self::MAX_VERIFICATION_RANGE) {
            throw new InvalidArgumentException('audit_integrity_range_invalid');
        }

        $rows = DB::table('audit_events')
            ->where('stream_key', $streamKey)
            ->whereBetween('stream_sequence', [$firstSequence, $lastSequence])
            ->orderBy('stream_sequence')
            ->get();

        if ($rows->isEmpty()) {
            try {
                $this->assertBoundedAnchor($streamKey, $firstSequence);
            } catch (InvalidArgumentException $exception) {
                if (! $this->isRangeFullyCoveredByRetentionPurge(
                    $streamKey,
                    $firstSequence,
                    $lastSequence,
                )) {
                    throw $exception;
                }
            }

            return $this->writeVerificationCheckpoint(
                verificationId: $verificationId,
                correlationId: $correlationId,
                streamKey: $streamKey,
                firstSequence: $firstSequence,
                lastSequence: $lastSequence,
                eventCount: 0,
                terminalHash: null,
                keyVersion: null,
                actorId: $actorId,
                status: self::STATUS_VERIFIED,
                details: ['reason' => 'empty_range_or_fully_purged'],
            );
        }

        $expectedSequence = $firstSequence;
        $previousHash = $this->previousRowHash($streamKey, $firstSequence - 1);
        $verifiedCount = 0;
        $terminalHash = $previousHash;
        $keyVersion = null;
        $firstMismatchSequence = null;
        $firstMismatchReason = null;
        $rowList = $rows->values()->all();

        foreach ($rowList as $row) {
            $actualSequence = (int) $row->stream_sequence;

            while ($expectedSequence < $actualSequence) {
                $anchor = $this->retentionPurgeTerminalHash(
                    $streamKey,
                    $actualSequence - 1,
                );
                if ($anchor === null) {
                    $firstMismatchSequence = $expectedSequence;
                    $firstMismatchReason = 'stream_sequence_gap';
                    break 2;
                }
                $previousHash = $anchor;
                $expectedSequence = $actualSequence;
            }

            if (isset($firstMismatchSequence)) {
                break;
            }

            $stored = $row->previous_hash === null ? null : (string) $row->previous_hash;
            if ($stored !== $previousHash) {
                $firstMismatchSequence = $actualSequence;
                $firstMismatchReason = 'chain_mismatch';
                break;
            }

            $canonical = $this->canonicalizer->canonicalizeFromRow((array) $row);
            $rowKeyVersion = (string) $row->integrity_key_version;
            $storedHash = (string) $row->event_hash;
            if (! $this->hasher->verify(
                $canonical,
                $previousHash,
                $rowKeyVersion,
                $storedHash,
            )) {
                $firstMismatchSequence = $actualSequence;
                $firstMismatchReason = 'chain_mismatch';
                break;
            }

            $previousHash = $storedHash;
            $terminalHash = $storedHash;
            $keyVersion = $rowKeyVersion;
            $verifiedCount++;
            $expectedSequence++;
        }

        if ($firstMismatchSequence === null && $expectedSequence <= $lastSequence) {
            $anchor = $this->retentionPurgeTerminalHash($streamKey, $lastSequence);
            if ($anchor === null) {
                $firstMismatchSequence = $expectedSequence;
                $firstMismatchReason = 'stream_sequence_gap';
            }
        }

        if ($firstMismatchSequence !== null) {
            return $this->writeVerificationCheckpoint(
                verificationId: $verificationId,
                correlationId: $correlationId,
                streamKey: $streamKey,
                firstSequence: $firstSequence,
                lastSequence: $lastSequence,
                eventCount: $verifiedCount,
                terminalHash: null,
                keyVersion: null,
                actorId: $actorId,
                status: self::STATUS_VIOLATED,
                details: [
                    'reason' => $firstMismatchReason ?? 'stream_sequence_gap',
                    'first_mismatch_stream_sequence' => $firstMismatchSequence,
                    'range_kind' => 'bounded',
                ],
            );
        }

        return $this->writeVerificationCheckpoint(
            verificationId: $verificationId,
            correlationId: $correlationId,
            streamKey: $streamKey,
            firstSequence: $firstSequence,
            lastSequence: $lastSequence,
            eventCount: $verifiedCount,
            terminalHash: $terminalHash,
            keyVersion: $keyVersion,
            actorId: $actorId,
            status: self::STATUS_VERIFIED,
            details: ['range_kind' => 'bounded'],
        );
    }

    /**
     * Return the terminal event hash from the most recent verified checkpoint
     * of either kind. A retention-purge checkpoint is the only surviving
     * anchor once its covered prefix has been deleted.
     */
    public function latestCheckpointAnchorForStream(string $streamKey): ?string
    {
        $row = DB::table('audit_integrity_checkpoints')
            ->where('stream_key', $streamKey)
            ->where('status', self::STATUS_VERIFIED)
            ->orderByDesc('last_sequence')
            ->orderByDesc('created_at')
            ->first(['terminal_event_hash']);

        if ($row === null) {
            return null;
        }

        $hash = (string) $row->terminal_event_hash;

        return $hash === '' ? null : $hash;
    }

    /**
     * Purge the contiguous expired prefix of one stream whose `retention_until`
     * is strictly before $cutoff. The chain of that prefix MUST verify
     * successfully; an immutable `retention_purge` checkpoint is written first
     * and the matching rows are deleted in the same transaction. The
     * application handler records the sanitized retention activity around this
     * transaction so any later recorder failure rolls the purge back.
     *
     * @return array{
     *     checkpoint_id: string,
     *     stream_key: string,
     *     first_sequence: int,
     *     last_sequence: int,
     *     deleted_event_count: int,
     *     terminal_event_hash: ?string,
     *     status: string,
     *     integrity_key_version: string,
     * }
     */
    public function purgeExpiredPrefix(
        string $checkpointId,
        string $streamKey,
        DateTimeImmutable $cutoff,
        string $actorId,
        string $correlationId,
    ): array {
        $this->assertUuidV7($checkpointId, 'checkpointId');
        $this->assertStreamKey($streamKey);
        $this->assertUuidV7($correlationId, 'correlationId');

        $databaseCutoff = $cutoff->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');

        return DB::transaction(function () use (
            $checkpointId,
            $streamKey,
            $databaseCutoff,
            $actorId,
            $correlationId,
        ): array {
            $candidate = DB::table('audit_events')
                ->where('stream_key', $streamKey)
                ->where('retention_until', '<', $databaseCutoff)
                ->orderBy('stream_sequence')
                ->limit(self::MAX_PURGE_BATCH)
                ->lockForUpdate()
                ->get();

            if ($candidate->isEmpty()) {
                return [
                    'checkpoint_id' => '',
                    'stream_key' => $streamKey,
                    'first_sequence' => 0,
                    'last_sequence' => 0,
                    'deleted_event_count' => 0,
                    'terminal_event_hash' => null,
                    'status' => self::STATUS_VERIFIED,
                    'integrity_key_version' => '',
                ];
            }

            $first = (int) $candidate->first()->stream_sequence;
            $last = (int) $candidate->last()->stream_sequence;

            $streamFirstSequence = (int) DB::table('audit_events')
                ->where('stream_key', $streamKey)
                ->min('stream_sequence');
            if ($first !== $streamFirstSequence) {
                throw new InvalidArgumentException('audit_retention_non_prefix');
            }

            $expectedSequence = $first;
            foreach ($candidate as $row) {
                if ((int) $row->stream_sequence !== $expectedSequence) {
                    throw new InvalidArgumentException('audit_retention_non_prefix');
                }
                $expectedSequence++;
            }

            $alreadyCheckpointed = DB::table('audit_integrity_checkpoints')
                ->where('stream_key', $streamKey)
                ->where('kind', self::CHECKPOINT_KIND_RETENTION_PURGE)
                ->where('last_sequence', '>=', $first)
                ->first();

            if ($alreadyCheckpointed !== null) {
                throw new InvalidArgumentException('audit_retention_already_checkpointed');
            }

            $previousCheckpointHash = $this->latestCheckpointHashForStream($streamKey);

            $previousHash = $this->previousRowHash($streamKey, $first - 1);

            $terminalHash = null;
            $keyVersion = null;
            foreach ($candidate as $row) {
                $stored = $row->previous_hash === null ? null : (string) $row->previous_hash;
                if ($stored !== $previousHash) {
                    throw new InvalidArgumentException('audit_retention_chain_violated');
                }

                $canonical = $this->canonicalizer->canonicalizeFromRow((array) $row);
                $rowKeyVersion = (string) $row->integrity_key_version;
                if (! $this->hasher->verify(
                    $canonical,
                    $previousHash,
                    $rowKeyVersion,
                    (string) $row->event_hash,
                )) {
                    throw new InvalidArgumentException('audit_retention_chain_violated');
                }

                $previousHash = (string) $row->event_hash;
                $terminalHash = $previousHash;
                $keyVersion = $rowKeyVersion;
            }

            if ($keyVersion === null) {
                throw new InvalidArgumentException('audit_retention_key_version_missing');
            }

            $now = $this->nowUtc();

            $details = [
                'reason' => 'retention_expired_prefix',
                'first_sequence' => $first,
                'last_sequence' => $last,
                'deleted_event_count' => $candidate->count(),
            ];

            $checkpointHash = $this->computeCheckpointHash(
                streamKey: $streamKey,
                kind: self::CHECKPOINT_KIND_RETENTION_PURGE,
                firstSequence: $first,
                lastSequence: $last,
                eventCount: $candidate->count(),
                terminalHash: $terminalHash,
                keyVersion: $keyVersion,
                previousCheckpointHash: $previousCheckpointHash,
                verifiedAt: $now,
                details: $details,
            );

            try {
                DB::table('audit_integrity_checkpoints')->insert([
                    'id' => $checkpointId,
                    'stream_key' => $streamKey,
                    'kind' => self::CHECKPOINT_KIND_RETENTION_PURGE,
                    'first_sequence' => $first,
                    'last_sequence' => $last,
                    'event_count' => $candidate->count(),
                    'terminal_event_hash' => $terminalHash,
                    'previous_checkpoint_hash' => $previousCheckpointHash,
                    'checkpoint_hash' => $checkpointHash,
                    'integrity_key_version' => $keyVersion,
                    'status' => self::STATUS_VERIFIED,
                    'actor_id' => $actorId,
                    'correlation_id' => $correlationId,
                    'details' => json_encode(
                        $details,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ),
                    'verified_at' => $now->format('Y-m-d H:i:s.v'),
                    'created_at' => $now->format('Y-m-d H:i:s.v'),
                ]);
            } catch (QueryException $exception) {
                $message = strtolower($exception->getMessage());
                $allowed = [
                    'audit_integrity_checkpoints_stream_kind_last_status_unique',
                    'audit_integrity_checkpoints_stream_kind_last_unique',
                    'audit_integrity_checkpoints_hash_unique',
                    'audit_integrity_checkpoints.checkpoint_hash',
                    'integrity_checkpoints.id',
                    'audit_integrity_checkpoints.stream_key, audit_integrity_checkpoints.kind, audit_integrity_checkpoints.last_sequence',
                    'audit_integrity_checkpoints.stream_key, audit_integrity_checkpoints.kind, audit_integrity_checkpoints.last_sequence, audit_integrity_checkpoints.status',
                ];
                if (! $this->messageMatches($message, $allowed)) {
                    throw $exception;
                }

                $existingReplay = DB::table('audit_integrity_checkpoints')
                    ->where('stream_key', $streamKey)
                    ->where('kind', self::CHECKPOINT_KIND_RETENTION_PURGE)
                    ->where('last_sequence', $last)
                    ->first();

                if ($existingReplay === null) {
                    throw $exception;
                }

                return [
                    'checkpoint_id' => (string) $existingReplay->id,
                    'stream_key' => (string) $existingReplay->stream_key,
                    'first_sequence' => (int) $existingReplay->first_sequence,
                    'last_sequence' => (int) $existingReplay->last_sequence,
                    'deleted_event_count' => (int) $existingReplay->event_count,
                    'terminal_event_hash' => (string) $existingReplay->terminal_event_hash,
                    'status' => (string) $existingReplay->status,
                    'integrity_key_version' => (string) ($existingReplay->integrity_key_version ?? ''),
                ];
            }

            $deletedRows = DB::table('audit_events')
                ->where('stream_key', $streamKey)
                ->whereBetween('stream_sequence', [$first, $last])
                ->delete();

            if ($deletedRows !== $candidate->count()) {
                throw new InvalidArgumentException('audit_retention_delete_count_mismatch');
            }

            return [
                'checkpoint_id' => $checkpointId,
                'stream_key' => $streamKey,
                'first_sequence' => $first,
                'last_sequence' => $last,
                'deleted_event_count' => $deletedRows,
                'terminal_event_hash' => $terminalHash,
                'status' => self::STATUS_VERIFIED,
                'integrity_key_version' => $keyVersion,
            ];
        }, 1);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $rows
     * @return array{
     *     verification_id: string,
     *     stream_key: string,
     *     first_sequence: int,
     *     last_sequence: int,
     *     verified_event_count: int,
     *     status: string,
     *     integrity_key_version: string,
     *     checkpoint_id: string,
     * }
     */
    private function walkAndCheckpoint(
        string $verificationId,
        string $correlationId,
        string $streamKey,
        string $actorId,
        Collection $rows,
        int $firstSequence,
        int $lastSequence,
        string $range,
    ): array {
        $previousHash = $this->previousRowHash($streamKey, $firstSequence - 1);

        $verifiedCount = 0;
        $terminalHash = null;
        $keyVersion = null;
        $firstMismatchStreamSequence = null;
        $firstMismatchReason = null;

        $expectedSequence = $firstSequence;
        foreach ($rows as $row) {
            $actualSequence = (int) $row->stream_sequence;

            while ($expectedSequence < $actualSequence) {
                $anchor = $this->retentionPurgeTerminalHash(
                    $streamKey,
                    $actualSequence - 1,
                );
                if ($anchor === null) {
                    $firstMismatchStreamSequence = $expectedSequence;
                    $firstMismatchReason = 'stream_sequence_gap';
                    break 2;
                }
                $previousHash = $anchor;
                $expectedSequence = $actualSequence;
            }

            if (isset($firstMismatchStreamSequence)) {
                break;
            }

            $stored = $row->previous_hash === null ? null : (string) $row->previous_hash;
            if ($stored !== $previousHash) {
                $firstMismatchStreamSequence = $actualSequence;
                $firstMismatchReason = 'chain_mismatch';
                break;
            }

            $canonical = $this->canonicalizer->canonicalizeFromRow((array) $row);
            $rowKeyVersion = (string) $row->integrity_key_version;
            $storedHash = (string) $row->event_hash;
            if (! $this->hasher->verify(
                $canonical,
                $previousHash,
                $rowKeyVersion,
                $storedHash,
            )) {
                $firstMismatchStreamSequence = $actualSequence;
                $firstMismatchReason = 'chain_mismatch';
                break;
            }

            $previousHash = $storedHash;
            $terminalHash = $storedHash;
            $keyVersion = $rowKeyVersion;
            $verifiedCount++;
            $expectedSequence++;
        }

        if ($firstMismatchStreamSequence === null && $expectedSequence <= $lastSequence) {
            $anchor = $this->retentionPurgeTerminalHash($streamKey, $lastSequence);
            if ($anchor === null) {
                $firstMismatchStreamSequence = $expectedSequence;
                $firstMismatchReason = 'stream_sequence_gap';
            }
        }

        $violated = $firstMismatchStreamSequence !== null;

        try {
            return DB::transaction(function () use (
                $verificationId,
                $correlationId,
                $streamKey,
                $actorId,
                $firstSequence,
                $lastSequence,
                $verifiedCount,
                $terminalHash,
                $keyVersion,
                $violated,
                $firstMismatchStreamSequence,
                $firstMismatchReason,
                $range,
            ): array {
                $result = $this->writeVerificationCheckpoint(
                    verificationId: $verificationId,
                    correlationId: $correlationId,
                    streamKey: $streamKey,
                    firstSequence: $firstSequence,
                    lastSequence: $lastSequence,
                    eventCount: $verifiedCount,
                    terminalHash: $terminalHash,
                    keyVersion: $keyVersion,
                    actorId: $actorId,
                    status: $violated ? self::STATUS_VIOLATED : self::STATUS_VERIFIED,
                    details: $violated
                        ? [
                            'reason' => $firstMismatchReason ?? 'chain_mismatch',
                            'first_mismatch_stream_sequence' => $firstMismatchStreamSequence,
                            'range_kind' => $range,
                        ]
                        : ['range_kind' => $range],
                );
                if ($violated && $result['checkpoint_id'] === $verificationId) {
                    $this->outbox->append(
                        $verificationId,
                        $streamKey,
                        AuditIntegrityViolationDetectedV1::EVENT_TYPE,
                        (new AuditIntegrityViolationDetectedV1(
                            eventId: Str::uuid7()->toString(),
                            verificationId: $verificationId,
                            streamKey: $streamKey,
                            correlationId: $correlationId,
                            firstMismatchStreamSequence: $firstMismatchStreamSequence,
                            verifiedEventCount: $verifiedCount,
                            detectedAt: $this->nowUtc(),
                        ))->payload(),
                    );
                }

                return $result;
            }, 1);
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout')) {
                throw new InvalidArgumentException('audit_integrity_lock_unavailable', 0, $exception);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{
     *     verification_id: string,
     *     stream_key: string,
     *     first_sequence: int,
     *     last_sequence: int,
     *     verified_event_count: int,
     *     status: string,
     *     integrity_key_version: string,
     *     checkpoint_id: string,
     * }
     */
    private function writeVerificationCheckpoint(
        string $verificationId,
        string $correlationId,
        string $streamKey,
        int $firstSequence,
        int $lastSequence,
        int $eventCount,
        ?string $terminalHash,
        ?string $keyVersion,
        string $actorId,
        string $status,
        array $details,
    ): array {
        $existing = DB::table('audit_integrity_checkpoints')
            ->where('id', $verificationId)
            ->where('kind', self::CHECKPOINT_KIND_VERIFICATION)
            ->first();

        if ($existing !== null) {
            return [
                'verification_id' => $verificationId,
                'stream_key' => $streamKey,
                'first_sequence' => $firstSequence,
                'last_sequence' => $lastSequence,
                'verified_event_count' => $eventCount,
                'status' => (string) $existing->status,
                'integrity_key_version' => (string) ($existing->integrity_key_version ?? ''),
                'checkpoint_id' => (string) $existing->id,
            ];
        }

        $now = $this->nowUtc();
        $previousCheckpointHash = $this->latestCheckpointHashForStream($streamKey);

        $checkpointHash = $this->computeCheckpointHash(
            streamKey: $streamKey,
            kind: self::CHECKPOINT_KIND_VERIFICATION,
            firstSequence: $firstSequence,
            lastSequence: $lastSequence,
            eventCount: $eventCount,
            terminalHash: $terminalHash,
            keyVersion: $keyVersion ?? '',
            previousCheckpointHash: $previousCheckpointHash,
            verifiedAt: $now,
            details: $details,
        );

        try {
            DB::table('audit_integrity_checkpoints')->insert([
                'id' => $verificationId,
                'stream_key' => $streamKey,
                'kind' => self::CHECKPOINT_KIND_VERIFICATION,
                'first_sequence' => $firstSequence,
                'last_sequence' => $lastSequence,
                'event_count' => $eventCount,
                'terminal_event_hash' => $terminalHash ?? '',
                'previous_checkpoint_hash' => $previousCheckpointHash,
                'checkpoint_hash' => $checkpointHash,
                'integrity_key_version' => $keyVersion ?? '',
                'status' => $status,
                'actor_id' => $actorId,
                'correlation_id' => $correlationId,
                'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'verified_at' => $now->format('Y-m-d H:i:s.v'),
                'created_at' => $now->format('Y-m-d H:i:s.v'),
            ]);
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            $allowed = [
                'audit_integrity_checkpoints_stream_kind_last_status_unique',
                'audit_integrity_checkpoints_stream_kind_last_unique',
                'audit_integrity_checkpoints_hash_unique',
                'audit_integrity_checkpoints.checkpoint_hash',
                'integrity_checkpoints.id',
                'audit_integrity_checkpoints.stream_key, audit_integrity_checkpoints.kind, audit_integrity_checkpoints.last_sequence',
                'audit_integrity_checkpoints.stream_key, audit_integrity_checkpoints.kind, audit_integrity_checkpoints.last_sequence, audit_integrity_checkpoints.status',
            ];
            if (! $this->messageMatches($message, $allowed)) {
                throw $exception;
            }

            $existingReplay = DB::table('audit_integrity_checkpoints')
                ->where(function ($query) use ($verificationId, $streamKey, $lastSequence, $status): void {
                    $query->where('id', $verificationId)
                        ->orWhere(function ($range) use ($streamKey, $lastSequence, $status): void {
                            $range->where('stream_key', $streamKey)
                                ->where('kind', self::CHECKPOINT_KIND_VERIFICATION)
                                ->where('last_sequence', $lastSequence)
                                ->where('status', $status);
                        });
                })
                ->where('kind', self::CHECKPOINT_KIND_VERIFICATION)
                ->first();

            if ($existingReplay === null) {
                throw $exception;
            }

            return [
                'verification_id' => (string) $existingReplay->id,
                'stream_key' => (string) $existingReplay->stream_key,
                'first_sequence' => (int) $existingReplay->first_sequence,
                'last_sequence' => (int) $existingReplay->last_sequence,
                'verified_event_count' => (int) $existingReplay->event_count,
                'status' => (string) $existingReplay->status,
                'integrity_key_version' => (string) ($existingReplay->integrity_key_version ?? ''),
                'checkpoint_id' => (string) $existingReplay->id,
            ];
        }

        return [
            'verification_id' => $verificationId,
            'stream_key' => $streamKey,
            'first_sequence' => $firstSequence,
            'last_sequence' => $lastSequence,
            'verified_event_count' => $eventCount,
            'status' => $status,
            'integrity_key_version' => $keyVersion ?? '',
            'checkpoint_id' => $verificationId,
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function computeCheckpointHash(
        string $streamKey,
        string $kind,
        int $firstSequence,
        int $lastSequence,
        int $eventCount,
        ?string $terminalHash,
        string $keyVersion,
        ?string $previousCheckpointHash,
        DateTimeImmutable $verifiedAt,
        array $details,
    ): string {
        $payload = [
            'stream_key' => $streamKey,
            'kind' => $kind,
            'first_sequence' => $firstSequence,
            'last_sequence' => $lastSequence,
            'event_count' => $eventCount,
            'terminal_event_hash' => $terminalHash ?? '',
            'integrity_key_version' => $keyVersion,
            'previous_checkpoint_hash' => $previousCheckpointHash,
            'verified_at' => $verifiedAt->format('Y-m-d\TH:i:s.v\Z'),
            'details' => $details,
        ];

        try {
            $encoded = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('audit_checkpoint_canonical_invalid', 0, $exception);
        }

        return hash('sha256', $encoded);
    }

    private function latestCheckpointHashForStream(string $streamKey): ?string
    {
        $hash = DB::table('audit_integrity_checkpoints')
            ->where('stream_key', $streamKey)
            ->where('status', self::STATUS_VERIFIED)
            ->orderByDesc('last_sequence')
            ->orderByDesc('created_at')
            ->value('checkpoint_hash');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /**
     * Return the previous-hash link for the row that immediately precedes
     * the requested stream_sequence. A surviving `audit_events` row always
     * wins; a deleted predecessor is recoverable only through a verified
     * `retention_purge` checkpoint whose terminal_event_hash IS that row's
     * event hash (the only allowed substitute anchor per M01 §5).
     */
    private function previousRowHash(string $streamKey, int $sequence): ?string
    {
        if ($sequence < 1) {
            return null;
        }

        $row = DB::table('audit_events')
            ->where('stream_key', $streamKey)
            ->where('stream_sequence', $sequence)
            ->first(['event_hash']);

        if ($row !== null) {
            return (string) $row->event_hash;
        }

        $anchor = DB::table('audit_integrity_checkpoints')
            ->where('stream_key', $streamKey)
            ->where('kind', self::CHECKPOINT_KIND_RETENTION_PURGE)
            ->where('status', self::STATUS_VERIFIED)
            ->where('last_sequence', $sequence)
            ->value('terminal_event_hash');

        if (! is_string($anchor) || $anchor === '') {
            throw new InvalidArgumentException('audit_integrity_chain_gap');
        }

        return $anchor;
    }

    /**
     * Confirm that the row immediately preceding a bounded verification range
     * either still exists or was legally purged (so the retention_purge
     * checkpoint can serve as the chain anchor). A missing anchor is the
     * only real gap; absent that, an empty bounded range is a no-op rather
     * than a violation.
     */
    private function assertBoundedAnchor(string $streamKey, int $firstSequence): void
    {
        if ($firstSequence <= 1) {
            return;
        }

        $this->previousRowHash($streamKey, $firstSequence - 1);
    }

    /**
     * Return the terminal_event_hash of a verified retention_purge checkpoint
     * whose last_sequence equals $sequence. This is the chain anchor that
     * allows a verification walk to skip over a legally purged prefix and
     * resume at the first surviving row. Returns null when no checkpoint
     * covers the requested sequence; the caller decides whether the gap is
     * a real violation or a covered transition.
     */
    private function retentionPurgeTerminalHash(string $streamKey, int $sequence): ?string
    {
        $anchor = DB::table('audit_integrity_checkpoints')
            ->where('stream_key', $streamKey)
            ->where('kind', self::CHECKPOINT_KIND_RETENTION_PURGE)
            ->where('status', self::STATUS_VERIFIED)
            ->where('last_sequence', $sequence)
            ->value('terminal_event_hash');

        return is_string($anchor) && $anchor !== '' ? $anchor : null;
    }

    /**
     * Confirm that every requested sequence in [first, last] lies inside one
     * or more verified retention_purge checkpoints, so an empty-rows result
     * can be safely reported as verified without writing a false violation
     * or surfacing the anchor lookup exception as a 500. The caller already
     * failed to find an exact anchor at first_sequence - 1; this method
     * walks forward from first_sequence to confirm coverage.
     */
    private function isRangeFullyCoveredByRetentionPurge(
        string $streamKey,
        int $firstSequence,
        int $lastSequence,
    ): bool {
        $sequence = $firstSequence;
        $streamLastSequence = (int) DB::table('audit_events')
            ->where('stream_key', $streamKey)
            ->max('stream_sequence');

        while ($sequence <= $lastSequence) {
            $row = DB::table('audit_events')
                ->where('stream_key', $streamKey)
                ->where('stream_sequence', $sequence)
                ->first(['stream_sequence']);

            if ($row !== null) {
                if ($sequence > $streamLastSequence) {
                    return false;
                }
                $sequence++;

                continue;
            }

            $nextSurvivor = DB::table('audit_events')
                ->where('stream_key', $streamKey)
                ->where('stream_sequence', '>=', $sequence)
                ->where('stream_sequence', '<=', $lastSequence)
                ->orderBy('stream_sequence')
                ->value('stream_sequence');

            $gapEnd = $nextSurvivor === null
                ? min($lastSequence, $streamLastSequence === 0 ? $lastSequence : $streamLastSequence)
                : ((int) $nextSurvivor) - 1;

            if ($gapEnd < $sequence) {
                return false;
            }

            $covering = DB::table('audit_integrity_checkpoints')
                ->where('stream_key', $streamKey)
                ->where('kind', self::CHECKPOINT_KIND_RETENTION_PURGE)
                ->where('status', self::STATUS_VERIFIED)
                ->where('first_sequence', '<=', $sequence)
                ->where('last_sequence', '>=', $gapEnd)
                ->first(['id']);

            if ($covering === null) {
                return false;
            }

            $sequence = $gapEnd + 1;
        }

        return true;
    }

    private function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @param  list<string>  $needles
     */
    private function messageMatches(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function assertUuidV7(string $value, string $field): void
    {
        if (preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
            $value,
        ) !== 1) {
            throw new InvalidArgumentException("audit_integrity_{$field}_invalid");
        }
    }

    private function assertStreamKey(string $streamKey): void
    {
        if (strlen($streamKey) > 160
            || preg_match('/\A[a-z][a-z0-9_-]*:[a-z][a-z0-9_-]*:(?:[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|global)\z/', $streamKey) !== 1) {
            throw new InvalidArgumentException('audit_stream_key_invalid');
        }
    }
}
