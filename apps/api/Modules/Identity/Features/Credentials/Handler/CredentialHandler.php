<?php

namespace Modules\Identity\Features\Credentials\Handler;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Domain\PasswordPolicy;
use Modules\Identity\Exceptions\AuthenticationFailed;
use Modules\Identity\Exceptions\WeakPassword;
use Modules\Identity\Features\Credentials\Contracts\ChangePassword;
use Modules\Identity\Features\Sessions\Handler\SessionHandler;
use Modules\Identity\Infrastructure\Outbox\IdentityOutbox;
use Modules\Identity\Infrastructure\Security\PasswordHasher;
use stdClass;

final class CredentialHandler implements ChangePassword
{
    public function __construct(
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
        private readonly SessionHandler $sessions,
        private readonly IdentityOutbox $outbox,
    ) {}

    /** @return array{user_id: string, password_version: int} */
    public function activateWithToken(string $token, string $password): array
    {
        return DB::transaction(function () use ($token, $password): array {
            $activation = DB::table('identity_activation_tokens')
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();
            if (! $activation instanceof stdClass || $activation->used_at !== null
                || CarbonImmutable::parse($activation->expires_at, 'UTC')->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                throw new AuthenticationFailed;
            }
            $user = DB::table('users')->where('id', $activation->user_id)->lockForUpdate()->first(['id', 'username', 'status', 'password_version']);
            if (! $user instanceof stdClass || $user->status !== 'pending'
                || DB::table('credentials')->where('user_id', $user->id)->exists()) {
                throw new AuthenticationFailed;
            }
            $this->policy->assertValid($password, (string) $user->username);
            $hash = $this->hasher->hash($password);
            $now = CarbonImmutable::now('UTC');
            $version = max(1, (int) $user->password_version);
            DB::table('credentials')->insert([
                'id' => Str::uuid7()->toString(),
                'user_id' => $user->id,
                'password_hash' => $hash,
                'hash_algorithm' => $this->hasher->algorithm(),
                'password_changed_at' => $now,
                'policy_version' => (string) config('identity.password.policy_version', 'identity-password-v1'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('identity_password_history')->insert([
                'user_id' => $user->id,
                'password_hash' => $hash,
                'hash_algorithm' => $this->hasher->algorithm(),
                'password_version' => $version,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('identity_activation_tokens')->where('id', $activation->id)->update([
                'used_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('users')->where('id', $user->id)->update([
                'status' => 'active',
                'must_change_password' => false,
                'password_version' => $version,
                'updated_at' => $now,
            ]);
            $this->outbox->insertSecurityEvent('credential_created', (string) $user->id, [
                'user_id' => (string) $user->id,
                'password_version' => $version,
            ]);
            $this->outbox->insertSecurityEvent('account_activated', (string) $user->id, [
                'user_id' => (string) $user->id,
            ]);

            return ['user_id' => (string) $user->id, 'password_version' => $version];
        });
    }

    public function change(string $userId, string $currentPassword, string $newPassword): void
    {
        DB::transaction(function () use ($userId, $currentPassword, $newPassword): void {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id', 'username', 'status', 'password_version']);
            $credential = DB::table('credentials')->where('user_id', $userId)->lockForUpdate()->first(['password_hash']);
            if (! $user instanceof stdClass || ! $credential instanceof stdClass || $user->status !== 'active'
                || ! $this->hasher->check($currentPassword, (string) $credential->password_hash)) {
                throw new AuthenticationFailed;
            }
            $this->policy->assertValid($newPassword, (string) $user->username);
            $historySize = max(1, (int) config('identity.password.history_size', 5));
            $history = DB::table('identity_password_history')
                ->where('user_id', $userId)
                ->orderByDesc('id')
                ->limit($historySize)
                ->get(['password_hash']);
            foreach ($history as $previous) {
                if ($this->hasher->check($newPassword, (string) $previous->password_hash)) {
                    throw new WeakPassword(['password_reused']);
                }
            }

            $now = CarbonImmutable::now('UTC');
            $newVersion = (int) $user->password_version + 1;
            $newHash = $this->hasher->hash($newPassword);
            DB::table('identity_password_history')->insert([
                'user_id' => $userId,
                'password_hash' => (string) $credential->password_hash,
                'hash_algorithm' => $this->hasher->algorithm(),
                'password_version' => (int) $user->password_version,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('credentials')->where('user_id', $userId)->update([
                'password_hash' => $newHash,
                'hash_algorithm' => $this->hasher->algorithm(),
                'password_changed_at' => $now,
                'policy_version' => (string) config('identity.password.policy_version', 'identity-password-v1'),
                'updated_at' => $now,
            ]);
            DB::table('users')->where('id', $userId)->update([
                'password_version' => $newVersion,
                'must_change_password' => false,
                'updated_at' => $now,
            ]);
            $this->sessions->revokeAllWithinTransaction($userId, 'password_change');
            $this->outbox->insertSecurityEvent('password_changed', $userId, [
                'user_id' => $userId,
                'password_version' => $newVersion,
            ]);
        });
    }

    public function hasCredential(string $userId): bool
    {
        return DB::table('credentials')->where('user_id', $userId)->exists();
    }
}
