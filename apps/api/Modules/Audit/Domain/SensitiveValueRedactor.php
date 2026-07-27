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

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::SENSITIVE_KEY_SEGMENTS as $segment) {
            if ($normalized === $segment
                || str_starts_with($normalized, $segment.'_')
                || str_ends_with($normalized, '_'.$segment)) {
                return true;
            }
        }

        return false;
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
