<?php

namespace Modules\Identity\Features\Authentication\Handler;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Domain\UserAccount;
use Modules\Identity\Exceptions\AuthenticationFailed;
use Modules\Identity\Features\Authentication\Contracts\AuthenticateUser;
use Modules\Identity\Features\Authentication\Contracts\PreAuthThrottle;
use Modules\Identity\Features\Sessions\Contracts\SessionTransport;
use Modules\Identity\Features\Sessions\Handler\SessionHandler;
use Modules\Identity\Features\Totp\Handler\TotpHandler;
use Modules\Identity\Infrastructure\Outbox\IdentityOutbox;
use Modules\Identity\Infrastructure\Security\PasswordHasher;
use stdClass;

final class AuthenticationHandler implements AuthenticateUser
{
    private readonly PreAuthThrottle $preAuthThrottle;

    public function __construct(
        private readonly PasswordHasher $hasher,
        private readonly SessionHandler $sessions,
        private readonly TotpHandler $totp,
        private readonly IdentityOutbox $outbox,
        PreAuthThrottle $preAuthThrottle,
    ) {
        $this->preAuthThrottle = $preAuthThrottle;
    }

    /** @param array<string, mixed> $metadata */
    public function authenticate(string $username, string $password, ?string $totpCode = null, array $metadata = [], ?string $source = null): SessionTransport
    {
        $normalizedUsername = UserAccount::normalizeUsername($username);
        $source = $this->source($source, $metadata);
        $preAuthDecision = $this->preAuthThrottle->attempt($source, $normalizedUsername);
        $result = DB::transaction(function () use ($normalizedUsername, $password, $totpCode, $metadata, $source, $preAuthDecision): ?SessionTransport {
            if (! $preAuthDecision->allowed) {
                $this->hasher->check($password, $this->hasher->dummyHash());

                $failureCode = $preAuthDecision->scope === 'account' ? 'account_rate_limited' : 'source_rate_limited';

                return $this->genericFailure($source, $normalizedUsername, $failureCode, null);
            }

            $user = DB::table('users')->where('username', $normalizedUsername)->lockForUpdate()->first([
                'id', 'username', 'status', 'password_version', 'failed_login_count', 'lockout_level', 'locked_until', 'is_admin',
            ]);
            if (! $user instanceof stdClass) {
                $this->hasher->check($password, $this->hasher->dummyHash());

                return $this->genericFailure($source, $normalizedUsername, 'invalid_credentials', null);
            }

            $now = CarbonImmutable::now('UTC');
            if ($user->locked_until !== null && CarbonImmutable::parse($user->locked_until, 'UTC')->lessThanOrEqualTo($now)) {
                DB::table('users')->where('id', $user->id)->update([
                    'failed_login_count' => 0,
                    'locked_until' => null,
                    // The lockout timer expired: restore an administratively
                    // locked account to active so the correct password works.
                    // lockout_level is preserved so the next lockout
                    // escalates to the next duration instead of restarting.
                    'status' => $user->status === 'locked' ? 'active' : $user->status,
                    'updated_at' => $now,
                ]);
                $user->failed_login_count = 0;
                $user->locked_until = null;
                if ($user->status === 'locked') {
                    $user->status = 'active';
                }
            }
            $credential = null;
            $passwordVerified = false;
            $legacyCredential = false;
            if ($user->locked_until === null && $user->status === 'active') {
                $credential = DB::table('credentials')->where('user_id', $user->id)->first(['id', 'password_hash']);
                $legacyCredential = $credential instanceof stdClass
                    && (! str_starts_with((string) $credential->password_hash, '$argon2id$')
                        || $this->hasher->needsRehash((string) $credential->password_hash));
                $verificationHash = $credential instanceof stdClass && ! $legacyCredential
                    ? (string) $credential->password_hash
                    : $this->hasher->dummyHash();
                $passwordVerified = $this->hasher->check($password, $verificationHash);
            } else {
                $passwordVerified = $this->hasher->check($password, $this->hasher->dummyHash());
            }
            if ($user->locked_until !== null || $user->status !== 'active') {
                return $this->genericFailure($source, $normalizedUsername, 'account_unavailable', (string) $user->id);
            }

            if (! $credential instanceof stdClass || $legacyCredential || ! $passwordVerified) {
                if ($credential instanceof stdClass) {
                    $this->recordCredentialFailure($user);
                }
                $failureCode = $legacyCredential ? 'credential_recovery_required' : 'invalid_credentials';

                return $this->genericFailure($source, $normalizedUsername, $failureCode, (string) $user->id);
            }

            $isAdmin = (bool) $user->is_admin;
            if ($isAdmin && ! $this->totp->isSatisfied((string) $user->id)) {
                return $this->genericFailure($source, $normalizedUsername, 'mfa_required', (string) $user->id);
            }
            $mfaVerified = ! $isAdmin;
            if ($isAdmin) {
                $mfaVerified = $this->totp->verify((string) $user->id, (string) ($totpCode ?? ''));
                if (! $mfaVerified) {
                    return $this->genericFailure($source, $normalizedUsername, 'mfa_failed', (string) $user->id);
                }
            }

            DB::table('users')->where('id', $user->id)->update([
                'last_login_at' => $now,
                'failed_login_count' => 0,
                'lockout_level' => 0,
                'locked_until' => null,
                'updated_at' => $now,
            ]);
            $this->preAuthThrottle->clear($source, $normalizedUsername);
            $session = $this->sessions->issueWithinTransaction((string) $user->id, $metadata, $mfaVerified);
            $this->outbox->insertSecurityEvent('authentication_succeeded', (string) $user->id, [
                'user_id' => (string) $user->id,
                'session_id' => $session->sessionId,
            ]);

            return $session;
        });
        if (! $result instanceof SessionTransport) {
            throw new AuthenticationFailed;
        }

        return $result;
    }

