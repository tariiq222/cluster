<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Modules\Audit\Contracts\AuditEventInput;
use Modules\Audit\Contracts\AuditEventReceipt;
use Modules\Audit\Contracts\RecordAuditEvent;
use Modules\Audit\Domain\AuditEventCanonicalizer;
use Modules\Audit\Domain\AuditEventIdConflict;
use Modules\Audit\Domain\AuditIntegrityHasher;
use Modules\Audit\Domain\AuditRetentionPolicy;
use Modules\Audit\Domain\SensitiveValueRedactor;
use Modules\Audit\Events\AuditEventRecordedV1;
use Shared\Contracts\TransactionalOutbox;

/**
 * Appends one redacted, per-stream chained Audit event and its safe outbox
 * notification in the caller's transaction.
 */
final class DatabaseRecordAuditEvent implements RecordAuditEvent
{
    private const CONTEXT_SCHEMA_VERSION = 1;

    private const REDACTION_POLICY_VERSION = 'v1';

    private const MAX_TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly TransactionalOutbox $outbox,
        private readonly SensitiveValueRedactor $redactor,
        private readonly AuditIntegrityHasher $hasher,
        private readonly AuditRetentionPolicy $retention,
        private readonly AuditEventCanonicalizer $canonicalizer,
        private readonly string $activeIntegrityKeyVersion,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,31}\z/', $activeIntegrityKeyVersion) !== 1) {
            throw new InvalidArgumentException('audit_integrity_key_version_invalid');
        }
    }

    public function record(AuditEventInput $input): AuditEventReceipt
    {
        $context = AuditEventInput::canonicalizeContext(
            $this->redactor->redact($input->context),
        );
        $streamKey = $this->streamKey($input);
        $requestHash = $this->requestHash($input, $context);

        // Nested producer calls run inside the caller's transaction and must
        // participate atomically with the outer command. Retrying inside a
        // nested transaction would either mask the caller's failure (we
        // re-raise on the same exception, but the outer transaction's view
        // of the audit side-effect is corrupted) or duplicate the audit row
        // (a SAVEPOINT-rolled-back inner attempt can leave behind an
        // outbox/outbox-dispatched skeleton before the outer rollback fires).
        // The outer command must own full-transaction retry; we contribute
        // by failing fast on the first transient error so the outer
        // retry-loop replays the entire effect.
        $outerTransactionLevel = DB::transactionLevel();
        if ($outerTransactionLevel !== 0) {
            return $this->appendOrReplay(
                $input,
                $context,
                $streamKey,
                $requestHash,
            );
        }

        for ($attempt = 1; $attempt <= self::MAX_TRANSACTION_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(
                    fn (): AuditEventReceipt => $this->appendOrReplay(
                        $input,
                        $context,
                        $streamKey,
                        $requestHash,
                    ),
                    1,
                );
            } catch (QueryException $exception) {
                if ($attempt === self::MAX_TRANSACTION_ATTEMPTS || ! self::isRetryableRace($exception)) {
                    throw $exception;
                }

                usleep(25_000 * $attempt);
            }
        }

        throw new \LogicException('audit_event_retry_loop_exhausted');
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    private function appendOrReplay(
        AuditEventInput $input,
        array $context,
        string $streamKey,
        string $requestHash,
    ): AuditEventReceipt {
        $existing = DB::table('audit_events')
            ->where('id', $input->eventId)
            ->lockForUpdate()
            ->first(['id', 'request_hash', 'stream_key', 'stream_sequence', 'event_hash', 'recorded_at']);

        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                throw new AuditEventIdConflict($input->eventId, 'request_hash_mismatch');
            }

            return new AuditEventReceipt(
                eventId: (string) $existing->id,
                streamKey: (string) $existing->stream_key,
                streamSequence: (int) $existing->stream_sequence,
                eventHash: (string) $existing->event_hash,
                recordedAt: self::dateTime((string) $existing->recorded_at),
                replayed: true,
            );
        }

        $tail = DB::table('audit_events')
            ->where('stream_key', $streamKey)
            ->orderByDesc('stream_sequence')
            ->lockForUpdate()
            ->first(['stream_sequence', 'event_hash']);

        $retentionAnchor = $tail === null
            ? DB::table('audit_integrity_checkpoints')
                ->where('stream_key', $streamKey)
                ->where('kind', 'retention_purge')
                ->where('status', 'verified')
                ->orderByDesc('last_sequence')
                ->lockForUpdate()
                ->first(['last_sequence', 'terminal_event_hash'])
            : null;

        $streamSequence = $tail !== null
            ? (int) $tail->stream_sequence + 1
            : ($retentionAnchor === null ? 1 : (int) $retentionAnchor->last_sequence + 1);
        $previousHash = $tail !== null
            ? (string) $tail->event_hash
            : ($retentionAnchor === null ? null : (string) $retentionAnchor->terminal_event_hash);
        $recordedAt = self::now();
        $retentionUntil = $this->retention->retentionUntil($recordedAt, $input->retentionClass);

        $recordedEvent = new AuditEventRecordedV1(
            eventId: $input->eventId,
            sourceModule: $input->sourceModule,
            action: $input->action,
            actorType: $input->actorType,
            actorId: $input->actorId,
            originalActorId: $input->originalActorId,
            subjectType: $input->subjectType,
            subjectId: $input->subjectId,
            correlationId: $input->correlationId,
            outcome: $input->outcome,
            classification: $input->classification,
            retentionClass: $input->retentionClass,
            streamKey: $streamKey,
            streamSequence: $streamSequence,
            occurredAt: $input->occurredAt,
            recordedAt: $recordedAt,
        );
        $eventHash = $this->hasher->eventHash(
            $this->canonicalEvent(
                $input,
                $context,
                $requestHash,
                $streamKey,
                $streamSequence,
                $recordedAt,
                $retentionUntil,
            ),
            $previousHash,
            $this->activeIntegrityKeyVersion,
        );

        DB::table('audit_events')->insert([
            'id' => $input->eventId,
            'request_hash' => $requestHash,
            'stream_key' => $streamKey,
            'stream_sequence' => $streamSequence,
            'source_module' => $input->sourceModule,
            'action' => $input->action,
            'event_type' => $input->eventType,
            'actor_type' => $input->actorType,
            'actor_id' => $input->actorId,
            'original_actor_id' => $input->originalActorId,
            'subject_type' => $input->subjectType,
            'subject_id' => $input->subjectId,
            'correlation_id' => $input->correlationId,
            'outcome' => $input->outcome,
            'classification' => $input->classification,
            'context' => self::encode($context),
            'context_schema_version' => self::CONTEXT_SCHEMA_VERSION,
            'redaction_policy_version' => self::REDACTION_POLICY_VERSION,
            'occurred_at' => self::databaseTimestamp($input->occurredAt),
            'recorded_at' => self::databaseTimestamp($recordedAt),
            'retention_until' => self::databaseTimestamp($retentionUntil),
            'previous_hash' => $previousHash,
            'event_hash' => $eventHash,
            'integrity_key_version' => $this->activeIntegrityKeyVersion,
        ]);

        $this->outbox->append(
            $input->eventId,
            $input->subjectId ?? $input->eventId,
            $recordedEvent->eventType(),
            $recordedEvent->payload(),
        );

        return new AuditEventReceipt(
            eventId: $input->eventId,
            streamKey: $streamKey,
            streamSequence: $streamSequence,
            eventHash: $eventHash,
            recordedAt: $recordedAt,
            replayed: false,
        );
    }

    /**
     * Canonical request content contains only accepted input, its redacted
     * context, and the two fixed server-side schema/policy versions.
     *
     * @param  array<array-key, mixed>  $context
     */
    private function requestHash(AuditEventInput $input, array $context): string
    {
        return hash('sha256', self::encode(self::canonicalize([
            'id' => $input->eventId,
            'source_module' => $input->sourceModule,
            'action' => $input->action,
            'event_type' => $input->eventType,
            'actor_type' => $input->actorType,
            'actor_id' => $input->actorId,
            'original_actor_id' => $input->originalActorId,
            'subject_type' => $input->subjectType,
            'subject_id' => $input->subjectId,
            'correlation_id' => $input->correlationId,
            'outcome' => $input->outcome,
            'classification' => $input->classification,
            'context' => $context,
            'context_schema_version' => self::CONTEXT_SCHEMA_VERSION,
            'redaction_policy_version' => self::REDACTION_POLICY_VERSION,
            'occurred_at' => self::apiTimestamp($input->occurredAt),
            'retention_class' => $input->retentionClass,
        ])));
    }

    /**
     * Delegates to the shared {@see AuditEventCanonicalizer} so the write path
     * and the verify path consume byte-identical canonical forms. The field
     * ordering, schema/policy versions, and timestamp format all live there.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<string, mixed>
     */
    private function canonicalEvent(
        AuditEventInput $input,
        array $context,
        string $requestHash,
        string $streamKey,
        int $streamSequence,
        DateTimeImmutable $recordedAt,
        DateTimeImmutable $retentionUntil,
    ): array {
        return $this->canonicalizer->canonicalizeForHash(
            $input,
            $context,
            $requestHash,
            $streamKey,
            $streamSequence,
            $recordedAt,
            $retentionUntil,
            $this->activeIntegrityKeyVersion,
        );
    }

    private function streamKey(AuditEventInput $input): string
    {
        return sprintf(
            '%s:%s:%s',
            $input->sourceModule,
            $input->subjectType,
            $input->subjectId ?? 'global',
        );
    }

    private static function now(): DateTimeImmutable
    {
        return now('UTC')->toDateTimeImmutable();
    }

    private static function dateTime(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private static function apiTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function databaseTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** @param array<array-key, mixed> $value */
    private static function encode(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('audit_canonical_json_invalid', 0, $exception);
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = self::canonicalize($nested);
            }
        }

        return $value;
    }

    private static function isRetryableRace(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        if (in_array($sqlState, ['40001', '40P01'], true)
            || in_array($driverCode, [1205, 1213], true)) {
            return true;
        }

        return false;
    }
