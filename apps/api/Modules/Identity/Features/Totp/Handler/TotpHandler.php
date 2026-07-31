<?php

namespace Modules\Identity\Features\Totp\Handler;

use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Infrastructure\Outbox\IdentityOutbox;
use stdClass;

final class TotpHandler
{
    public function __construct(private readonly IdentityOutbox $outbox) {}

    /** @return array{user_id: string, secret: string, otpauth_uri: string} */
    public function enroll(string $userId): array
    {
        return DB::transaction(function () use ($userId): array {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id', 'username', 'is_admin', 'mfa_required']);
            if (! $user instanceof stdClass || (! (bool) $user->is_admin && ! (bool) $user->mfa_required)) {
                throw new DomainException('totp_not_required');
            }

            $secret = $this->base32Encode(random_bytes(20));
            DB::table('identity_totp')->updateOrInsert(
                ['user_id' => $userId],
                [
                    'required' => true,
                    'enabled' => false,
                    'secret_ciphertext' => Crypt::encryptString($secret),
                    'confirmed_at' => null,
                    'last_used_step' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $this->outbox->insertSecurityEvent('totp_enrollment_started', $userId, [
                'user_id' => $userId,
            ]);

            return [
                'user_id' => $userId,
                'secret' => $secret,
                'otpauth_uri' => $this->otpauthUri((string) $user->username, $secret),
            ];
        });
    }

    public function confirm(string $userId, string $code): bool
    {
        return DB::transaction(function () use ($userId, $code): bool {
            $state = DB::table('identity_totp')->where('user_id', $userId)->lockForUpdate()->first();
            if (! $state instanceof stdClass || ! (bool) $state->required || ! is_string($state->secret_ciphertext)) {
                return false;
            }

            try {
                $secret = Crypt::decryptString($state->secret_ciphertext);
            } catch (DecryptException) {
                return false;
            }
            if ((bool) $state->enabled) {
                return false;
            }
            $match = $this->matchingStep($secret, $code, time());
            if ($match === null) {
                return false;
            }

            DB::table('identity_totp')->where('user_id', $userId)->update([
                'enabled' => true,
                'confirmed_at' => now(),
                'last_used_step' => $match,
                'updated_at' => now(),
            ]);
            $this->outbox->insertSecurityEvent('totp_enabled', $userId, ['user_id' => $userId]);

            return true;
        });
    }

    public function isSatisfied(string $userId): bool
    {
        $user = DB::table('users')->where('id', $userId)->first(['is_admin', 'mfa_required']);
        if (! $user instanceof stdClass || (! (bool) $user->is_admin && ! (bool) $user->mfa_required)) {
            return true;
        }

        $state = DB::table('identity_totp')->where('user_id', $userId)->first(['required', 'enabled']);

        return $state instanceof stdClass && (bool) $state->required && (bool) $state->enabled;
    }

    public function verify(string $userId, string $code): bool
    {
        return DB::transaction(function () use ($userId, $code): bool {
            $state = DB::table('identity_totp')->where('user_id', $userId)->lockForUpdate()->first();
            if (! $state instanceof stdClass || ! (bool) $state->required || ! (bool) $state->enabled || ! is_string($state->secret_ciphertext)) {
                return false;
            }
            try {
                $secret = Crypt::decryptString($state->secret_ciphertext);
            } catch (DecryptException) {
                return false;
            }

            $match = $this->matchingStep($secret, $code, time());
            if ($match === null || ($state->last_used_step !== null && $match <= (int) $state->last_used_step)) {
                return false;
            }
            DB::table('identity_totp')->where('user_id', $userId)->update([
                'last_used_step' => $match,
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    private function matchingStep(string $secret, string $code, int $timestamp): ?int
    {
        if (preg_match('/\A[0-9]{'.(int) config('identity.totp.digits', 6).'}\z/', $code) !== 1) {
            return null;
        }
        $period = max(1, (int) config('identity.totp.period_seconds', 30));
        $step = intdiv($timestamp, $period);
        $window = max(0, (int) config('identity.totp.window', 1));
        for ($offset = -$window; $offset <= $window; $offset++) {
            $candidate = $step + $offset;
            if ($candidate >= 0 && hash_equals($this->codeForStep($secret, $candidate), $code)) {
                return $candidate;
            }
        }

        return null;
    }

    private function codeForStep(string $secret, int $step): string
    {
        $counter = pack('N2', intdiv($step, 4294967296), $step & 0xFFFFFFFF);
        $digest = hash_hmac('sha1', $counter, $this->base32Decode($secret), true);
        $offset = ord($digest[19]) & 0x0F;
        $binary = ((ord($digest[$offset]) & 0x7F) << 24)
            | (ord($digest[$offset + 1]) << 16)
            | (ord($digest[$offset + 2]) << 8)
            | ord($digest[$offset + 3]);
        $modulo = 10 ** (int) config('identity.totp.digits', 6);

        return str_pad((string) ($binary % $modulo), (int) config('identity.totp.digits', 6), '0', STR_PAD_LEFT);
    }

    private function otpauthUri(string $username, string $secret): string
    {
        $issuer = (string) config('identity.totp.issuer', 'Identity');
        $label = rawurlencode($issuer.':'.$username);

        return 'otpauth://totp/'.$label.'?secret='.rawurlencode($secret).'&issuer='.rawurlencode($issuer)
            .'&algorithm=SHA1&digits='.(int) config('identity.totp.digits', 6)
            .'&period='.(int) config('identity.totp.period_seconds', 30);
    }

    private function base32Encode(string $value): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bits = 0;
        $encoded = '';
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $buffer = ($buffer << 8) | ord($value[$index]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= $alphabet[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $encoded .= $alphabet[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $normalized = strtoupper(rtrim($value, '='));
        $buffer = 0;
        $bits = 0;
        $decoded = '';
        for ($index = 0, $length = strlen($normalized); $index < $length; $index++) {
            $position = strpos($alphabet, $normalized[$index]);
            if ($position === false) {
                throw new DomainException('Invalid TOTP secret.');
            }
            $buffer = ($buffer << 5) | $position;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $decoded;
    }
}
