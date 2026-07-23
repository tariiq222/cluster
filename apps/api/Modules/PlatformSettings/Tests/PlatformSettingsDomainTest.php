<?php

namespace Modules\PlatformSettings\Tests;

use InvalidArgumentException;
use Modules\PlatformSettings\Domain\SecurityPolicy;
use Modules\PlatformSettings\Domain\SettingsVersion;
use Tests\TestCase;

final class PlatformSettingsDomainTest extends TestCase
{
    public function test_security_policy_accepts_the_supported_safe_values(): void
    {
        $policy = SecurityPolicy::fromArray([
            'idle_timeout_minutes' => 30,
            'absolute_session_hours' => 12,
            'minimum_password_length' => 12,
            'password_history_count' => 5,
            'failed_login_attempts' => 5,
            'failed_login_window_minutes' => 15,
            'lockout_minutes' => 30,
        ]);

        $this->assertSame(12, $policy->minimumPasswordLength);
        $this->assertSame(30, $policy->toArray()['idle_timeout_minutes']);
    }

    public function test_security_policy_rejects_password_lengths_below_the_safe_minimum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SecurityPolicy::fromArray($this->security(['minimum_password_length' => 7]));
    }

    public function test_settings_version_rejects_an_unsupported_default_locale(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettingsVersion('version-1', 'draft', 'fr', 1, $this->security());
    }

    public function test_settings_version_rejects_a_timezone_other_than_riyadh(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SettingsVersion('version-1', 'draft', 'ar', 1, $this->security(), 'Europe/Paris');
    }

    public function test_published_settings_version_cannot_be_modified(): void
    {
        $version = new SettingsVersion('version-1', 'published', 'ar', 1, $this->security());

        $this->expectException(\LogicException::class);
        $version->withValue('security.minimum_password_length', 14);
    }

    /** @param array<string, int> $overrides
     * @return array<string, int>
     */
    private function security(array $overrides = []): array
    {
        return array_replace([
            'idle_timeout_minutes' => 30,
            'absolute_session_hours' => 12,
            'minimum_password_length' => 12,
            'password_history_count' => 5,
            'failed_login_attempts' => 5,
            'failed_login_window_minutes' => 15,
            'lockout_minutes' => 30,
        ], $overrides);
    }
}
