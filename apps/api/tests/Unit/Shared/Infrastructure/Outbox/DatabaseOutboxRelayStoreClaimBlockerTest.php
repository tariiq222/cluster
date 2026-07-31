<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Shared\Infrastructure\Outbox\DatabaseOutboxRelayStore;
use Tests\TestCase;

/**
 * Documents the unrecoverable failure mode that requires a schema migration
 * (or external coordination) to resolve safely.
 *
 * Without a `claim_id` / `lease_until` column on outbox_events — which is
 * explicitly out of scope for this round ("لا schema migration") — a worker
 * crash between claim and XADD leaves the row stuck at delivery_attempts = 1.
 * Future relay iterations cannot claim it, so the event is silently lost
 * from the XADD pipeline until an operator intervenes.
 *
 * This is NOT a bug introduced by the claim API; it is a deliberate trade-off
 * documented in the relay migration plan. The test is pinned as failing so
 * the gap stays visible in CI rather than being rediscovered later.
 *
 * @see \Shared\Infrastructure\Outbox\Relay\RedisOutboxRelay for the current
 *      safe-in-the-XADD-path relay used in this round.
 */
#[CoversClass(DatabaseOutboxRelayStore::class)]
final class DatabaseOutboxRelayStoreClaimBlockerTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT_ID = '0197f0e0-0000-7000-8000-000000000b01';

    private const AGGREGATE_ID = '0197f0e0-0000-7000-8000-000000000b02';

    private const EVENT_TYPE = 'com.cluster.outbox.claim-blocker.v1';

    public function test_blocker_claim_orphans_row_on_worker_crash_between_claim_and_xadd(): void
    {
        $this->insertPendingRow(self::EVENT_ID);

        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $this->assertTrue($store->claim(self::EVENT_ID), 'first worker claims the row');

        $this->assertTrue(
            $store->claim(self::EVENT_ID),
            'BLOCKER: Without a lease_until column on outbox_events, a worker crash '
            .'between claim and XADD orphans the row. The next worker cannot reclaim '
            .'it because delivery_attempts > 0. The event is lost from the XADD '
            .'pipeline until an operator resets the row. '
            .'Options: (a) add lease_until + reaper, (b) Redis SETNX lock, '
            .'(c) deploy a single relay instance per cluster. '
            .'The single-instance deployment is the only safe option in this round.',
        );
    }

    private function insertPendingRow(string $eventId): void
    {
        DB::table('outbox_events')->insert([
            'event_id' => $eventId,
            'aggregate_id' => self::AGGREGATE_ID,
            'event_type' => self::EVENT_TYPE,
            'cloud_event' => json_encode([
                'specversion' => '1.0',
                'id' => $eventId,
                'source' => '/outbox',
                'type' => self::EVENT_TYPE,
                'subject' => '/'.self::AGGREGATE_ID,
                'time' => '2026-07-30T09:00:00Z',
                'datacontenttype' => 'application/json',
                'data' => ['value' => 'claim-blocker-fixture'],
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => '2026-07-30 09:00:00',
            'published_at' => null,
            'delivery_attempts' => 0,
            'created_at' => '2026-07-30 09:00:00',
            'updated_at' => '2026-07-30 09:00:00',
        ]);
    }
}
