<?php

namespace Modules\Identity\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Exceptions\AuthenticationFailed;
use Modules\Identity\Features\Activation\Handler\ActivationHandler;
use Modules\Identity\Features\Authentication\Handler\AuthenticationHandler;
use Modules\Identity\Features\Sessions\Contracts\TrustedRequestBindingContext;
use Modules\Identity\Features\Sessions\Handler\SessionHandler;
use Modules\Identity\Features\Totp\Handler\TotpHandler;
use Modules\Identity\Features\UserAccount\Handler\UserAccountHandler;
use Tests\TestCase;

class IdentityMfaPolicyRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', [
            '--path' => 'Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityCredentialCoreTables.php',
        ])->assertSuccessful();
        $this->artisan('migrate', [
            '--path' => 'Modules/Identity/Infrastructure/Persistence/Migrations/ZAddIdentityMfaRequiredColumn.php',
        ])->assertSuccessful();
    }

    public function test_mfa_required_account_is_admin_like_enrolls_totp_and_requires_it_at_login(): void
    {
        $userId = $this->user('mfa-policy.user');
        DB::table('users')->where('id', $userId)->update(['mfa_required' => true]);

        $activation = $this->app->make(ActivationHandler::class)->issue($userId);
        $this->assertArrayHasKey('totp_secret', $activation);
        $this->assertArrayHasKey('totp_otpauth_uri', $activation);
        $this->app->make(ActivationHandler::class)->activate(
            $activation['token'],
            'A secure activation phrase 2026!',
            $this->totpCode($activation['totp_secret'], time()),
        );
        $this->assertTrue((bool) DB::table('identity_totp')->where('user_id', $userId)->value('enabled'));

        $authentication = $this->app->make(AuthenticationHandler::class);
        try {
            $authentication->authenticate('mfa-policy.user', 'A secure activation phrase 2026!');
            $this->fail('An mfa_required account without TOTP must not authenticate.');
        } catch (AuthenticationFailed) {
            // The public failure is generic.
        }
        $transport = $authentication->authenticate(
            'mfa-policy.user',
            'A secure activation phrase 2026!',
            $this->totpCode($activation['totp_secret'], time() + 30),
        );
        $this->assertSame($userId, $transport->userId);
    }

    public function test_mfa_required_alone_is_not_sufficient_for_an_optional_account(): void
    {
        $userId = $this->user('mfa-optional.user');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('totp_not_required');
        $this->app->make(TotpHandler::class)->enroll($userId);
    }

    public function test_legacy_credential_login_reports_recovery_required_without_lockout_escalation(): void
    {
        $userId = $this->activate('legacy-recovery.user');
        $legacyHash = (string) DB::table('credentials')->where('user_id', $userId)->value('password_hash');
        DB::table('credentials')->where('user_id', $userId)->update([
            // A legacy-style hash the current hasher reports as needsRehash.
            'password_hash' => '$2y$12$'.substr($legacyHash, 7),
            'hash_algorithm' => 'bcrypt',
        ]);

        $authentication = $this->app->make(AuthenticationHandler::class);
        foreach (range(1, 3) as $_) {
            try {
                $authentication->authenticate('legacy-recovery.user', 'A secure activation phrase 2026!', null, [], 'legacy-source');
                $this->fail('A legacy credential must fail with recovery required.');
            } catch (AuthenticationFailed) {
                // Generic public failure.
            }
        }

        $this->assertSame(0, (int) DB::table('users')->where('id', $userId)->value('failed_login_count'));
        $this->assertSame('active', DB::table('users')->where('id', $userId)->value('status'));
        $this->assertNull(DB::table('users')->where('id', $userId)->value('locked_until'));
        $payloads = DB::table('outbox_events')
            ->where('event_type', 'com.cluster.identity.authentication_failed.v1')
            ->pluck('cloud_event');
        $this->assertTrue($payloads->contains(static fn (string $payload): bool => str_contains($payload, 'credential_recovery_required')));
        $this->assertDatabaseMissing('outbox_events', ['event_type' => 'com.cluster.identity.account_login_locked.v1']);
    }

    public function test_admin_reset_credential_reissues_activation_and_the_account_logs_in_again(): void
    {
        $userId = $this->activate('reset-credential.user');
        $this->app->make(UserAccountHandler::class)->transition(
            $userId,
            'reset-credential',
            1,
            'legacy hash recovery',
            [
                'principal_id' => '018f6f7d-0c00-7000-8000-000000000021',
                'operation' => 'reset-credential:'.$userId,
                'key_hash' => hash('sha256', 'reset-credential-key'),
                'request_hash' => hash('sha256', 'reset-credential-request'),
            ],
            fn (array $account, string $action, ?string $reason, int $version): array => [
                'specversion' => '1.0',
                'id' => fake()->uuid(),
                'source' => '/identity',
                'type' => 'com.cluster.identity.useraccountchanged.v1',
                'subject' => '/identity/accounts/'.$account['id'],
                'time' => '2026-07-18T09:00:00Z',
                'datacontenttype' => 'application/json',
                'data' => ['account_id' => $account['id'], 'action' => $action, 'lock_version' => $version],
            ],
        );

        $this->assertDatabaseMissing('credentials', ['user_id' => $userId]);
        $this->assertSame('pending', DB::table('users')->where('id', $userId)->value('status'));
        $this->assertSame(1, (int) DB::table('users')->where('id', $userId)->value('must_change_password'));
        $this->assertSame(2, (int) DB::table('users')->where('id', $userId)->value('password_version'));

        $activation = $this->app->make(ActivationHandler::class)->issue($userId);
        $this->app->make(ActivationHandler::class)->activate($activation['token'], 'A recovered activation phrase 2026!');

        $this->assertSame('active', DB::table('users')->where('id', $userId)->value('status'));
        $this->assertSame($userId, $this->app->make(AuthenticationHandler::class)->authenticate(
            'reset-credential.user',
            'A recovered activation phrase 2026!',
        )->userId);
    }

    public function test_session_revocation_reports_password_version_changed(): void
    {
        $userId = $this->activate('reason-version.user');
        $token = $this->issueToken($userId, 'reason-version-source');
        DB::table('users')->where('id', $userId)->update(['password_version' => DB::raw('password_version + 1')]);

        $this->assertNull($this->app->make(SessionHandler::class)->resolve($token, $this->bindingContext()));
        $this->assertSame('password_version_changed', $this->lastRevocationReason());
    }

    public function test_session_revocation_reports_account_not_active(): void
    {
        $userId = $this->activate('reason-status.user');
        $token = $this->issueToken($userId, 'reason-status-source');
        DB::table('users')->where('id', $userId)->update(['status' => 'disabled']);

        $this->assertNull($this->app->make(SessionHandler::class)->resolve($token, $this->bindingContext()));
        $this->assertSame('account_not_active', $this->lastRevocationReason());
    }

    public function test_session_revocation_reports_totp_policy_changed(): void
    {
        $userId = $this->activate('reason-totp.user');
        $token = $this->issueToken($userId, 'reason-totp-source');
        DB::table('users')->where('id', $userId)->update(['mfa_required' => true]);

        $this->assertNull($this->app->make(SessionHandler::class)->resolve($token, $this->bindingContext()));
        $this->assertSame('totp_policy_changed', $this->lastRevocationReason());
    }

    public function test_session_revocation_reports_credentials_missing(): void
    {
        $userId = $this->activate('reason-credentials.user');
        $token = $this->issueToken($userId, 'reason-credentials-source');
        DB::table('credentials')->where('user_id', $userId)->delete();

        $this->assertNull($this->app->make(SessionHandler::class)->resolve($token, $this->bindingContext()));
        $this->assertSame('credentials_missing', $this->lastRevocationReason());
    }

    public function test_session_revocation_reports_binding_mismatch(): void
    {
        $userId = $this->activate('reason-binding.user');
        $token = $this->issueToken($userId, 'reason-binding-source');

        $this->assertNull($this->app->make(SessionHandler::class)->resolve(
            $token,
            new TrustedRequestBindingContext('10.20.31.0/24', hash('sha256', 'identity-test-agent')),
        ));
        $this->assertSame('binding_mismatch', $this->lastRevocationReason());
    }

    public function test_session_revocation_reports_session_expired_when_no_account_check_fails(): void
    {
        $userId = $this->activate('reason-expired.user');
        $token = $this->issueToken($userId, 'reason-expired-source');
        DB::table('identity_sessions')->where('user_id', $userId)->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertNull($this->app->make(SessionHandler::class)->resolve($token, $this->bindingContext()));
        $this->assertSame('session_expired', $this->lastRevocationReason());
    }

    private function lastRevocationReason(): string
    {
        $event = DB::table('outbox_events')
            ->where('event_type', 'com.cluster.identity.session_revoked.v1')
            ->orderByDesc('id')
            ->first('cloud_event');
        $this->assertNotNull($event);
        $payload = json_decode((string) $event->cloud_event, true, 32, JSON_THROW_ON_ERROR);

        return (string) $payload['data']['reason_code'];
    }

    private function issueToken(string $userId, string $source): string
    {
        $transport = $this->app->make(AuthenticationHandler::class)->authenticate(
            DB::table('users')->where('id', $userId)->value('username'),
            'A secure activation phrase 2026!',
            null,
            $this->bindingMetadata(),
            $source,
        );

        return (string) $transport->cookie->getValue();
    }

    private function activate(string $username): string
    {
        $userId = $this->user($username);
        $token = $this->app->make(ActivationHandler::class)->issue($userId);
        $this->app->make(ActivationHandler::class)->activate($token['token'], 'A secure activation phrase 2026!');

        return $userId;
    }

    private function bindingContext(): TrustedRequestBindingContext
    {
        return new TrustedRequestBindingContext('10.20.30.0/24', hash('sha256', 'identity-test-agent'));
    }

    /** @return array{ip_cidr: string, user_agent_hash: string} */
    private function bindingMetadata(): array
    {
        $context = $this->bindingContext();

        return ['ip_cidr' => $context->ipCidr, 'user_agent_hash' => $context->userAgentHash];
    }

    private function user(string $username): string
    {
        $id = fake()->uuid();
        DB::table('users')->insert([
            'id' => $id,
            'username' => $username,
            'person_id' => null,
            'person_version' => null,
            'display_name_ar' => 'اختبار سياسة التحقق',
            'display_name_en' => 'MFA Policy Test',
            'status' => 'pending',
            'must_change_password' => true,
            'password_version' => 1,
            'is_admin' => false,
            'last_login_at' => null,
            'failed_login_count' => 0,
            'lockout_level' => 0,
            'locked_until' => null,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function totpCode(string $secret, int $timestamp): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $decoded = '';
        foreach (str_split($secret) as $character) {
            $buffer = ($buffer << 5) | strpos($alphabet, $character);
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        $step = intdiv($timestamp, 30);
        $counter = pack('N2', intdiv($step, 4294967296), $step & 0xFFFFFFFF);
        $digest = hash_hmac('sha1', $counter, $decoded, true);
        $offset = ord($digest[19]) & 0x0F;
        $binary = ((ord($digest[$offset]) & 0x7F) << 24)
            | (ord($digest[$offset + 1]) << 16)
            | (ord($digest[$offset + 2]) << 8)
            | ord($digest[$offset + 3]);

        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
