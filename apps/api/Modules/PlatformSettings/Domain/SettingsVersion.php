<?php

namespace Modules\PlatformSettings\Domain;

use InvalidArgumentException;
use LogicException;

final readonly class SettingsVersion
{
    public const TIMEZONE = 'Asia/Riyadh';

    /** @param array<string, int> $security */
    public function __construct(
        public string $id,
        public string $status,
        public string $defaultLocale,
        public int $lockVersion,
        public array $security,
        public string $timezone = self::TIMEZONE,
        public int $activeLogMonths = 90,
    ) {
        if (! in_array($status, ['draft', 'validated', 'published', 'retired'], true)) {
            throw new InvalidArgumentException('Unsupported settings version status.');
        }
        if (! in_array($defaultLocale, ['ar', 'en'], true)) {
            throw new InvalidArgumentException('Default locale must be ar or en.');
        }
        if ($timezone !== self::TIMEZONE) {
            throw new InvalidArgumentException('Timezone is fixed to Asia/Riyadh.');
        }
        if ($activeLogMonths < 1 || $activeLogMonths > 120) {
            throw new InvalidArgumentException('Active log months must be between 1 and 120.');
        }
    }

    /** @param array<string, mixed> $document */
    public static function fromDocument(string $id, string $status, int $lockVersion, array $document): self
    {
        $localization = $document['localization'] ?? [];
        $security = $document['security'] ?? [];
        $operations = $document['operations'] ?? [];

        if (! is_array($localization) || ! is_array($security) || ! is_array($operations)) {
            throw new InvalidArgumentException('Settings document has an invalid shape.');
        }

        return new self(
            $id,
            $status,
            $localization['default_locale'] ?? '',
            $lockVersion,
            $security,
            self::TIMEZONE,
            $operations['active_log_months'] ?? 90,
        );
    }

    public static function defaults(string $id): self
    {
        return new self($id, 'draft', 'ar', 1, [
            'idle_timeout_minutes' => 30,
            'absolute_session_hours' => 8,
            'minimum_password_length' => 14,
            'password_history_count' => 5,
            'failed_login_attempts' => 4,
            'failed_login_window_minutes' => 1,
            'lockout_minutes' => 30,
        ]);
    }

    public function withValue(string $key, mixed $value): self
    {
        if ($this->status === 'published' || $this->status === 'retired') {
            throw new LogicException('Published or retired settings versions cannot be modified.');
        }

        $settingKey = SettingKey::tryFrom($key);
        if ($settingKey === null) {
            throw new InvalidArgumentException('Setting key is not allowed.');
        }

        $locale = $this->defaultLocale;
        $security = $this->security;
        $activeLogMonths = $this->activeLogMonths;
        if ($settingKey === SettingKey::DefaultLocale) {
            if (! is_string($value)) {
                throw new InvalidArgumentException('Default locale must be a string.');
            }
            $locale = $value;
        } elseif ($settingKey === SettingKey::ActiveLogMonths) {
            if (! is_int($value)) {
                throw new InvalidArgumentException('Active log months must be an integer.');
            }
            $activeLogMonths = $value;
        } else {
            if (! is_int($value)) {
                throw new InvalidArgumentException('Security settings must be integers.');
            }
            $security[$settingKey->name()] = $value;
        }

        return new self($this->id, $this->status, $locale, $this->lockVersion, $security, self::TIMEZONE, $activeLogMonths);
    }

    public function validate(): self
    {
        if ($this->status !== 'draft' && $this->status !== 'validated') {
            throw new LogicException('Only a draft can be validated.');
        }
        SecurityPolicy::fromArray($this->security);

        return new self($this->id, 'validated', $this->defaultLocale, $this->lockVersion, $this->security, $this->timezone, $this->activeLogMonths);
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return [
            'localization' => ['default_locale' => $this->defaultLocale],
            'security' => $this->security,
            'operations' => ['active_log_months' => $this->activeLogMonths],
        ];
    }

    public function contentHash(): string
    {
        return hash('sha256', json_encode(self::canonicalize($this->document()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function canonicalize(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }
}
