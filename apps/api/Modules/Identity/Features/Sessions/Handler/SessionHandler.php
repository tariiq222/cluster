<?php

namespace Modules\Identity\Features\Sessions\Handler;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Identity\Exceptions\AuthenticationFailed;
use Modules\Identity\Features\Sessions\Contracts\ResolveSession;
use Modules\Identity\Features\Sessions\Contracts\SessionTransport;
use Modules\Identity\Features\Sessions\Contracts\TrustedRequestBindingContext;
use Modules\Identity\Infrastructure\Outbox\IdentityOutbox;
use stdClass;
use Symfony\Component\HttpFoundation\Cookie;

final class SessionHandler implements ResolveSession
{
    public function __construct(private readonly IdentityOutbox $outbox) {}

    /** @param array<string, mixed> $metadata */
    public function issue(string $userId, array $metadata = [], bool $mfaVerified = false): SessionTransport
    {
        return DB::transaction(fn (): SessionTransport => $this->issueWithinTransaction($userId, $metadata, $mfaVerified));
    }

    /** @param array<string, mixed> $metadata */
    public function issueWithinTransaction(string $userId, array $metadata = [], bool $mfaVerified = false): SessionTransport
    {
        $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first([
            'id', 'status', 'password_version', 'is_admin', 'must_change_password', 'locked_until',
        ]);
        if (! $user instanceof stdClass || $user->status !== 'active'
            || ($user->locked_until !== null && CarbonImmutable::parse($user->locked_until, 'UTC')->greaterThan(CarbonImmutable::now('UTC')))) {
            throw new AuthenticationFailed;
        }
        if (! DB::table('credentials')->where('user_id', $userId)->exists()) {
            throw new AuthenticationFailed;
        }
        if ((bool) $user->is_admin && ! $this->adminMfaSatisfied($userId, $mfaVerified)) {
            throw new AuthenticationFailed;
        }

        $now = CarbonImmutable::now('UTC');
        $expiresAt = $now->addMinutes(max(1, (int) config('identity.session.ttl_minutes', 480)));
        $sessionId = Str::uuid7()->toString();
        $rawSessionToken = bin2hex(random_bytes(32));
        $rawCsrfToken = bin2hex(random_bytes(32));
        $safeMetadata = $this->safeMetadata($metadata);
        $restricted = (bool) $user->must_change_password;
        if ($restricted) {
            $safeMetadata['session_restriction'] = 'password_change_only';
        }

        $activeSessions = DB::table('identity_sessions')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now)
            ->orderBy('issued_at')
            ->lockForUpdate()
            ->get(['id']);
        $maximum = max(1, (int) config('identity.session.max_concurrent', 3));
        foreach ($activeSessions->take(max(0, $activeSessions->count() - $maximum + 1)) as $oldest) {
            DB::table('identity_sessions')->where('id', $oldest->id)->update([
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('identity_sessions')->insert([
            'id' => $sessionId,
            'user_id' => $userId,
            'token_hash' => hash('sha256', $rawSessionToken),
            'csrf_token_hash' => hash('sha256', $rawCsrfToken),
            'password_version' => (int) $user->password_version,
            'issued_at' => $now,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'last_seen_at' => null,
            'metadata' => json_encode($safeMetadata, JSON_THROW_ON_ERROR),
            'mfa_verified' => $mfaVerified,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->outbox->insertSecurityEvent('session_created', $userId, [
            'user_id' => $userId,
            'session_id' => $sessionId,
        ]);

        return new SessionTransport(
            userId: $userId,
            sessionId: $sessionId,
            csrfToken: $rawCsrfToken,
            expiresAt: $expiresAt,
            cookie: new Cookie(
                (string) config('identity.session.cookie', 'cluster_identity_session'),
                $rawSessionToken,
                $expiresAt,
                (string) config('identity.session.path', '/'),
                null,
                (bool) config('identity.session.secure', true),
                (bool) config('identity.session.http_only', true),
                false,
                (string) config('identity.session.same_site', 'lax'),
            ),
            restricted: $restricted,
        );
    }

    /** @return array{user_id: string, session_id: string, csrf_token_hash: string|null, restricted: bool}|null */
    public function resolve(string $rawSessionToken, TrustedRequestBindingContext $context): ?array
    {
        if ($rawSessionToken === '' || ! $context->isUsable()) {
            return null;
        }

        return DB::transaction(function () use ($rawSessionToken, $context): ?array {
            $session = DB::table('identity_sessions as sessions')
                ->join('users', 'users.id', '=', 'sessions.user_id')
                ->where('sessions.token_hash', hash('sha256', $rawSessionToken))
                ->lockForUpdate()
                ->first([
                    'sessions.id', 'sessions.user_id', 'sessions.csrf_token_hash', 'sessions.password_version',
                    'sessions.issued_at', 'sessions.expires_at', 'sessions.last_seen_at', 'sessions.revoked_at',
                    'sessions.metadata', 'sessions.mfa_verified', 'users.status',
                    'users.password_version as current_password_version', 'users.is_admin', 'users.must_change_password',
                ]);
            if (! $session instanceof stdClass) {
                return null;
            }

            $now = CarbonImmutable::now('UTC');
            $expired = $session->revoked_at !== null
                || CarbonImmutable::parse($session->expires_at, 'UTC')->lessThanOrEqualTo($now)
                || CarbonImmutable::parse($session->last_seen_at ?? $session->issued_at, 'UTC')
                    ->addMinutes((int) config('identity.session.idle_minutes', 30))->lessThanOrEqualTo($now);
            $metadata = json_decode((string) $session->metadata, true);
            $restricted = is_array($metadata) && ($metadata['session_restriction'] ?? null) === 'password_change_only';
            $bindingMatches = is_array($metadata)
                && is_string($metadata['ip_cidr'] ?? null)
                && is_string($metadata['user_agent_hash'] ?? null)
                && hash_equals($metadata['ip_cidr'], $context->ipCidr)
                && hash_equals($metadata['user_agent_hash'], $context->userAgentHash);
            $invalid = $session->status !== 'active'
                || (int) $session->password_version !== (int) $session->current_password_version
                || ((bool) $session->is_admin && ! (bool) $session->mfa_verified)
                || ((bool) $session->must_change_password && ! $restricted)
                || ! $bindingMatches;
            if (! DB::table('credentials')->where('user_id', $session->user_id)->exists()) {
                $invalid = true;
            }
            if ((bool) $session->is_admin) {
                $totp = DB::table('identity_totp')->where('user_id', $session->user_id)->first(['required', 'enabled']);
                $invalid = $invalid || ! ($totp instanceof stdClass) || ! (bool) $totp->required || ! (bool) $totp->enabled;
            }
            if ($expired || $invalid) {
                if ($session->revoked_at === null) {
                    DB::table('identity_sessions')->where('id', $session->id)->update([
                        'revoked_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $this->outbox->insertSecurityEvent('session_revoked', (string) $session->user_id, [
                        'user_id' => (string) $session->user_id,
                        'session_id' => (string) $session->id,
                        'reason_code' => ! $bindingMatches ? 'binding_mismatch' : 'session_expired',
                    ]);
                }

                return null;
            }
            DB::table('identity_sessions')->where('id', $session->id)->update([
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'user_id' => (string) $session->user_id,
                'session_id' => (string) $session->id,
                'csrf_token_hash' => is_string($session->csrf_token_hash) ? $session->csrf_token_hash : null,
                'restricted' => $restricted,
            ];
        });
    }

    public function validateCsrf(string $rawSessionToken, string $rawCsrfToken, TrustedRequestBindingContext $context): bool
    {
        $session = $this->resolve($rawSessionToken, $context);
        if ($session === null || $rawCsrfToken === '' || ! is_string($session['csrf_token_hash'])) {
            return false;
        }

        return hash_equals($session['csrf_token_hash'], hash('sha256', $rawCsrfToken));
    }

    public function rotateCsrf(string $sessionId): string
    {
        return DB::transaction(function () use ($sessionId): string {
            $token = bin2hex(random_bytes(32));
            $updated = DB::table('identity_sessions')->where('id', $sessionId)->whereNull('revoked_at')->update([
                'csrf_token_hash' => hash('sha256', $token),
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new AuthenticationFailed;
            }
            return $token;
        });
    }

    public function revoke(string $sessionId, string $reasonCode = 'manual_logout'): void
    {
        DB::transaction(function () use ($sessionId, $reasonCode): void {
            $session = DB::table('identity_sessions')->where('id', $sessionId)->lockForUpdate()->first(['id', 'user_id', 'revoked_at']);
            if (! $session instanceof stdClass || $session->revoked_at !== null) {
                return;
            }
            DB::table('identity_sessions')->where('id', $sessionId)->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
            $this->outbox->insertSecurityEvent('session_revoked', (string) $session->user_id, [
                'user_id' => (string) $session->user_id,
                'session_id' => $sessionId,
                'reason_code' => $reasonCode,
            ]);
        });
    }

    public function revokeAll(string $userId, string $reasonCode = 'security_change'): void
    {
        DB::transaction(fn (): int => $this->revokeAllWithinTransaction($userId, $reasonCode));
    }

    public function revokeAllWithinTransaction(string $userId, string $reasonCode = 'security_change'): int
    {
        $count = DB::table('identity_sessions')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        if ($count > 0) {
            $this->outbox->insertSecurityEvent('sessions_revoked', $userId, [
                'user_id' => $userId,
                'reason_code' => $reasonCode,
            ]);
        }

        return $count;
    }

    /** @param array<string, mixed> $metadata @return array<string, scalar|null> */
    private function safeMetadata(array $metadata): array
    {
        $safe = [];
        foreach (['ip_cidr', 'user_agent_hash', 'capability_version', 'device_id'] as $key) {
            if (isset($metadata[$key]) && is_scalar($metadata[$key])) {
                $safe[$key] = (string) $metadata[$key];
            }
        }

        return $safe;
    }

    private function adminMfaSatisfied(string $userId, bool $mfaVerified): bool
    {
        if (! $mfaVerified) {
            return false;
        }
        $state = DB::table('identity_totp')->where('user_id', $userId)->first(['required', 'enabled']);

        return $state instanceof stdClass && (bool) $state->required && (bool) $state->enabled;
    }
}
