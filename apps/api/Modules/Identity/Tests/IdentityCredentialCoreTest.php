<?php

namespace Modules\Identity\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Modules\Identity\Domain\PasswordPolicy;
use Modules\Identity\Exceptions\AuthenticationFailed;
use Modules\Identity\Features\Activation\Handler\ActivationHandler;
use Modules\Identity\Features\Authentication\Handler\AuthenticationHandler;
use Modules\Identity\Features\Credentials\Handler\CredentialHandler;
use Modules\Identity\Features\Sessions\Contracts\TrustedRequestBindingContext;
use Modules\Identity\Features\Sessions\Handler\SessionHandler;
use Modules\Identity\Features\Totp\Handler\TotpHandler;
use Modules\Identity\Infrastructure\Outbox\IdentityOutbox;
use Modules\Identity\Infrastructure\Security\LocalUsernameDenylist;
use Modules\Identity\Infrastructure\Security\PasswordHasher;
use Modules\Identity\Infrastructure\Security\PersistentPreAuthThrottle;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class IdentityCredentialCoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', [
            '--path' => 'Modules/Identity/Infrastructure/Persistence/Migrations/AddIdentityCredentialCoreTables.php',
        ])->assertSuccessful();
    }

    public function test_activation_token_is_hashed_one_time_and_creates_an_argon2id_credential(): void
    {
        $userId = $this->user('pending.user');
        $token = $this->app->make(ActivationHandler::class)->issue($userId);

        $this->assertNotSame($token['token'], DB::table('identity_activation_tokens')->value('token_hash'));
        $this->assertStringNotContainsString($token['token'], (string) DB::table('outbox_events')->value('cloud_event'));

        $this->app->make(ActivationHandler::class)->activate($token['token'], 'A secure activation phrase 2026!');
        $credential = DB::table('credentials')->where('user_id', $userId)->first();
        $this->assertNotNull($credential);
        $this->assertStringStartsWith('$argon2id$', (string) $credential->password_hash);
        $this->assertStringNotContainsString('A secure activation phrase 2026!', (string) $credential->password_hash);
        $this->assertSame('active', DB::table('users')->where('id', $userId)->value('status'));

        $this->expectException(AuthenticationFailed::class);
        $this->app->make(ActivationHandler::class)->activate($token['token'], 'A different activation phrase 2026!');
    }

    public function test_credentialless_account_remains_pending_and_cannot_issue_a_session(): void
    {
        $userId = $this->user('credentialless.user');
        $this->assertSame('pending', DB::table('users')->where('id', $userId)->value('status'));
        $this->assertFalse($this->app->make(CredentialHandler::class)->hasCredential($userId));

        $this->expectException(AuthenticationFailed::class);
        $this->app->make(SessionHandler::class)->issue($userId);
    }

    public function test_session_is_opaque_cookie_bound_to_csrf_and_revocable_by_password_change(): void
    {
        $userId = $this->activate('session.user');
        $authentication = $this->app->make(AuthenticationHandler::class);
        $context = $this->bindingContext();
        $transport = $authentication->authenticate('session.user', 'A secure activation phrase 2026!', null, $this->bindingMetadata());

        $this->assertInstanceOf(Cookie::class, $transport->cookie);
        $this->assertTrue($transport->cookie->isSecure());
        $this->assertTrue($transport->cookie->isHttpOnly());
        $this->assertSame('lax', $transport->cookie->getSameSite());
        $rawSessionToken = (string) $transport->cookie->getValue();
        $this->assertSame(hash('sha256', $rawSessionToken), DB::table('identity_sessions')->value('token_hash'));
        $this->assertNotSame($rawSessionToken, DB::table('identity_sessions')->value('token_hash'));
        $this->assertTrue($this->app->make(SessionHandler::class)->validateCsrf($rawSessionToken, $transport->csrfToken, $context));
        $this->assertFalse($this->app->make(SessionHandler::class)->validateCsrf($rawSessionToken, 'wrong-csrf', $context));

        $second = $this->app->make(SessionHandler::class)->issue($userId);
        $this->app->make(CredentialHandler::class)->change($userId, 'A secure activation phrase 2026!', 'A completely new phrase 2026!');

        $this->assertNull($this->app->make(SessionHandler::class)->resolve($rawSessionToken, $context));
        $this->assertNull($this->app->make(SessionHandler::class)->resolve((string) $second->cookie->getValue(), $context));
        $this->assertSame(2, (int) DB::table('users')->where('id', $userId)->value('password_version'));
        $this->assertGreaterThanOrEqual(2, DB::table('identity_password_history')->where('user_id', $userId)->count());
    }

    public function test_source_ledger_blocks_progressively_without_mutating_account_or_sessions(): void
    {
        config([
            'identity.pre_auth_throttle.source_username_max_attempts' => 2,
            'identity.pre_auth_throttle.account_max_attempts' => 10,
        ]);
        $userId = $this->activate('lock.user');
        $authentication = $this->app->make(AuthenticationHandler::class);
        $binding = $this->bindingContext();
        $existingSession = $authentication->authenticate('lock.user', 'A secure activation phrase 2026!', null, $this->bindingMetadata(), 'existing-source');
        foreach (range(1, 3) as $_) {
            try {
                $authentication->authenticate('lock.user', 'wrong password phrase');
                $this->fail('Invalid credentials must fail.');
            } catch (AuthenticationFailed $failure) {
                $this->assertSame('Authentication failed.', $failure->getMessage());
            }
        }

        $this->assertSame('active', DB::table('users')->where('id', $userId)->value('status'));
        $this->assertNull(DB::table('users')->where('id', $userId)->value('locked_until'));
        $this->assertSame(2, (int) DB::table('users')->where('id', $userId)->value('failed_login_count'));
        $this->assertNull(DB::table('identity_sessions')->where('id', $existingSession->sessionId)->value('revoked_at'));
        $this->assertNotNull($this->app->make(SessionHandler::class)->resolve((string) $existingSession->cookie->getValue(), $binding));
        $ledger = DB::table('identity_auth_attempt_ledgers')->where('scope', 'source_username')->where('scope_hash', hash('sha256', "unknown\0lock.user"))->first();
        $this->assertNotNull($ledger);
        $this->assertSame(1, (int) $ledger->lock_level);
        $this->assertNotNull($ledger->blocked_until);
        $this->assertDatabaseMissing('outbox_events', ['event_type' => 'com.cluster.identity.account_login_locked.v1']);
    }

    public function test_five_failed_credentials_lock_only_new_login_and_preserve_existing_session(): void
    {
        config([
            'identity.pre_auth_throttle.source_username_max_attempts' => 100,
            'identity.pre_auth_throttle.account_max_attempts' => 100,
        ]);
        $userId = $this->activate('account-lock.user');
        $authentication = $this->app->make(AuthenticationHandler::class);
        $binding = $this->bindingContext();
        $existingSession = $authentication->authenticate('account-lock.user', 'A secure activation phrase 2026!', null, $this->bindingMetadata(), 'existing-account-lock-source');
        foreach (range(1, 5) as $_) {
            $this->attemptFailure($authentication, 'account-lock.user', 'account-lock-source');
        }

        $lockedUntil = DB::table('users')->where('id', $userId)->value('locked_until');
        $this->assertSame('active', DB::table('users')->where('id', $userId)->value('status'));
        $this->assertSame(5, (int) DB::table('users')->where('id', $userId)->value('failed_login_count'));
        $this->assertSame(1, (int) DB::table('users')->where('id', $userId)->value('lockout_level'));
        $this->assertNotNull($lockedUntil);
        $this->assertNull(DB::table('identity_sessions')->where('id', $existingSession->sessionId)->value('revoked_at'));
        $this->assertNotNull($this->app->make(SessionHandler::class)->resolve((string) $existingSession->cookie->getValue(), $binding));

        $this->attemptFailure($authentication, 'account-lock.user', 'new-login-source');
        $this->assertSame(5, (int) DB::table('users')->where('id', $userId)->value('failed_login_count'));
        $this->assertNotNull($this->app->make(SessionHandler::class)->resolve((string) $existingSession->cookie->getValue(), $binding));
        $this->expectException(AuthenticationFailed::class);
        $this->app->make(SessionHandler::class)->issue($userId);
    }

    public function test_account_login_lock_progression_is_15_30_60_then_120_minutes(): void
    {
        config([
            'identity.pre_auth_throttle.source_username_max_attempts' => 100,
            'identity.pre_auth_throttle.account_max_attempts' => 100,
        ]);
        $userId = $this->activate('progression.user');
        $authentication = $this->app->make(AuthenticationHandler::class);
        foreach ([15, 30, 60, 120] as $expectedMinutes) {
            $this->attemptFailure($authentication, 'progression.user', 'progression-source');
            $this->attemptFailure($authentication, 'progression.user', 'progression-source');
            $this->attemptFailure($authentication, 'progression.user', 'progression-source');
            $this->attemptFailure($authentication, 'progression.user', 'progression-source');
            $this->attemptFailure($authentication, 'progression.user', 'progression-source');
            $lockedUntil = CarbonImmutable::parse(DB::table('users')->where('id', $userId)->value('locked_until'), 'UTC');
            $minutes = CarbonImmutable::now('UTC')->diffInMinutes($lockedUntil, false);
            $level = (int) DB::table('users')->where('id', $userId)->value('lockout_level');
            $this->assertSame($expectedMinutes, [15, 30, 60, 120][$level - 1]);
            $this->assertGreaterThanOrEqual($expectedMinutes - 1, $minutes);
            DB::table('users')->where('id', $userId)->update(['locked_until' => now()->subSecond()]);
        }
    }

    public function test_successful_login_resets_account_failure_progression(): void
    {
        config([
            'identity.pre_auth_throttle.source_username_max_attempts' => 100,
            'identity.pre_auth_throttle.account_max_attempts' => 100,
        ]);
        $userId = $this->activate('reset-progression.user');
        $authentication = $this->app->make(AuthenticationHandler::class);
        $this->attemptFailure($authentication, 'reset-progression.user', 'reset-source');
        $this->attemptFailure($authentication, 'reset-progression.user', 'reset-source');
        $authentication->authenticate('reset-progression.user', 'A secure activation phrase 2026!', null, $this->bindingMetadata(), 'reset-success-source');

        $this->assertSame(0, (int) DB::table('users')->where('id', $userId)->value('failed_login_count'));
        $this->assertSame(0, (int) DB::table('users')->where('id', $userId)->value('lockout_level'));
        $this->assertNull(DB::table('users')->where('id', $userId)->value('locked_until'));
    }

    public function test_admin_totp_is_required_and_state_is_encrypted(): void
    {
        $userId = $this->activate('admin.user');
        DB::table('users')->where('id', $userId)->update(['is_admin' => true]);
        $totp = $this->app->make(TotpHandler::class);
        $enrollment = $totp->enroll($userId);
        $this->assertStringNotContainsString($enrollment['secret'], (string) DB::table('identity_totp')->where('user_id', $userId)->value('secret_ciphertext'));

        $authentication = $this->app->make(AuthenticationHandler::class);
        try {
            $authentication->authenticate('admin.user', 'A secure activation phrase 2026!');
            $this->fail('An administrative account without TOTP must not authenticate.');
        } catch (AuthenticationFailed) {
            // The public failure is generic.
        }
        $code = $this->totpCode($enrollment['secret'], time());
        $this->assertTrue($totp->confirm($userId, $code));
        $this->assertSame(
            $this->totpStep(time()),
            (int) DB::table('identity_totp')->where('user_id', $userId)->value('last_used_step'),
        );
        $this->assertFalse($totp->confirm($userId, $code));
        $nextCode = $this->totpCode($enrollment['secret'], time() + 30);
        $transport = $authentication->authenticate('admin.user', 'A secure activation phrase 2026!', $nextCode);
        $this->assertSame($userId, $transport->userId);

        $this->expectException(AuthenticationFailed::class);
        $authentication->authenticate('admin.user', 'A secure activation phrase 2026!', $nextCode);
    }

    public function test_must_change_password_only_receives_a_restricted_session(): void
    {
        $userId = $this->activate('must-change.user');
        DB::table('users')->where('id', $userId)->update(['must_change_password' => true]);

        $authentication = $this->app->make(AuthenticationHandler::class);
        $context = $this->bindingContext();
        $transport = $authentication->authenticate('must-change.user', 'A secure activation phrase 2026!', null, $this->bindingMetadata(), 'source-a');
        $this->assertTrue($transport->restricted);
        $this->assertTrue($transport->toArray()['restricted']);
        $resolved = $this->app->make(SessionHandler::class)->resolve((string) $transport->cookie->getValue(), $context);
        $this->assertIsArray($resolved);
        $this->assertTrue($resolved['restricted']);
        $this->assertSame('password_change_only', json_decode(
            (string) DB::table('identity_sessions')->where('id', $transport->sessionId)->value('metadata'),
            true,
        )['session_restriction']);

        $this->app->make(CredentialHandler::class)->change(
            $userId,
            'A secure activation phrase 2026!',
            'A renewed password phrase 2026!',
        );
        $normal = $authentication->authenticate('must-change.user', 'A renewed password phrase 2026!', null, [], 'source-a');
        $this->assertFalse($normal->restricted);
    }

    public function test_pre_auth_throttle_is_source_and_normalized_username_scoped_without_locking_accounts(): void
    {
        config([
            'identity.pre_auth_throttle.source_username_max_attempts' => 1,
            'identity.pre_auth_throttle.account_max_attempts' => 20,
        ]);
        $userId = $this->activate('throttle.user');
        $authentication = $this->app->make(AuthenticationHandler::class);

        foreach (['source-a', 'source-a'] as $source) {
            try {
                $authentication->authenticate('THROTTLE.USER', 'wrong password phrase', null, [], $source);
            } catch (AuthenticationFailed) {
                // Every generic failure has the same public exception.
            }
        }
        $this->assertSame(1, (int) DB::table('users')->where('id', $userId)->value('failed_login_count'));
        $this->assertSame('active', DB::table('users')->where('id', $userId)->value('status'));

        try {
            $authentication->authenticate('throttle.user', 'wrong password phrase', null, [], 'source-b');
        } catch (AuthenticationFailed) {
            // A different source gets an independent pre-auth budget.
        }
        $this->assertSame(2, (int) DB::table('users')->where('id', $userId)->value('failed_login_count'));
        $failedEvents = DB::table('outbox_events')->where('event_type', 'com.cluster.identity.authentication_failed.v1')->get();
        $this->assertCount(3, $failedEvents);
        foreach ($failedEvents as $event) {
            $payload = (string) $event->cloud_event;
            $this->assertStringNotContainsString('wrong password phrase', $payload);
            $this->assertStringNotContainsString('throttle.user', $payload);
            $this->assertStringNotContainsString('source-a', $payload);
            $this->assertStringNotContainsString('source-b', $payload);
        }
    }

    public function test_first_use_after_idle_period_is_rejected_even_without_last_seen_at(): void
    {
        $userId = $this->activate('idle.user');
        $context = $this->bindingContext();
        $transport = $this->app->make(AuthenticationHandler::class)->authenticate(
            'idle.user',
            'A secure activation phrase 2026!',
            null,
            $this->bindingMetadata(),
            'source-idle',
        );
        DB::table('identity_sessions')->where('id', $transport->sessionId)->update([
            'issued_at' => now()->subMinutes(31),
            'last_seen_at' => null,
        ]);

        $this->assertNull($this->app->make(SessionHandler::class)->resolve((string) $transport->cookie->getValue(), $context));
        $this->assertNotNull(DB::table('identity_sessions')->where('id', $transport->sessionId)->value('revoked_at'));
        $this->assertSame('active', DB::table('users')->where('id', $userId)->value('status'));
    }

    public function test_local_denylist_and_username_fragments_are_applied_without_network_access(): void
    {
        config(['identity.password.denylist.path' => __DIR__.'/Fixtures/password-denylist.txt']);
        $policy = new PasswordPolicy(new LocalUsernameDenylist);
        $this->assertContains('common_password', $policy->violations('Known-Leak-Password'));
        $this->assertContains('contains_username', $policy->violations('A safe employee phrase 2026!', 'Employee.One'));
        $this->assertNotContains('contains_username', $policy->violations('A safe clinical phrase 2026!', 'Employee.One'));
    }

    public function test_local_denylist_fails_closed_when_non_testing_corpus_is_missing(): void
    {
        $originalEnvironment = config('app.env');
        config(['identity.password.denylist.path' => __DIR__.'/Fixtures/missing-denylist.txt']);
        $this->app->detectEnvironment(static fn (): string => 'production');
        try {
            $this->app->make(LocalUsernameDenylist::class)->contains('password');
            $this->fail('A missing production denylist must fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The configured local Identity password denylist is unavailable.', $exception->getMessage());
        } finally {
            $this->app->detectEnvironment(static fn (): string => (string) $originalEnvironment);
        }
    }

    public function test_persistent_pre_auth_ledger_has_separate_source_and_account_windows_with_expiry(): void
    {
        config([
            'identity.pre_auth_throttle.source_username_max_attempts' => 1,
            'identity.pre_auth_throttle.account_max_attempts' => 2,
            'identity.pre_auth_throttle.window_seconds' => 60,
        ]);
        $throttle = $this->app->make(PersistentPreAuthThrottle::class);
        $this->assertTrue($throttle->attempt('source-a', 'ledger.user')->allowed);
        $this->assertFalse($throttle->attempt('source-a', 'ledger.user')->allowed);
        $this->assertTrue($throttle->attempt('source-b', 'ledger.user')->allowed);
        $this->assertFalse($throttle->attempt('source-b', 'ledger.user')->allowed);
        $this->assertSame(1, (int) DB::table('identity_auth_attempt_ledgers')->where('scope', 'source_username')->where('scope_hash', hash('sha256', "source-a\0ledger.user"))->value('attempt_count'));
        $this->assertSame(2, (int) DB::table('identity_auth_attempt_ledgers')->where('scope', 'account')->where('scope_hash', hash('sha256', 'ledger.user'))->value('attempt_count'));

        DB::table('identity_auth_attempt_ledgers')->update([
            'window_started_at' => now()->subMinute()->subSecond(),
            'blocked_until' => now()->subSecond(),
        ]);
        $this->assertTrue($throttle->attempt('source-a', 'ledger.user')->allowed);
        $this->assertSame(1, (int) DB::table('identity_auth_attempt_ledgers')->where('scope', 'account')->where('scope_hash', hash('sha256', 'ledger.user'))->value('attempt_count'));
    }

    public function test_persistent_pre_auth_ledger_serializes_concurrent_budget_decisions(): void
    {
        config([
            'identity.pre_auth_throttle.source_username_max_attempts' => 1,
            'identity.pre_auth_throttle.account_max_attempts' => 1,
        ]);
        $first = $this->app->make(PersistentPreAuthThrottle::class);
        $second = $this->app->make(PersistentPreAuthThrottle::class);
        $this->assertTrue($first->attempt('same-source', 'concurrent.user')->allowed);
        $this->assertFalse($second->attempt('same-source', 'concurrent.user')->allowed);
        $this->assertSame(1, DB::table('identity_auth_attempt_ledgers')->where('scope', 'account')->where('scope_hash', hash('sha256', 'concurrent.user'))->value('attempt_count'));
    }

    public function test_account_aggregate_rate_limit_is_only_an_audit_signal(): void
    {
        config([
            'identity.pre_auth_throttle.source_username_max_attempts' => 10,
            'identity.pre_auth_throttle.account_max_attempts' => 1,
        ]);
        $userId = $this->activate('aggregate.user');
        $authentication = $this->app->make(AuthenticationHandler::class);
        foreach (['aggregate-source-a', 'aggregate-source-b'] as $source) {
            try {
                $authentication->authenticate('aggregate.user', 'wrong password phrase', null, [], $source);
            } catch (AuthenticationFailed) {
                // The account-wide aggregate must not become an account lock.
            }
        }

        $this->assertSame('active', DB::table('users')->where('id', $userId)->value('status'));
        $this->assertNull(DB::table('users')->where('id', $userId)->value('locked_until'));
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'com.cluster.identity.authentication_failed.v1']);
        $payloads = DB::table('outbox_events')->where('event_type', 'com.cluster.identity.authentication_failed.v1')->pluck('cloud_event');
        $this->assertTrue($payloads->contains(static fn (string $payload): bool => str_contains($payload, 'account_rate_limited')));
    }

    public function test_session_binding_context_is_required_and_mismatches_fail_closed(): void
    {
        $this->activate('binding.user');
        $transport = $this->app->make(AuthenticationHandler::class)->authenticate(
            'binding.user',
            'A secure activation phrase 2026!',
            null,
            $this->bindingMetadata(),
            'binding-source',
        );
        $sessionHandler = $this->app->make(SessionHandler::class);
        $this->assertNull($sessionHandler->resolve((string) $transport->cookie->getValue(), new TrustedRequestBindingContext('10.20.31.0/24', hash('sha256', 'identity-test-agent'))));
        $this->assertNull($sessionHandler->resolve((string) $transport->cookie->getValue(), new TrustedRequestBindingContext('10.20.30.0/24', hash('sha256', 'identity-test-agent'), false)));
    }

    public function test_administrative_locked_status_denies_sessions(): void
    {
        $userId = $this->activate('administratively-locked.user');
        $transport = $this->app->make(AuthenticationHandler::class)->authenticate(
            'administratively-locked.user',
            'A secure activation phrase 2026!',
            null,
            $this->bindingMetadata(),
            'admin-lock-source',
        );
        DB::table('users')->where('id', $userId)->update(['status' => 'locked']);

        $this->assertNull($this->app->make(SessionHandler::class)->resolve((string) $transport->cookie->getValue(), $this->bindingContext()));
        $this->assertNotNull(DB::table('identity_sessions')->where('id', $transport->sessionId)->value('revoked_at'));
    }

    public function test_authentication_selects_exactly_one_real_or_dummy_argon_verification(): void
    {
        $userId = $this->activate('instrumented.user');
        $realHash = (string) DB::table('credentials')->where('user_id', $userId)->value('password_hash');
        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('needsRehash')->once()->andReturn(false);
        $hasher->shouldReceive('check')->once()->with('wrong password phrase', $realHash)->andReturn(false);
        $instrumented = new AuthenticationHandler(
            new PasswordHasher($hasher),
            $this->app->make(SessionHandler::class),
            $this->app->make(TotpHandler::class),
            $this->app->make(IdentityOutbox::class),
            new PersistentPreAuthThrottle,
        );
        try {
            $instrumented->authenticate('instrumented.user', 'wrong password phrase', null, [], 'instrumented-failure');
            $this->fail('Wrong credentials must fail.');
        } catch (AuthenticationFailed) {
            // One real-hash verification was asserted by the instrumented hasher.
        }

        $dummyHash = (new PasswordHasher)->dummyHash();
        $dummyHasher = Mockery::mock(Hasher::class);
        $dummyHasher->shouldReceive('check')->once()->with('wrong password phrase', $dummyHash)->andReturn(false);
        $unknown = new AuthenticationHandler(
            new PasswordHasher($dummyHasher),
            $this->app->make(SessionHandler::class),
            $this->app->make(TotpHandler::class),
            $this->app->make(IdentityOutbox::class),
            new PersistentPreAuthThrottle,
        );
        try {
            $unknown->authenticate('missing.instrumented.user', 'wrong password phrase', null, [], 'instrumented-unknown');
            $this->fail('Unknown credentials must fail.');
        } catch (AuthenticationFailed) {
            // One fixed-dummy verification was asserted by the instrumented hasher.
        }

        $successHasher = Mockery::mock(Hasher::class);
        $successHasher->shouldReceive('check')->once()->with('A secure activation phrase 2026!', $realHash)->andReturn(true);
        $successHasher->shouldReceive('needsRehash')->once()->andReturn(false);
        $successful = new AuthenticationHandler(
            new PasswordHasher($successHasher),
            $this->app->make(SessionHandler::class),
            $this->app->make(TotpHandler::class),
            $this->app->make(IdentityOutbox::class),
            new PersistentPreAuthThrottle,
        );
        $this->assertSame($userId, $successful->authenticate(
            'instrumented.user',
            'A secure activation phrase 2026!',
            null,
            [],
            'instrumented-success',
        )->userId);
    }

    public function test_legacy_hash_fails_with_one_current_cost_dummy_verification(): void
    {
        $userId = $this->activate('legacy.user');
        $legacyHash = (string) DB::table('credentials')->where('user_id', $userId)->value('password_hash');
        $dummyHash = (new PasswordHasher)->dummyHash();
        $hasher = Mockery::mock(Hasher::class);
        $hasher->shouldReceive('needsRehash')->once()->with($legacyHash)->andReturn(true);
        $hasher->shouldReceive('check')->once()->with('A secure activation phrase 2026!', $dummyHash)->andReturn(true);
        $instrumented = new AuthenticationHandler(
            new PasswordHasher($hasher),
            $this->app->make(SessionHandler::class),
            $this->app->make(TotpHandler::class),
            $this->app->make(IdentityOutbox::class),
            new PersistentPreAuthThrottle,
        );

        $this->expectException(AuthenticationFailed::class);
        $instrumented->authenticate('legacy.user', 'A secure activation phrase 2026!', null, [], 'legacy-source');
    }

    private function activate(string $username): string
    {
        $userId = $this->user($username);
        $token = $this->app->make(ActivationHandler::class)->issue($userId);
        $this->app->make(ActivationHandler::class)->activate($token['token'], 'A secure activation phrase 2026!');

        return $userId;
    }

    private function attemptFailure(AuthenticationHandler $authentication, string $username, string $source): void
    {
        try {
            $authentication->authenticate($username, 'wrong password phrase', null, [], $source);
            $this->fail('Invalid credentials must fail.');
        } catch (AuthenticationFailed $failure) {
            $this->assertSame('Authentication failed.', $failure->getMessage());
        }
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
            'display_name_ar' => 'اختبار الهوية',
            'display_name_en' => 'Identity Test',
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

    private function totpStep(int $timestamp): int
    {
        return intdiv($timestamp, 30);
    }
}
