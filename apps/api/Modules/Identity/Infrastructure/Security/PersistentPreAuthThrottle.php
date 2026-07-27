<?php

namespace Modules\Identity\Infrastructure\Security;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Features\Authentication\Contracts\PreAuthThrottle;
use Modules\Identity\Features\Authentication\Contracts\PreAuthThrottleDecision;
use Modules\PlatformSettings\Contracts\GetEffectivePlatformSettings;
use RuntimeException;
use stdClass;
use Throwable;

final class PersistentPreAuthThrottle implements PreAuthThrottle
{
    private const PRUNE_BATCH_SIZE = 200;

    /** @var array<string, int>|null */
    private ?array $cachedSecurity = null;

    public function __construct(private readonly ?GetEffectivePlatformSettings $settings = null) {}

    public function attempt(string $source, string $normalizedUsername): PreAuthThrottleDecision
    {
        return DB::transaction(function () use ($source, $normalizedUsername): PreAuthThrottleDecision {
            $now = CarbonImmutable::now('UTC');
            $this->pruneExpiredRows($now);
            $usernameHash = hash('sha256', $normalizedUsername);
            $sourceUsernameHash = hash('sha256', $source."\0".$normalizedUsername);
            $sourceRow = $this->lockOrCreate('source_username', $sourceUsernameHash, $usernameHash, $now);
            $accountRow = $this->lockOrCreate('account', $usernameHash, $usernameHash, $now);
            if ($sourceRow->blocked_until !== null && CarbonImmutable::parse($sourceRow->blocked_until, 'UTC')->greaterThan($now)) {
                return new PreAuthThrottleDecision(false, 'source_username', CarbonImmutable::parse($sourceRow->blocked_until, 'UTC'), (int) $sourceRow->lock_level);
            }

            $sourceAttempts = $this->activeAttempts($sourceRow, $now);
            $accountAttempts = $this->activeAttempts($accountRow, $now);
            if ($accountAttempts >= $this->accountLimit()) {
                return new PreAuthThrottleDecision(false, 'account', $this->windowEnd($accountRow, $now));
            }
            if ($sourceAttempts >= $this->sourceUsernameLimit()) {
                return new PreAuthThrottleDecision(false, 'source_username', $this->windowEnd($sourceRow, $now), (int) $sourceRow->lock_level);
            }

            $nextSourceAttempts = $sourceAttempts + 1;
            $nextLockLevel = (int) $sourceRow->lock_level;
            $blockedUntil = null;
            if ($nextSourceAttempts >= $this->sourceUsernameLimit()) {
                $nextLockLevel++;
                $blockedUntil = $now->addMinutes($this->lockDuration($nextLockLevel));
            }
            $this->record($sourceRow, $nextSourceAttempts, $now, $nextLockLevel, $blockedUntil);
            $this->record($accountRow, $accountAttempts + 1, $now);

            return new PreAuthThrottleDecision(true, 'none', null, $nextLockLevel);
        }, 3);
    }

    public function clear(string $source, string $normalizedUsername): void
    {
        DB::transaction(function () use ($source, $normalizedUsername): void {
            $now = CarbonImmutable::now('UTC');
            $usernameHash = hash('sha256', $normalizedUsername);
            $sourceUsernameHash = hash('sha256', $source."\0".$normalizedUsername);
            $this->lockOrCreate('source_username', $sourceUsernameHash, $usernameHash, $now);
            $this->lockOrCreate('account', $usernameHash, $usernameHash, $now);
            DB::table('identity_auth_attempt_ledgers')->where('username_hash', $usernameHash)->update([
                'attempt_count' => 0,
                'lock_level' => 0,
                'blocked_until' => null,
                'window_started_at' => $now,
                'last_attempt_at' => null,
                'updated_at' => $now,
            ]);
        }, 3);
    }

    public function retryAfterSeconds(string $source, string $normalizedUsername): ?int
    {
        $now = CarbonImmutable::now('UTC');
        $usernameHash = hash('sha256', $normalizedUsername);
        $sourceUsernameHash = hash('sha256', $source."\0".$normalizedUsername);
        $rows = DB::table('identity_auth_attempt_ledgers')
            ->whereIn('scope_hash', [$sourceUsernameHash, $usernameHash])
            ->get();
        $retryAfter = null;

        foreach ($rows as $row) {
            $retryAt = $row->blocked_until === null
                ? null
                : CarbonImmutable::parse($row->blocked_until, 'UTC');
            if ($retryAt === null || $retryAt->lessThanOrEqualTo($now)) {
                $attempts = $this->activeAttempts($row, $now);
                $limit = $row->scope === 'account' ? $this->accountLimit() : $this->sourceUsernameLimit();
                $retryAt = $attempts >= $limit ? $this->windowEnd($row, $now) : null;
            }
            if ($retryAt === null || $retryAt->lessThanOrEqualTo($now)) {
                continue;
            }

            $seconds = max(1, $retryAt->getTimestamp() - $now->getTimestamp());
            $retryAfter = $retryAfter === null ? $seconds : max($retryAfter, $seconds);
        }

        return $retryAfter;
    }

