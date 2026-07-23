<?php

namespace Modules\PlatformSettings\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TechnicalLogEntry
{
    /** @var array<string, mixed> */
    public array $context;

    /** @param array<string, mixed> $context */
    public function __construct(
        public string $id,
        public string $source,
        public string $category,
        public DateTimeImmutable $occurredAt,
        public string $correlationId,
        array $context,
    ) {
        if ($id === '' || $source === '' || $correlationId === '') {
            throw new InvalidArgumentException('Technical log identity is required.');
        }
        if (! in_array($category, ['audit', 'security', 'system', 'operations'], true)) {
            throw new InvalidArgumentException('Technical log category is invalid.');
        }

        $this->context = self::redact($context);
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                $context[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            }
        }

        return $context;
    }

    private static function isSensitiveKey(string $key): bool
    {
        return in_array(strtolower($key), [
            'password',
            'token',
            'authorization',
            'cookie',
            'document_content',
            'national_id',
        ], true);
    }
}
