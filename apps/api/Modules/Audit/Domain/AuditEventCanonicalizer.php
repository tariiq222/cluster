<?php

declare(strict_types=1);

namespace Modules\Audit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Modules\Audit\Contracts\AuditEventInput;

/**
 * Single source of truth for the Audit event canonical form.
 *
 * Recording ({@see \Modules\Audit\Infrastructure\Persistence\DatabaseRecordAuditEvent})
 * and verification ({@see \Modules\Audit\Infrastructure\Persistence\AuditIntegrityRepository})
 * MUST derive the JSON-compatible canonical event through this service so the
 * per-field ordering, schema/policy versions, and timestamp format cannot drift
 * between producer and verifier. The Hasher consumes the output verbatim.
 *
 * The canonical form is intentionally identical to what already lives in
 * `audit_events.context_schema_version = 1` and `redaction_policy_version = v1`
 * — this class does not change the on-disk format, it only guarantees that
 * both write and read paths compute the exact same byte sequence.
 */
final class AuditEventCanonicalizer
{
    public const CONTEXT_SCHEMA_VERSION = 1;

    public const REDACTION_POLICY_VERSION = 'v1';

    public const FIELDS = [
        'id',
        'request_hash',
        'stream_key',
        'stream_sequence',
        'source_module',
        'action',
        'event_type',
        'actor_type',
        'actor_id',
        'original_actor_id',
        'subject_type',
        'subject_id',
        'correlation_id',
        'outcome',
        'classification',
        'context',
        'context_schema_version',
        'redaction_policy_version',
        'occurred_at',
        'recorded_at',
        'retention_until',
        'integrity_key_version',
    ];

    /**
     * Build the canonical hash input for a freshly recorded event.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<string, mixed>
     */
    public function canonicalizeForHash(
        AuditEventInput $input,
        array $context,
        string $requestHash,
        string $streamKey,
        int $streamSequence,
        DateTimeImmutable $recordedAt,
        DateTimeImmutable $retentionUntil,
        string $activeIntegrityKeyVersion,
    ): array {
        $this->assertKeyVersion($activeIntegrityKeyVersion);

        return [
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
            'context' => $this->canonicalizeContext($context),
            'context_schema_version' => self::CONTEXT_SCHEMA_VERSION,
            'redaction_policy_version' => self::REDACTION_POLICY_VERSION,
            'occurred_at' => $this->apiTimestamp($input->occurredAt),
            'recorded_at' => $this->apiTimestamp($recordedAt),
            'retention_until' => $this->apiTimestamp($retentionUntil),
            'integrity_key_version' => $activeIntegrityKeyVersion,
        ];
    }

    /**
     * Build the canonical hash input from a persisted `audit_events` row.
     *
     * Used by verification, retention purge, and any future replay path. The
     * returned array matches {@see canonicalizeForHash()} byte-for-byte for
     * the same logical event; timestamps are normalized to the canonical
     * UTC-Z millisecond format and the context JSON is decoded then
     * re-canonicalized so key ordering matches what the recorder emitted.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function canonicalizeFromRow(array $row): array
    {
        foreach (['id', 'request_hash', 'stream_key', 'stream_sequence', 'source_module', 'action', 'event_type', 'actor_type', 'correlation_id', 'outcome', 'classification', 'context_schema_version', 'redaction_policy_version', 'occurred_at', 'recorded_at', 'retention_until', 'integrity_key_version', 'event_hash'] as $required) {
            if (! array_key_exists($required, $row)) {
                throw new InvalidArgumentException("audit_canonical_row_missing_{$required}");
            }
        }

        $context = $this->decodeContext($row['context']);

        return [
            'id' => (string) $row['id'],
            'request_hash' => (string) $row['request_hash'],
            'stream_key' => (string) $row['stream_key'],
            'stream_sequence' => (int) $row['stream_sequence'],
            'source_module' => (string) $row['source_module'],
            'action' => (string) $row['action'],
            'event_type' => (string) $row['event_type'],
            'actor_type' => (string) $row['actor_type'],
            'actor_id' => $row['actor_id'] ?? null,
            'original_actor_id' => $row['original_actor_id'] ?? null,
            'subject_type' => (string) $row['subject_type'],
            'subject_id' => $row['subject_id'] ?? null,
            'correlation_id' => (string) $row['correlation_id'],
            'outcome' => (string) $row['outcome'],
            'classification' => (string) $row['classification'],
            'context' => $context,
            'context_schema_version' => (int) $row['context_schema_version'],
            'redaction_policy_version' => (string) $row['redaction_policy_version'],
            'occurred_at' => $this->apiTimestamp($this->asDateTimeImmutable($row['occurred_at'], 'occurred_at')),
            'recorded_at' => $this->apiTimestamp($this->asDateTimeImmutable($row['recorded_at'], 'recorded_at')),
            'retention_until' => $this->apiTimestamp($this->asDateTimeImmutable($row['retention_until'], 'retention_until')),
            'integrity_key_version' => (string) $row['integrity_key_version'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public function canonicalizeContext(array $context): array
    {
        return $this->canonicalizeValue($context);
    }

    /**
     * Decode the JSON `context` column back into a sorted-key canonical array.
     *
     * @return array<array-key, mixed>
     */
    public function decodeContext(mixed $context): array
    {
        if ($context === null) {
            return [];
        }

        if (is_array($context)) {
            return $this->canonicalizeValue($context);
        }

        $raw = is_string($context) ? $context : (string) $context;
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('audit_canonical_context_invalid', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('audit_canonical_context_invalid');
        }

        return $this->canonicalizeValue($decoded);
    }

    public function apiTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function canonicalizeValue(array $value): array
    {
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $nested) {
                $out[] = is_array($nested) ? $this->canonicalizeValue($nested) : $this->scalarize($nested);
            }

            return $out;
        }

        ksort($value, SORT_STRING);
        $out = [];
        foreach ($value as $key => $nested) {
            if (! is_string($key) || $key === '' || str_contains($key, "\0")) {
                throw new InvalidArgumentException('audit_canonical_key_invalid');
            }
            $out[$key] = is_array($nested) ? $this->canonicalizeValue($nested) : $this->scalarize($nested);
        }

        return $out;
    }

    private function scalarize(mixed $value): mixed
    {
        if (is_string($value) || is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }

        throw new InvalidArgumentException('audit_canonical_value_unsupported');
    }

    private function assertKeyVersion(string $version): void
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,31}\z/', $version) !== 1) {
            throw new InvalidArgumentException('audit_canonical_key_version_invalid');
        }
    }

    private function asDateTimeImmutable(mixed $value, string $field): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value === null || $value === '') {
            throw new InvalidArgumentException("audit_canonical_{$field}_missing");
        }

        $string = (string) $value;
        $formats = [
            'Y-m-d H:i:s.v',
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:s.v\Z',
            'Y-m-d\TH:i:s\Z',
        ];
        foreach ($formats as $format) {
            $candidate = DateTimeImmutable::createFromFormat('!'.$format, $string, new DateTimeZone('UTC'));
            if ($candidate instanceof DateTimeImmutable) {
                return $candidate;
            }
        }

        try {
            return new DateTimeImmutable($string, new DateTimeZone('UTC'));
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException("audit_canonical_{$field}_invalid", 0, $exception);
        }
    }
}
