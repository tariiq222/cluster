<?php

namespace Modules\Documents\Infrastructure\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Reads the ClamAV connection configuration from environment variables. The
 * module owns these keys; the future config entrypoint can simply alias them.
 * Both the TCP and unix-socket shapes are supported so dev sandboxes and the
 * production VPS share the same configuration contract.
 */
final class ClamAvConfiguration
{
    private function __construct(
        public readonly string $transport,
        public readonly string $host,
        public readonly int $port,
        public readonly ?string $unixSocket,
        public readonly float $connectTimeoutSeconds,
        public readonly float $readTimeoutSeconds,
        public readonly int $chunkBytes,
        public readonly string $engineName,
        public readonly string $signatureVersion,
    ) {}

    public static function fromEnvironment(bool $testing = false): self
    {
        $transport = self::optional('DOCUMENTS_CLAMAV_TRANSPORT', $testing ? 'disabled' : '');
        if (! in_array($transport, ['tcp', 'unix', 'disabled'], true)) {
            throw new RuntimeException('Documents ClamAV transport must be one of: tcp, unix, disabled.');
        }
        $host = self::optional('DOCUMENTS_CLAMAV_HOST', $testing ? '127.0.0.1' : '');
        $port = self::port('DOCUMENTS_CLAMAV_PORT', 3310);
        $unixSocket = self::optional('DOCUMENTS_CLAMAV_SOCKET');
        $connectTimeout = self::floatInRange('DOCUMENTS_CLAMAV_CONNECT_TIMEOUT', 0.1, 30.0, 5.0);
        $readTimeout = self::floatInRange('DOCUMENTS_CLAMAV_READ_TIMEOUT', 1.0, 600.0, 60.0);
        $chunkBytes = self::integerInRange('DOCUMENTS_CLAMAV_CHUNK_BYTES', 1024, 1_048_576, 65536);
        $engineName = self::optional('DOCUMENTS_CLAMAV_ENGINE_NAME', $testing ? 'clamav-test' : '');
        $signatureVersion = self::optional('DOCUMENTS_CLAMAV_SIGNATURE_VERSION', $testing ? 'test-sigs-v1' : '');

        if (! $testing && $transport === 'tcp' && $host === '') {
            throw new RuntimeException('Documents ClamAV TCP transport requires DOCUMENTS_CLAMAV_HOST.');
        }
        if (! $testing && $transport === 'unix' && $unixSocket === '') {
            throw new RuntimeException('Documents ClamAV unix transport requires DOCUMENTS_CLAMAV_SOCKET.');
        }
        if (! $testing && $transport !== 'disabled' && ($engineName === '' || $signatureVersion === '')) {
            throw new RuntimeException('Documents ClamAV requires DOCUMENTS_CLAMAV_ENGINE_NAME and DOCUMENTS_CLAMAV_SIGNATURE_VERSION.');
        }

        return new self($transport, $host, $port, $unixSocket, $connectTimeout, $readTimeout, $chunkBytes, $engineName, $signatureVersion);
    }

    private static function optional(string $name, string $default = ''): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (! is_string($value)) {
            return $default;
        }

        return trim($value);
    }

    private static function port(string $name, int $default): int
    {
        $raw = self::optional($name);
        if ($raw === '') {
            return $default;
        }
        if (! ctype_digit($raw)) {
            throw new InvalidArgumentException("Documents ClamAV integer {$name} must be numeric.");
        }
        $port = (int) $raw;
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Documents ClamAV integer {$name} must be within [1, 65535].");
        }

        return $port;
    }

    private static function integerInRange(string $name, int $min, int $max, int $default): int
    {
        $raw = self::optional($name);
        if ($raw === '') {
            return $default;
        }
        if (! ctype_digit($raw)) {
            throw new InvalidArgumentException("Documents ClamAV integer {$name} must be a non-negative integer.");
        }
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("Documents ClamAV integer {$name} must be within [{$min}, {$max}].");
        }

        return $value;
    }

    private static function floatInRange(string $name, float $min, float $max, float $default): float
    {
        $raw = self::optional($name);
        if ($raw === '') {
            return $default;
        }
        if (! is_numeric($raw)) {
            throw new InvalidArgumentException("Documents ClamAV float {$name} must be numeric.");
        }
        $value = (float) $raw;
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException("Documents ClamAV float {$name} must be within [{$min}, {$max}].");
        }

        return $value;
    }
}