    private function pruneExpiredRows(CarbonImmutable $now): void
    {
        $cutoff = $now->subSeconds($this->windowSeconds());
        $expired = static function (Builder $query) use ($now, $cutoff): void {
            $query->where('window_started_at', '<=', $cutoff)
                ->where(function (Builder $blocked) use ($now): void {
                    $blocked->whereNull('blocked_until')
                        ->orWhere('blocked_until', '<=', $now);
                });
        };
        $ids = DB::table('identity_auth_attempt_ledgers')
            ->where($expired)
            ->orderBy('id')
            ->limit(self::PRUNE_BATCH_SIZE)
            ->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        DB::table('identity_auth_attempt_ledgers')
            ->whereIn('id', $ids->all())
            ->where($expired)
            ->delete();
    }

    private function lockOrCreate(string $scope, string $scopeHash, string $usernameHash, CarbonImmutable $now): stdClass
    {
        $query = DB::table('identity_auth_attempt_ledgers')
            ->where('scope', $scope)
            ->where('scope_hash', $scopeHash);
        $row = $query->lockForUpdate()->first();
        if (! $row instanceof stdClass) {
            DB::table('identity_auth_attempt_ledgers')->insertOrIgnore([
                'scope' => $scope,
                'scope_hash' => $scopeHash,
                'username_hash' => $usernameHash,
                'window_started_at' => $now,
                'attempt_count' => 0,
                'lock_level' => 0,
                'blocked_until' => null,
                'last_attempt_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $row = $query->lockForUpdate()->first();
        }
        if (! $row instanceof stdClass) {
            throw new RuntimeException('The Identity authentication attempt ledger could not be locked.');
        }

        return $row;
    }

    private function activeAttempts(stdClass $row, CarbonImmutable $now): int
    {
        $windowStartedAt = CarbonImmutable::parse($row->window_started_at, 'UTC');
        if ($windowStartedAt->addSeconds($this->windowSeconds())->lessThanOrEqualTo($now)) {
            return 0;
        }

        return (int) $row->attempt_count;
    }

    private function record(stdClass $row, int $attempts, CarbonImmutable $now, ?int $lockLevel = null, ?CarbonImmutable $blockedUntil = null): void
    {
        $windowStartedAt = CarbonImmutable::parse($row->window_started_at, 'UTC');
        $window = $windowStartedAt->addSeconds($this->windowSeconds())->lessThanOrEqualTo($now) ? $now : $windowStartedAt;
        DB::table('identity_auth_attempt_ledgers')->where('id', $row->id)->update([
            'window_started_at' => $window,
            'attempt_count' => $attempts,
            'lock_level' => $lockLevel ?? (int) $row->lock_level,
            'blocked_until' => $blockedUntil,
            'last_attempt_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function sourceUsernameLimit(): int
    {
        $security = $this->securitySnapshot();
        $limit = (int) ($security['failed_login_attempts'] ?? 0);

        return $limit > 0 ? $limit : max(1, (int) config('identity.pre_auth_throttle.source_username_max_attempts', 4));
    }

    private function accountLimit(): int
    {
        return max(1, (int) config('identity.pre_auth_throttle.account_max_attempts', 20));
    }

    private function windowSeconds(): int
    {
        $security = $this->securitySnapshot();
        $windowMinutes = (int) ($security['failed_login_window_minutes'] ?? 0);
        $seconds = $windowMinutes > 0 ? $windowMinutes * 60 : 0;

        return $seconds > 0 ? $seconds : max(1, (int) config('identity.pre_auth_throttle.window_seconds', 60));
    }

    private function lockDuration(int $lockLevel): int
    {
        $security = $this->securitySnapshot();
        $published = (int) ($security['lockout_minutes'] ?? 0);
        if ($published > 0) {
            return max(1, $published);
        }
        $durations = array_values(array_map('intval', config('identity.pre_auth_throttle.lock_durations_minutes', [15, 30, 60, 120])));

        return max(1, $durations[min(max(0, $lockLevel - 1), max(0, count($durations) - 1))] ?? 15);
    }

    private function securitySnapshot(): array
    {
        if ($this->settings === null) {
            return [];
        }
        if ($this->cachedSecurity !== null) {
            return $this->cachedSecurity;
        }
        try {
            if (! $this->settings->hasPublishedVersion()) {
                $this->cachedSecurity = [];
            } else {
                $current = $this->settings->current();
                $this->cachedSecurity = $current['security'];
            }
        } catch (Throwable) {
            // Retain the last successful snapshot for the object lifetime.
        }

        return $this->cachedSecurity ?? [];
    }

    private function windowEnd(stdClass $row, CarbonImmutable $now): CarbonImmutable
    {
        $end = CarbonImmutable::parse($row->window_started_at, 'UTC')->addSeconds($this->windowSeconds());

        return $end->greaterThan($now) ? $end : $now;
    }
}
