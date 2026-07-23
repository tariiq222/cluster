<?php

namespace Modules\PlatformSettings\Domain;

use InvalidArgumentException;

final readonly class SecurityPolicy
{
    public const MIN_IDLE_TIMEOUT_MINUTES = 5;

    public const MAX_IDLE_TIMEOUT_MINUTES = 60;

    public const MIN_ABSOLUTE_SESSION_HOURS = 1;

    public const MAX_ABSOLUTE_SESSION_HOURS = 24;

    public const MINIMUM_PASSWORD_LENGTH = 8;

    public const MAXIMUM_PASSWORD_LENGTH = 128;

    public const MIN_PASSWORD_HISTORY_COUNT = 0;

    public const MAX_PASSWORD_HISTORY_COUNT = 24;

    public const MIN_FAILED_LOGIN_ATTEMPTS = 3;

    public const MAX_FAILED_LOGIN_ATTEMPTS = 10;

    public const MIN_FAILED_LOGIN_WINDOW_MINUTES = 1;

    public const MAX_FAILED_LOGIN_WINDOW_MINUTES = 60;

    public const MIN_LOCKOUT_MINUTES = 5;

    public const MAX_LOCKOUT_MINUTES = 1440;

    private function __construct(
        public int $idleTimeoutMinutes,
        public int $absoluteSessionHours,
        public int $minimumPasswordLength,
        public int $passwordHistoryCount,
        public int $failedLoginAttempts,
        public int $failedLoginWindowMinutes,
        public int $lockoutMinutes,
    ) {}

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self(
            self::bounded($values, 'idle_timeout_minutes', self::MIN_IDLE_TIMEOUT_MINUTES, self::MAX_IDLE_TIMEOUT_MINUTES),
            self::bounded($values, 'absolute_session_hours', self::MIN_ABSOLUTE_SESSION_HOURS, self::MAX_ABSOLUTE_SESSION_HOURS),
            self::bounded($values, 'minimum_password_length', self::MINIMUM_PASSWORD_LENGTH, self::MAXIMUM_PASSWORD_LENGTH),
            self::bounded($values, 'password_history_count', self::MIN_PASSWORD_HISTORY_COUNT, self::MAX_PASSWORD_HISTORY_COUNT),
            self::bounded($values, 'failed_login_attempts', self::MIN_FAILED_LOGIN_ATTEMPTS, self::MAX_FAILED_LOGIN_ATTEMPTS),
            self::bounded($values, 'failed_login_window_minutes', self::MIN_FAILED_LOGIN_WINDOW_MINUTES, self::MAX_FAILED_LOGIN_WINDOW_MINUTES),
            self::bounded($values, 'lockout_minutes', self::MIN_LOCKOUT_MINUTES, self::MAX_LOCKOUT_MINUTES),
        );
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'idle_timeout_minutes' => $this->idleTimeoutMinutes,
            'absolute_session_hours' => $this->absoluteSessionHours,
            'minimum_password_length' => $this->minimumPasswordLength,
            'password_history_count' => $this->passwordHistoryCount,
            'failed_login_attempts' => $this->failedLoginAttempts,
            'failed_login_window_minutes' => $this->failedLoginWindowMinutes,
            'lockout_minutes' => $this->lockoutMinutes,
        ];
    }

    /** @param array<string, mixed> $values */
    private static function bounded(array $values, string $key, int $minimum, int $maximum): int
    {
        $value = $values[$key] ?? null;
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("{$key} must be an integer between {$minimum} and {$maximum}.");
        }

        return $value;
    }
}
