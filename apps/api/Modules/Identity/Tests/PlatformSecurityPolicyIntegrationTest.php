<?php

namespace Modules\Identity\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Domain\PasswordPolicy;
use Modules\Identity\Features\Sessions\Contracts\TrustedRequestBindingContext;
use Modules\Identity\Features\Sessions\Handler\SessionHandler;
use Modules\Identity\Infrastructure\Security\PersistentPreAuthThrottle;
use Modules\PlatformSettings\Contracts\GetEffectivePlatformSettings;
use Tests\TestCase;

final class PlatformSecurityPolicyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_without_a_published_version_keeps_identity_security_floors(): void
    {
        $security = $this->app->make(GetEffectivePlatformSettings::class)->current()['security'];

        $this->assertSame(14, $security['minimum_password_length']);
        $this->assertSame(8, $security['absolute_session_hours']);
        $this->assertSame(4, $security['failed_login_attempts']);
        $this->assertSame(1, $security['failed_login_window_minutes']);
        $this->assertContains('min_length', $this->app->make(PasswordPolicy::class)->violations('ValidPass123'));

        $userId = $this->activeUser();
        $session = $this->app->make(SessionHandler::class)->issue($userId, $this->bindingMetadata());
        $this->assertLessThanOrEqual(480, now()->diffInMinutes($session->expiresAt, false));

        $throttle = $this->app->make(PersistentPreAuthThrottle::class);
        foreach (range(1, 4) as $_) {
            $this->assertTrue($throttle->attempt('bootstrap-source', 'bootstrap.user')->allowed);
        }
        $this->assertFalse($throttle->attempt('bootstrap-source', 'bootstrap.user')->allowed);
    }

    public function test_published_security_policy_controls_passwords_sessions_and_pre_auth_locks(): void
    {
        $settings = $this->bindPublishedSecurity([
            'minimum_password_length' => 14,
            'idle_timeout_minutes' => 5,
            'absolute_session_hours' => 1,
            'failed_login_attempts' => 3,
            'failed_login_window_minutes' => 1,
            'lockout_minutes' => 5,
        ]);
        $this->assertContains('min_length', $this->app->make(PasswordPolicy::class)->violations('ValidPass123'));
        $this->assertSame([], $this->app->make(PasswordPolicy::class)->violations('Longer Valid Pass 123!'));

        $userId = $this->activeUser();
        $session = $this->app->make(SessionHandler::class)->issue($userId, $this->bindingMetadata());
        DB::table('identity_sessions')->where('id', $session->sessionId)->update(['issued_at' => now()->subMinutes(6)]);
        $this->assertNull($this->app->make(SessionHandler::class)->resolve((string) $session->cookie->getValue(), $this->bindingContext()));

        $throttle = $this->app->make(PersistentPreAuthThrottle::class);
        $this->assertTrue($throttle->attempt('policy-source', 'policy.user')->allowed);
        $this->assertTrue($throttle->attempt('policy-source', 'policy.user')->allowed);
        DB::table('identity_auth_attempt_ledgers')
            ->where('scope_hash', hash('sha256', "policy-source\0policy.user"))
            ->update(['window_started_at' => now()->subSeconds(61)]);
        DB::table('identity_auth_attempt_ledgers')
            ->where('scope_hash', hash('sha256', 'policy.user'))
            ->update(['window_started_at' => now()->subSeconds(61)]);
        $this->assertTrue($throttle->attempt('policy-source', 'policy.user')->allowed);
        $this->assertSame(1, (int) DB::table('identity_auth_attempt_ledgers')
            ->where('scope_hash', hash('sha256', "policy-source\0policy.user"))
            ->value('attempt_count'));
        $this->assertTrue($throttle->attempt('policy-source', 'policy.user')->allowed);
        $this->assertTrue($throttle->attempt('policy-source', 'policy.user')->allowed);
        $blocked = $throttle->attempt('policy-source', 'policy.user');
        $this->assertFalse($blocked->allowed);
        $this->assertNotNull($blocked->blockedUntil);
        $this->assertGreaterThanOrEqual(4, now()->diffInMinutes($blocked->blockedUntil, false));
    }

    public function test_last_published_security_snapshot_is_retained_when_the_contract_fails(): void
    {
        $settings = new class implements GetEffectivePlatformSettings
        {
            public bool $fails = false;

            public function current(): array
            {
                if ($this->fails) {
                    throw new \RuntimeException('platform settings unavailable');
                }

                return ['default_locale' => 'ar', 'timezone' => 'Asia/Riyadh', 'security' => [
                    'minimum_password_length' => 18,
                ]];
            }

            public function hasPublishedVersion(): bool
            {
                return true;
            }
        };
        $policy = new PasswordPolicy(null, $settings);

        $this->assertContains('min_length', $policy->violations('Valid Pass 123'));
        $settings->fails = true;
        $this->assertContains('min_length', $policy->violations('Valid Pass 123'));
    }

    public function test_published_security_policy_equal_to_bootstrap_defaults_remains_authoritative(): void
    {
        $this->bindPublishedSecurity([
            'idle_timeout_minutes' => 30,
            'absolute_session_hours' => 8,
            'minimum_password_length' => 14,
            'password_history_count' => 5,
            'failed_login_attempts' => 4,
            'failed_login_window_minutes' => 1,
            'lockout_minutes' => 30,
        ]);

        $this->assertTrue($this->app->make(GetEffectivePlatformSettings::class)->hasPublishedVersion());
        $this->assertContains('min_length', $this->app->make(PasswordPolicy::class)->violations('ValidPass123'));
        $this->assertSame([], $this->app->make(PasswordPolicy::class)->violations('Longer Valid Pass 123!'));
    }

    public function test_environment_config_does_not_override_an_explicitly_published_security_policy(): void
    {
        config(['identity.password.min_length' => 6]);
        $this->bindPublishedSecurity([
            'minimum_password_length' => 18,
            'idle_timeout_minutes' => 30,
            'absolute_session_hours' => 8,
            'password_history_count' => 5,
            'failed_login_attempts' => 4,
            'failed_login_window_minutes' => 1,
            'lockout_minutes' => 30,
        ]);

        $this->assertContains('min_length', $this->app->make(PasswordPolicy::class)->violations('ValidPass123'));
        $this->assertSame([], $this->app->make(PasswordPolicy::class)->violations('Longer Valid Pass 123!!'));
    }

    public function test_no_published_version_means_identity_uses_environment_floors(): void
    {
        config(['identity.password.min_length' => 14]);
        $this->app->instance(GetEffectivePlatformSettings::class, new class implements GetEffectivePlatformSettings
        {
            public function current(): array
            {
                return ['default_locale' => 'ar', 'timezone' => 'Asia/Riyadh', 'security' => []];
            }

            public function hasPublishedVersion(): bool
            {
                return false;
            }
        });

        $this->assertContains('min_length', $this->app->make(PasswordPolicy::class)->violations('ValidPass123'));
        $this->assertSame([], $this->app->make(PasswordPolicy::class)->violations('ValidPass1234More'));
    }

    public function test_contract_failure_fails_safely_and_uses_environment_floor(): void
    {
        config(['identity.password.min_length' => 14]);
        $this->app->instance(GetEffectivePlatformSettings::class, new class implements GetEffectivePlatformSettings
        {
            public function current(): array
            {
                throw new \RuntimeException('platform settings unavailable');
            }

            public function hasPublishedVersion(): bool
            {
                throw new \RuntimeException('platform settings unavailable');
            }
        });

        $this->assertContains('min_length', $this->app->make(PasswordPolicy::class)->violations('ValidPass123'));
        $this->assertSame([], $this->app->make(PasswordPolicy::class)->violations('ValidPass1234More'));
    }

    /** @param array<string, int> $security */
    private function bindPublishedSecurity(array $security): GetEffectivePlatformSettings
    {
        $settings = new class($security) implements GetEffectivePlatformSettings
        {
            /** @param array<string, int> $publishedSecurity */
            public function __construct(private readonly array $publishedSecurity) {}

            public function current(): array
            {
                return [
                    'default_locale' => 'ar',
                    'timezone' => 'Asia/Riyadh',
                    'security' => $this->publishedSecurity,
                ];
            }

            public function hasPublishedVersion(): bool
            {
                return true;
            }
        };
        $this->app->instance(GetEffectivePlatformSettings::class, $settings);

        return $settings;
    }

    private function activeUser(): string
    {
        $userId = fake()->uuid();
        $now = now();
        DB::table('users')->insert([
            'id' => $userId, 'username' => 'policy.user', 'person_id' => null, 'person_version' => null,
            'display_name_ar' => 'اختبار السياسة', 'display_name_en' => 'Policy Test', 'status' => 'active',
            'password_version' => 1, 'failed_login_count' => 0, 'lockout_level' => 0, 'locked_until' => null,
            'is_admin' => false, 'must_change_password' => false, 'last_login_at' => null, 'lock_version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('credentials')->insert([
            'id' => fake()->uuid(), 'user_id' => $userId, 'password_hash' => password_hash('unused', PASSWORD_ARGON2ID),
            'hash_algorithm' => 'argon2id', 'password_changed_at' => $now, 'policy_version' => 'test', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $userId;
    }

    private function bindingContext(): TrustedRequestBindingContext
    {
        return new TrustedRequestBindingContext('10.20.30.0/24', hash('sha256', 'platform-policy-test-agent'));
    }

    /** @return array{ip_cidr: string, user_agent_hash: string} */
    private function bindingMetadata(): array
    {
        $context = $this->bindingContext();

        return ['ip_cidr' => $context->ipCidr, 'user_agent_hash' => $context->userAgentHash];
    }
}
