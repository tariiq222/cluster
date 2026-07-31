<?php

declare(strict_types=1);

namespace Shared\Contracts;

/**
 * Outbox relay store contract with an atomic, exclusive claim semantic that
 * survives worker crashes and supports safe horizontal scaling.
 *
 * The claim is a compare-and-set on the row's `claim_owner` and
 * `lease_expires_at` columns. A worker wins the claim only when the row is
 * either unclaimed or held by a stale (expired) lease, and the lease is
 * always refreshed in the same UPDATE so a lost heart-beat is recoverable
 * by the reaper rather than by a second worker stealing the claim while
 * the first is still alive.
 *
 * The relay MUST XADD only when `claim` returns `true`, MUST call `release`
 * with the same `workerId` when XADD throws, and MUST NOT race another
 * worker that has won a fresh claim. `reapAbandonedClaims` is the operator
 * hook for crashed workers and is safe to call from any process.
 */
interface ClaimableOutboxRelayStore extends OutboxRelayStore
{
    public function claim(string $eventId, string $workerId, int $leaseSeconds): bool;

    public function release(string $eventId, string $workerId): void;

    public function reapAbandonedClaims(\DateTimeInterface $now): int;
}