    private function recordCredentialFailure(stdClass $user): void
    {
        $count = (int) $user->failed_login_count + 1;
        $threshold = max(1, (int) config('identity.pre_auth_throttle.account_lock_threshold', 5));
        $values = [
            'failed_login_count' => $count,
            'updated_at' => now(),
        ];
        if ($count < $threshold) {
            DB::table('users')->where('id', $user->id)->update($values);

            return;
        }

        $level = (int) $user->lockout_level + 1;
        $durations = array_values(array_map('intval', config('identity.pre_auth_throttle.account_lock_durations_minutes', [15, 30, 60, 120])));
        $duration = max(1, $durations[min(max(0, $level - 1), max(0, count($durations) - 1))] ?? 15);
        DB::table('users')->where('id', $user->id)->update([
            ...$values,
            'status' => 'locked',
            'lockout_level' => $level,
            'locked_until' => CarbonImmutable::now('UTC')->addMinutes($duration),
        ]);
        // A locked account must not keep live sessions: revoke them now so
        // existing tokens stop working immediately.
        DB::table('identity_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->update([
            'revoked_at' => CarbonImmutable::now('UTC'),
            'updated_at' => now(),
        ]);
        $this->outbox->insertSecurityEvent('account_login_locked', (string) $user->id, [
            'user_id' => (string) $user->id,
            'lockout_level' => $level,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function source(?string $source, array $metadata): string
    {
        $candidate = $source ?? (is_scalar($metadata['source'] ?? null) ? (string) $metadata['source'] : 'unknown');

        return trim($candidate) !== '' ? trim($candidate) : 'unknown';
    }

    private function genericFailure(
        string $source,
        string $normalizedUsername,
        string $failureCode,
        ?string $userId,
    ): null {
        $aggregateId = $userId ?? Str::uuid7()->toString();
        $this->outbox->insertSecurityEvent('authentication_failed', $aggregateId, [
            'user_id' => $userId,
            'failure_code' => $failureCode,
            'source_hash' => hash('sha256', $source),
            'username_hash' => hash('sha256', $normalizedUsername),
        ]);

        return null;
    }
}
