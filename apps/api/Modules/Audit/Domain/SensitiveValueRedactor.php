<?php

declare(strict_types=1);

namespace Modules\Audit\Domain;

use InvalidArgumentException;
use Modules\Audit\Contracts\AuditEventInput;

final class SensitiveValueRedactor
{
    public const REDACTED = '[REDACTED]';

    private const REDACTED_JWT = '[JWT_REDACTED]';

    private const REDACTED_NATIONAL_ID = '[NATIONAL_ID_REDACTED]';

    /** @var list<string> */
    private const SENSITIVE_KEY_SEGMENTS = [
        'password',
        'token',
        'authorization',
        'cookie',
        'secret',
        'csrf',
        'credential',
        'medical_record_number',
        'national_id',
        'document_content',
    ];

    private const BEARER_PATTERN = '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i';

    private const JWT_PATTERN = '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/';

    private const NATIONAL_ID_PATTERN = '/(?<!\d)\d{10}(?!\d)/';

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public function redact(array $context): array
    {
        AuditEventInput::assertContext($context);

        $redacted = $this->redactArray($context);
        AuditEventInput::assertContext($redacted);

        return $redacted;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function redactArray(array $value): array
    {
        foreach ($value as $key => $nested) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $value[$key] = self::REDACTED;

                continue;
            }

            if (is_array($nested)) {
                $value[$key] = $this->redactArray($nested);

                continue;
            }

            if (is_string($nested)) {
                $value[$key] = $this->redactString($nested);
            }
        }

        return $value;
    }

    /**
     * A key is sensitive when ANY of its normalized segments (split on
     * `_`, `-`, `.`, and camelCase boundaries) is a substring of, equal
     * to, or contained in a sensitive list entry. The pattern is:
     *
     *   1. lowercase the key,
     *   2. insert a separator at every camelCase boundary
     *      (`aB` → `a_b`, `AB` → `a_b`),
     *   3. replace every existing `_`, `-`, `.` with a single canonical
     *      separator,
     *   4. split on the canonical separator into non-empty segments,
     *   5. for each segment, lowercase-compare against every sensitive
     *      list entry; the segment is sensitive when it strictly equals
     *      the entry or is contained inside the entry (catches
     *      `MedicalRecordNumber` → `medical` + `record` + `number` where
     *      `medical_record_number` matches the `number` entry).
     *
     * Examples (all marked sensitive):
     *   - `old_password_hash` (split → `old`, `password`, `hash`)
     *   - `access_token_value` (split → `access`, `token`, `value`)
     *   - `csrfToken` (split → `csrf`, `token`)
     *   - `headers.Authorization` (split → `headers`, `authorization`)
     *   - `XSRF-TOKEN` (split → `xsrf`, `token`)
     *   - `medical_record_number` (split → `medical`, `record`, `number`
     *     → entry `medical_record_number` matches `number` containment)
     */
    private function isSensitiveKey(string $key): bool
    {
        $segments = self::keySegments($key);
        if ($segments === []) {
            return false;
        }

        foreach ($segments as $segment) {
            foreach (self::SENSITIVE_KEY_SEGMENTS as $entry) {
                if ($segment === $entry
                    || str_contains($entry, $segment)
                    || str_contains($segment, $entry)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Split a key into lowercase segments on `_`, `-`, `.`, and camelCase
     * boundaries. Returns an empty list when the key contains no segments
     * (e.g. only separators or empty).
     *
     * @return list<string>
     */
    private static function keySegments(string $key): array
    {
        $lowered = strtolower($key);
        $separated = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $lowered);
        $separated = (string) preg_replace('/([A-Z])([A-Z][a-z])/', '$1_$2', $separated);
        $normalized = (string) preg_replace('/[._\-]+/', '_', $separated);

        $segments = [];
        foreach (explode('_', $normalized) as $segment) {
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    private function redactString(string $value): string
    {
        $value = $this->replace(self::JWT_PATTERN, self::REDACTED_JWT, $value);
        $value = $this->replace(self::BEARER_PATTERN, 'Bearer '.self::REDACTED, $value);

        return $this->replace(self::NATIONAL_ID_PATTERN, self::REDACTED_NATIONAL_ID, $value);
    }

    private function replace(string $pattern, string $replacement, string $value): string
    {
        $redacted = preg_replace($pattern, $replacement, $value);
        if ($redacted === null) {
            throw new InvalidArgumentException('audit_redaction_failed');
        }

        return $redacted;
    }
}
