<?php

declare(strict_types=1);

namespace Modules\Audit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

/**
 * Read-time projection + serialization of audit_events rows for export
 * downloads.
 *
 * Responsibilities:
 *  - Normalize a raw audit_events row into the Section 8 column set.
 *  - Apply the canonical {@see SensitiveValueRedactor} *every* time the
 *    row is emitted, regardless of any persisted redaction policy. The
 *    download contract from M01 Task 5 says the projection must not
 *    trust the on-disk context.
 *  - Serialize the projected row to a single CSV line (with formula
 *    escaping for `=`, `+`, `-`, `@` cells) or a single NDJSON line.
 *
 * Bytes are deterministic for a given input row, but the projection
 * does not cache or persist bytes anywhere.
 */
final class AuditExportProjection
{
    public const FORMAT_CSV = 'csv';

    public const FORMAT_NDJSON = 'ndjson';

    /** @var list<string> */
    private const FORMULA_LEAD = ['=', '+', '-', '@'];

    public function __construct(
        private readonly SensitiveValueRedactor $redactor,
    ) {}

    /**
     * Project a raw audit_events row to the Section 8 columns, applying
     * read-time redaction regardless of how the row was stored.
     *
     * @param  object  $row  raw audit_events row (must expose every
     *                       Section 8 column plus `context`)
     * @return array<string, string|int|null>
     */
    public function project(object $row): array
    {
        $context = $this->decodeContext($row->context ?? null);
        $redactedContext = $context === null
            ? ['__invalid_context' => '[REDACTED]']
            : $this->redactor->redact($context);

        return [
            'event_id' => (string) $row->id,
            'occurred_at' => $this->apiTimestamp((string) $row->occurred_at),
            'recorded_at' => $this->apiTimestamp((string) $row->recorded_at),
            'source_module' => (string) $row->source_module,
            'action' => (string) $row->action,
            'event_type' => (string) $row->event_type,
            'actor_type' => (string) $row->actor_type,
            'actor_id' => $row->actor_id === null ? null : (string) $row->actor_id,
            'subject_type' => (string) $row->subject_type,
            'subject_id' => $row->subject_id === null ? null : (string) $row->subject_id,
            'correlation_id' => (string) $row->correlation_id,
            'outcome' => (string) $row->outcome,
            'classification' => (string) $row->classification,
            'retention_until' => $this->apiTimestamp((string) $row->retention_until),
            'context' => $this->canonicalContextJson($redactedContext),
        ];
    }

    public function toCsvLine(array $row): string
    {
        $cells = [];
        foreach (AuditExportSection8Columns::COLUMNS as $column) {
            $cells[] = $this->csvEscape((string) $row[$column]);
        }

        return implode(',', $cells)."\r\n";
    }

    public function csvHeader(): string
    {
        return implode(',', AuditExportSection8Columns::COLUMNS)."\r\n";
    }

    public function toNdjsonLine(array $row): string
    {
        $payload = [];
        foreach (AuditExportSection8Columns::COLUMNS as $column) {
            $payload[$column] = $row[$column];
        }

        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
    }

    private function csvEscape(string $value): string
    {
        if ($value !== '' && in_array($value[0], self::FORMULA_LEAD, true)) {
            $value = "'".$value;
        }
        // Tab (\t) and carriage return (\r) are also spreadsheet
        // executable in Excel; quote-prefix them so a CSV cell that
        // opens with tab or CR cannot be reinterpreted as a formula.
        if ($value !== '' && ($value[0] === "\t" || $value[0] === "\r")) {
            $value = "'".$value;
        }

        $needsQuoting = str_contains($value, ',')
            || str_contains($value, '"')
            || str_contains($value, "\n")
            || str_contains($value, "\r")
            || str_contains($value, "\t");
        if ($needsQuoting) {
            $value = '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private function decodeContext(mixed $stored): ?array
    {
        if (is_array($stored)) {
            return $stored;
        }
        if (! is_string($stored)) {
            return null;
        }
        try {
            $decoded = json_decode($stored, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function canonicalContextJson(array $context): string
    {
        $canonical = $this->canonicalize($context);
        try {
            return json_encode(
                $canonical,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            return json_encode(
                ['__invalid_context' => SensitiveValueRedactor::REDACTED],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->canonicalize($nested);
            }
        }

        return $value;
    }

    private function apiTimestamp(string $value): string
    {
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new InvalidArgumentException('audit_export_timestamp_invalid');
        }

        $canonical = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        // Round-trip the canonical format to confirm it is millisecond-precision.
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $canonical, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s.v\Z') !== $canonical) {
            throw new InvalidArgumentException('audit_export_timestamp_invalid');
        }

        return $canonical;
    }
}
