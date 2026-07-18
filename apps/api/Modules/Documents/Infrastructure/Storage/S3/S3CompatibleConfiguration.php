<?php

namespace Modules\Documents\Infrastructure\Storage\S3;

use InvalidArgumentException;
use RuntimeException;

/**
 * Reads documents storage configuration strictly from environment variables.
 * The module owns these names so a future config entrypoint can simply alias
 * the same keys. Anything missing outside testing raises so misconfiguration
 * fails closed at boot, never at request time.
 */
final class S3CompatibleConfiguration
{
    private function __construct(
        public readonly string $region,
        public readonly string $endpoint,
        public readonly bool $usePathStyle,
        public readonly string $quarantineBucket,
        public readonly string $availableBucket,
        public readonly string $accessKeyId,
        public readonly string $secretAccessKey,
        public readonly ?string $sessionToken,
        public readonly ?string $quarantineKmsKeyId,
        public readonly ?string $availableKmsKeyId,
        public readonly int $uploadIntentTtlSeconds,
    ) {}

    /**
     * @internal test-only constructor; bypasses the production validation so
     * tests can exercise the adapter without needing real environment
     * variables.
     */
    public static function forTesting(
        string $region,
        string $endpoint,
        bool $usePathStyle,
        string $quarantineBucket,
        string $availableBucket,
        string $accessKeyId,
        string $secretAccessKey,
        ?string $sessionToken = null,
        ?string $quarantineKmsKeyId = null,
        ?string $availableKmsKeyId = null,
        int $uploadIntentTtlSeconds = 300,
    ): self {
        return new self(
            $region,
            $endpoint,
            $usePathStyle,
            $quarantineBucket,
            $availableBucket,
            $accessKeyId,
            $secretAccessKey,
            $sessionToken,
            $quarantineKmsKeyId,
            $availableKmsKeyId,
            $uploadIntentTtlSeconds,
        );
    }

    public static function fromEnvironment(bool $testing = false): self
    {
        $region = self::required('DOCUMENTS_S3_REGION', $testing);
        $endpoint = self::optional('DOCUMENTS_S3_ENDPOINT');
        $usePathStyle = self::boolean('DOCUMENTS_S3_USE_PATH_STYLE', $testing || self::optional('DOCUMENTS_S3_ENDPOINT') !== '');
        $quarantineBucket = self::required('DOCUMENTS_S3_QUARANTINE_BUCKET', $testing);
        $availableBucket = self::required('DOCUMENTS_S3_AVAILABLE_BUCKET', $testing);
        $accessKeyId = self::required('DOCUMENTS_S3_ACCESS_KEY_ID', $testing);
        $secretAccessKey = self::required('DOCUMENTS_S3_SECRET_ACCESS_KEY', $testing);
        $sessionToken = self::optional('DOCUMENTS_S3_SESSION_TOKEN');
        $quarantineKmsKeyId = self::optional('DOCUMENTS_S3_QUARANTINE_KMS_KEY_ID');
        $availableKmsKeyId = self::optional('DOCUMENTS_S3_AVAILABLE_KMS_KEY_ID');
        $ttl = self::integerInRange(
            'DOCUMENTS_UPLOAD_INTENT_TTL_SECONDS',
            60,
            300,
            $testing ? 300 : null,
        );

        if (! $testing
            && strtolower($quarantineBucket) === strtolower($availableBucket)) {
            throw new RuntimeException('Documents S3 quarantine and available buckets must differ.');
        }
        if (! $testing
            && $quarantineKmsKeyId !== ''
            && $availableKmsKeyId !== ''
            && hash_equals($quarantineKmsKeyId, $availableKmsKeyId)) {
            throw new RuntimeException('Documents S3 quarantine and available KMS keys must differ.');
        }

        return new self(
            $region,
            $endpoint,
            $usePathStyle,
            $quarantineBucket,
            $availableBucket,
            $accessKeyId,
            $secretAccessKey,
            $sessionToken,
            $quarantineKmsKeyId,
            $availableKmsKeyId,
            $ttl,
        );
    }

    public function host(): string
    {
        if ($this->endpoint === '') {
            $suffix = $this->region === 'us-east-1' ? '' : '.'.$this->region;

            return 's3'.$suffix.'.amazonaws.com';
        }

        $host = parse_url($this->endpoint, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new RuntimeException('Documents S3 endpoint host is invalid.');
        }

        return $host;
    }

    public function hostWithPort(): string
    {
        if ($this->endpoint === '') {
            return $this->host();
        }
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        if (! is_int($port) || $port === 0) {
            return $this->host();
        }

        return $this->host().':'.$port;
    }

    public function scheme(): string
    {
        if ($this->endpoint === '') {
            return 'https';
        }
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME);

        return is_string($scheme) && $scheme !== '' ? $scheme : 'https';
    }

    private static function required(string $name, bool $testing): string
    {
        $value = self::optional($name);
        if ($value === '') {
            if ($testing) {
                return 'test-'.strtolower($name);
            }
            throw new RuntimeException("Documents S3 configuration requires {$name} outside testing.");
        }

        return $value;
    }

    private static function optional(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (! is_string($value)) {
            return '';
        }

        return trim($value);
    }

    private static function boolean(string $name, bool $default): bool
    {
        $value = self::optional($name);
        if ($value === '') {
            return $default;
        }
        if (in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array(strtolower($value), ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        throw new RuntimeException("Documents S3 boolean {$name} must be one of: 1, 0, true, false.");
    }

    private static function integerInRange(string $name, int $min, int $max, ?int $default): int
    {
        $raw = self::optional($name);
        if ($raw === '') {
            if ($default === null) {
                throw new RuntimeException("Documents S3 integer {$name} is required.");
            }

            return $default;
        }
        if (! ctype_digit($raw)) {
            throw new InvalidArgumentException("Documents S3 integer {$name} must be a non-negative integer.");
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("Documents S3 integer {$name} must be within [{$min}, {$max}].");
        }

        return $value;
    }
}
