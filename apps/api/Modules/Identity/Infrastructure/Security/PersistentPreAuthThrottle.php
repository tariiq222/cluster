<?php

namespace Modules\Identity\Infrastructure\Security;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Identity\Features\Authentication\Contracts\PreAuthThrottle;
use Modules\Identity\Features\Authentication\Contracts\PreAuthThrottleDecision;
use RuntimeException;
use stdClass;

final class PersistentPreAuthThrottle implements PreAuthThrottle
{
    public function attempt(string $source, string $normalizedUsername): PreAuthThrottleDecision
    {
        return DB::transaction(function () use ($source, $normalizedUsername): PreAuthThrottleDecision {
            $now = CarbonImmutable::now('UTC');
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
        return max(1, (int) config('identity.pre_auth_throttle.source_username_max_attempts', 4));
    }

    private function accountLimit(): int
    {
        return max(1, (int) config('identity.pre_auth_throttle.account_max_attempts', 20));
    }

    private function windowSeconds(): int
    {
        return max(1, (int) config('identity.pre_auth_throttle.window_seconds', 60));
    }

    private function lockDuration(int $lockLevel): int
    {
        $durations = array_values(array_map('intval', config('identity.pre_auth_throttle.lock_durations_minutes', [15, 30, 60, 120])));

        return max(1, $durations[min(max(0, $lockLevel - 1), max(0, count($durations) - 1))] ?? 15);
    }

    private function windowEnd(stdClass $row, CarbonImmutable $now): CarbonImmutable
    {
        $end = CarbonImmutable::parse($row->window_started_at, 'UTC')->addSeconds($this->windowSeconds());

        return $end->greaterThan($now) ? $end : $now;
    }
}
