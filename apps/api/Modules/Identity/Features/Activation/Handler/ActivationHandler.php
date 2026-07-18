<?php

namespace Modules\Identity\Features\Activation\Handler;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Features\Activation\Contracts\IssueActivationToken;
use Modules\Identity\Features\Credentials\Handler\CredentialHandler;
use Modules\Identity\Infrastructure\Outbox\IdentityOutbox;
use stdClass;

final class ActivationHandler implements IssueActivationToken
{
    public function __construct(
        private readonly IdentityOutbox $outbox,
        private readonly CredentialHandler $credentials,
    ) {}

    /** @return array{user_id: string, token: string, expires_at: string} */
    public function issue(string $userId): array
    {
        return DB::transaction(function () use ($userId): array {
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id', 'status']);
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

            return [
                'user_id' => $userId,
                'token' => $token,
                'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
            ];
        });
    }

    /** @return array{user_id: string, password_version: int} */
    public function activate(string $token, string $password): array
    {
        return $this->credentials->activateWithToken($token, $password);
    }
}
