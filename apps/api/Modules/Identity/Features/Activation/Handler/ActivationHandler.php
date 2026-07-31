<?php

namespace Modules\Identity\Features\Activation\Handler;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Exceptions\AuthenticationFailed;
use Modules\Identity\Features\Activation\Contracts\IssueActivationToken;
use Modules\Identity\Features\Credentials\Handler\CredentialHandler;
use Modules\Identity\Features\Totp\Handler\TotpHandler;
use Modules\Identity\Infrastructure\Outbox\IdentityOutbox;
use stdClass;

final class ActivationHandler implements IssueActivationToken
{
    public function __construct(
        private readonly IdentityOutbox $outbox,
        private readonly CredentialHandler $credentials,
        private readonly TotpHandler $totp,
    ) {}

    /** @return array{user_id: string, token: string, expires_at: string, totp_secret?: string, totp_otpauth_uri?: string} */
    public function issue(string $userId): array
    {
        return DB::transaction(function () use ($userId): array {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id', 'status', 'is_admin', 'mfa_required']);
            if (! $user instanceof stdClass || $user->status !== 'pending') {
                throw new DomainException('activation_not_available');
            }
            if (DB::table('credentials')->where('user_id', $userId)->exists()) {
                throw new DomainException('credentials_already_set');
            }

            $now = CarbonImmutable::now('UTC');
            $expiresAt = $now->addMinutes(max(1, (int) config('identity.activation.ttl_minutes', 60)));
            DB::table('identity_activation_tokens')->where('user_id', $userId)->whereNull('used_at')->update([
                'used_at' => $now,
                'updated_at' => $now,
            ]);
            $token = bin2hex(random_bytes(32));
            DB::table('identity_activation_tokens')->insert([
                'id' => Str::uuid7()->toString(),
                'user_id' => $userId,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
                'used_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->outbox->insertSecurityEvent('activation_token_issued', $userId, ['user_id' => $userId]);
            $totpEnrollment = ((bool) $user->is_admin || (bool) $user->mfa_required) ? $this->totp->enroll($userId) : null;

            return [
                'user_id' => $userId,
                'token' => $token,
                'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
                ...($totpEnrollment === null ? [] : [
                    'totp_secret' => $totpEnrollment['secret'],
                    'totp_otpauth_uri' => $totpEnrollment['otpauth_uri'],
                ]),
            ];
        });
    }

    /** @return array{user_id: string, password_version: int} */
    public function activate(string $token, string $password, ?string $totpCode = null): array
    {
        return DB::transaction(function () use ($token, $password, $totpCode): array {
            $activation = DB::table('identity_activation_tokens')
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first(['user_id']);
            if ($activation instanceof stdClass) {
                $user = DB::table('users')->where('id', $activation->user_id)->first(['is_admin', 'mfa_required']);
                $adminLike = $user instanceof stdClass && ((bool) $user->is_admin || (bool) $user->mfa_required);
                if ($adminLike) {
                    $totpEnabled = DB::table('identity_totp')->where('user_id', $activation->user_id)->value('enabled');
                    $totpAccepted = (bool) $totpEnabled
                        ? $this->totp->verify((string) $activation->user_id, (string) $totpCode)
                        : $this->totp->confirm((string) $activation->user_id, (string) $totpCode);
                    if (! $totpAccepted) {
                        throw new AuthenticationFailed;
                    }
                }
            }

            return $this->credentials->activateWithToken($token, $password);
        });
    }
}
