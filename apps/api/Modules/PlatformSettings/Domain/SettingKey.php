<?php

namespace Modules\PlatformSettings\Domain;

enum SettingKey: string
{
    case DefaultLocale = 'localization.default_locale';
    case IdleTimeoutMinutes = 'security.idle_timeout_minutes';
    case AbsoluteSessionHours = 'security.absolute_session_hours';
    case MinimumPasswordLength = 'security.minimum_password_length';
    case PasswordHistoryCount = 'security.password_history_count';
    case FailedLoginAttempts = 'security.failed_login_attempts';
    case FailedLoginWindowMinutes = 'security.failed_login_window_minutes';
    case LockoutMinutes = 'security.lockout_minutes';
    case ActiveLogMonths = 'operations.active_log_months';

    public function section(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    public function name(): string
    {
        return explode('.', $this->value, 2)[1];
    }
}
