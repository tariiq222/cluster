<?php

declare(strict_types=1);

namespace Modules\Audit\Domain;

use InvalidArgumentException;
use JsonException;

final readonly class AuditIntegrityHasher
{
    /**
     * @param  array<string, string>  $keys  map(key version => HMAC key material)
     */
    public function __construct(private array $keys)
    {
        if ($keys === []) {
            throw new InvalidArgumentException('audit_integrity_keys_required');
        }

        foreach ($keys as $version => $key) {
            if (preg_match('/\A[a-z][a-z0-9_.-]{0,31}\z/', $version) !== 1
                || strlen($key) < 32) {
                throw new InvalidArgumentException('audit_integrity_key_invalid');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $canonicalEvent
     */
    public function eventHash(array $canonicalEvent, ?string $previousHash, string $keyVersion): string
    {
        $key = $this->keys[$keyVersion] ?? null;
        if ($key === null) {
            throw new InvalidArgumentException('audit_integrity_key_version_unavailable');
        }
        self::assertPreviousHash($previousHash);

        $canonical = self::canonicalize($canonicalEvent);
        try {
            $macInput = json_encode(
                [
                    'event' => $canonical,
                    'previous_hash' => $previousHash,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('audit_integrity_event_invalid', 0, $exception);
        }

        return hash_hmac('sha256', $macInput, $key);
    }

    /**
     * @param  array<string, mixed>  $canonicalEvent
     */
    public function verify(
        array $canonicalEvent,
        ?string $previousHash,
        string $keyVersion,
        string $expectedHash,
    ): bool {
        if (preg_match('/\A[0-9a-f]{64}\z/', $expectedHash) !== 1) {
            return false;
        }

        return hash_equals(
            $expectedHash,
            $this->eventHash($canonicalEvent, $previousHash, $keyVersion),
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            foreach ($value as $index => $nested) {
                $value[$index] = self::canonicalizeValue($nested);
            }

            return $value;
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $nested) {
            if (! is_string($key) || $key === '' || str_contains($key, "\0")) {
                throw new InvalidArgumentException('audit_integrity_event_key_invalid');
            }
            $value[$key] = self::canonicalizeValue($nested);
        }

        return $value;
    }

    private static function canonicalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::canonicalize($value);
        }
        if (is_string($value) || is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }

        throw new InvalidArgumentException('audit_integrity_event_value_unsupported');
    }

    private static function assertPreviousHash(?string $previousHash): void
    {
        if ($previousHash !== null && preg_match('/\A[0-9a-f]{64}\z/', $previousHash) !== 1) {
            throw new InvalidArgumentException('audit_previous_hash_invalid');
        }
    }
}
