<?php

declare(strict_types=1);

namespace Shared\Contracts;

/**
 * Outbox relay store contract with an atomic, exclusive claim semantic.
 *
 * Claim is a compare-and-set on the existing `delivery_attempts` column:
 * the first caller transitions the row from `delivery_attempts = 0` to
 * `delivery_attempts = 1` and wins; concurrent callers see `false` and
 * must skip XADD. `release` decrements back to 0 so the row remains
 * retryable after a transport failure.
 *
 * KNOWN LIMITATION (documented blocker in this round): because there is
 * no `lease_until` column and no external lock, a worker crash between
 * `claim` and XADD leaves the row stuck at `delivery_attempts = 1` and
 * never re-claimable by another worker. Operators must deploy a single
 * relay instance per cluster until a follow-up migration adds a lease
 * timestamp or a Redis SETNX lock is introduced as the sole arbiter.
 *
 * The relay MUST XADD only when `claim` returns `true`, and MUST call
 * `release` when XADD throws so the row is retryable on the next
 * iteration. `markPublished` remains the final and authoritative step.
 */
interface ClaimableOutboxRelayStore extends OutboxRelayStore
{
    /**
     * Atomically transition the row to the "claimed" state.
     *
     * Returns `true` only if this caller is the sole winner of the
     * exclusive claim. Concurrent callers, callers racing an already-
     * published row, and callers racing a row left claimed by a crashed
     * worker MUST observe `false`.
     */
    public function claim(string $eventId): bool;

    /**
     * Best-effort release of a claim the caller previously won.
     *
     * MUST be a no-op when the row has already been published, when no
     * claim was held by this caller, and when the row no longer exists.
     * MUST NOT mutate `published_at`. MUST NOT decrement past zero.
     */
    public function release(string $eventId): void;
}
