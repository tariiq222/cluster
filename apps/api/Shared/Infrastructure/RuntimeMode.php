<?php

declare(strict_types=1);

namespace Shared\Infrastructure;

/**
 * The runtime mode of the API process. It replaces the previous
 * $_SERVER['argv'] parsing and serves two purposes:
 *
 *  1. Tell feature gates whether they may engage the real backend (S3,
 *     ClamAV, identity dev fixtures, ...).
 *  2. Replace the env() + argv() combination with one explicit config key
 *     so production deployment cannot accidentally be detected as testing.
 */
enum RuntimeMode: string
{
    case Production = 'production';
    case Testing = 'testing';
    case Local = 'local';

    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    public function isTesting(): bool
    {
        return $this === self::Testing;
    }

    public function isLocal(): bool
    {
        return $this === self::Local;
    }

    /**
     * Resolve the runtime mode from configuration.
     *
     * Resolution order:
     *   1. `app.runtime_mode` (env: APP_RUNTIME_MODE). Explicit override wins.
     *   2. `app.env` (env: APP_ENV). When APP_RUNTIME_MODE is not set, derive
     *      from the standard Laravel environment: `production` -> production,
     *      `testing` -> testing, anything else -> local.
     */
    public static function fromConfig(?string $configured, ?string $fallbackEnv): self
    {
        $configured = is_string($configured) ? trim($configured) : '';
        $fallback = is_string($fallbackEnv) ? trim($fallbackEnv) : '';
        $candidate = $configured !== '' ? $configured : $fallback;

        return match (strtolower($candidate)) {
            'production', 'prod' => self::Production,
            'testing', 'test' => self::Testing,
            default => self::Local,
        };
    }
}
