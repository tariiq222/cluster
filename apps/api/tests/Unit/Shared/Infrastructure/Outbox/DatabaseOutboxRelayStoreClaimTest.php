<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Shared\Infrastructure\Outbox\DatabaseOutboxRelayStore;
use Tests\TestCase;

/**
 * Relay-store contract tests for the worker-claim + lease API.
 *
 * The Shared-owned relay must guarantee that XADD never runs unless the
 * caller has won an exclusive claim on the row, and that a crashed
 * worker's events are reclaimable either by a fresh claim after the
 * lease expires or by an explicit `reapAbandonedClaims` pass.
 */
#[CoversClass(DatabaseOutboxRelayStore::class)]
final class DatabaseOutboxRelayStoreClaimTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT_ID = '0197f0e0-0000-7000-8000-000000000a01';

    private const AGGREGATE_ID = '0197f0e0-0000-7000-8000-000000000a02';

    private const EVENT_TYPE = 'com.cluster.outbox.claim.v1';

    private const WORKER_A = '0197f0e0-0000-7000-8000-000000000aa1';

    private const WORKER_B = '0197f0e0-0000-7000-8000-000000000aa2';

    public function test_claim_returns_true_only_for_the_first_caller_and_records_lease(): void
    {
        $this->insertPendingRow(self::EVENT_ID);
        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $this->assertTrue($store->claim(self::EVENT_ID, self::WORKER_A, 30));
        $row = DB::table('outbox_events')->where('event_id', self::EVENT_ID)->sole();
        $this->assertSame(self::WORKER_A, $row->claim_owner);
        $this->assertNotNull($row->lease_expires_at);
        $this->assertSame(1, (int) $row->delivery_attempts);
    }

    public function test_claim_returns_false_for_a_row_already_claimed_by_another_worker(): void
    {
        $this->insertPendingRow(self::EVENT_ID);
        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $this->assertTrue($store->claim(self::EVENT_ID, self::WORKER_A, 30));
        $this->assertFalse($store->claim(self::EVENT_ID, self::WORKER_B, 30));
        $row = DB::table('outbox_events')->where('event_id', self::EVENT_ID)->sole();
        $this->assertSame(self::WORKER_A, $row->claim_owner, 'loser must not steal the claim');
        $this->assertSame(1, (int) $row->delivery_attempts);
    }

    public function test_claim_is_a_noop_for_a_published_row(): void
    {
        $this->insertPendingRow(self::EVENT_ID);
        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $this->assertTrue($store->markPublished(self::EVENT_ID));
        $this->assertFalse($store->claim(self::EVENT_ID, self::WORKER_A, 30));
    }

    public function test_claim_after_lease_expires_allows_a_new_worker_to_take_over(): void
    {
        $this->insertPendingRow(self::EVENT_ID);
        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $this->assertTrue($store->claim(self::EVENT_ID, self::WORKER_A, 30));
        DB::table('outbox_events')->where('event_id', self::EVENT_ID)->update([
            'lease_expires_at' => now()->subSecond(),
        ]);

        $this->assertTrue(
            $store->claim(self::EVENT_ID, self::WORKER_B, 30),
            'expired lease must free the row so a second worker can take over after a crash',
        );
        $row = DB::table('outbox_events')->where('event_id', self::EVENT_ID)->sole();
        $this->assertSame(self::WORKER_B, $row->claim_owner);
    }

    public function test_release_after_xadd_failure_clears_claim_and_lease(): void
    {
        $this->insertPendingRow(self::EVENT_ID);
        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $this->assertTrue($store->claim(self::EVENT_ID, self::WORKER_A, 30));
        $store->release(self::EVENT_ID, self::WORKER_A);

        $row = DB::table('outbox_events')->where('event_id', self::EVENT_ID)->sole();
        $this->assertNull($row->claim_owner);
        $this->assertNull($row->lease_expires_at);
        $this->assertNull($row->published_at);
        $this->assertTrue($store->claim(self::EVENT_ID, self::WORKER_A, 30));
    }

    public function test_release_is_a_noop_for_a_different_worker(): void
    {
        $this->insertPendingRow(self::EVENT_ID);
        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $store->release(self::EVENT_ID, self::WORKER_A);
        $this->assertSame(0, (int) DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('delivery_attempts'));

        $this->assertTrue($store->claim(self::EVENT_ID, self::WORKER_A, 30));
        $store->release(self::EVENT_ID, self::WORKER_B);
        $row = DB::table('outbox_events')->where('event_id', self::EVENT_ID)->sole();
        $this->assertSame(self::WORKER_A, $row->claim_owner, 'release must not evict the rightful owner');
    }

    public function test_release_is_a_noop_on_a_published_row(): void
    {
        $this->insertPendingRow(self::EVENT_ID);
        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $this->assertTrue($store->markPublished(self::EVENT_ID));
        $store->release(self::EVENT_ID, self::WORKER_A);

        $row = DB::table('outbox_events')->where('event_id', self::EVENT_ID)->sole();
        $this->assertNotNull($row->published_at);
    }

    public function test_reap_abandoned_claims_clears_only_expired_leases(): void
    {
        $this->insertPendingRow(self::EVENT_ID, 'com.cluster.outbox.reap.crashed.v1');
        $liveId = '0197f0e0-0000-7000-8000-000000000a99';
        $liveAggregate = '0197f0e0-0000-7000-8000-000000000a9a';
        $this->insertOutboxRaw(
            $this->cloudEvent($liveId, $liveAggregate, 'com.cluster.outbox.reap.live.v1'),
            'com.cluster.outbox.reap.live.v1',
        );

        $store = $this->app->make(DatabaseOutboxRelayStore::class);
        $this->assertTrue($store->claim(self::EVENT_ID, 'crashed-worker', 30));
        $this->assertTrue($store->claim($liveId, 'live-worker', 30));

        DB::table('outbox_events')->where('event_id', self::EVENT_ID)->update([
            'lease_expires_at' => now()->subMinutes(5),
        ]);

        $reaped = $store->reapAbandonedClaims(now()->copy()->addSeconds(2));

        $this->assertSame(1, $reaped, 'reaper must clear only the expired lease');
        $this->assertNull(DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('claim_owner'));
        $this->assertSame('live-worker', DB::table('outbox_events')->where('event_id', $liveId)->value('claim_owner'));
    }

    public function test_mark_published_clears_claim_and_lease(): void
    {
        $this->insertPendingRow(self::EVENT_ID);
        $store = $this->app->make(DatabaseOutboxRelayStore::class);
        $this->assertTrue($store->claim(self::EVENT_ID, self::WORKER_A, 30));
        $this->assertTrue($store->markPublished(self::EVENT_ID));

        $row = DB::table('outbox_events')->where('event_id', self::EVENT_ID)->sole();
        $this->assertNotNull($row->published_at);
        $this->assertNull($row->claim_owner);
        $this->assertNull($row->lease_expires_at);
        $this->assertFalse($store->markPublished(self::EVENT_ID));
    }

    public function test_record_attempt_is_conditional_on_unpublished(): void
    {
        $this->insertPendingRow(self::EVENT_ID);
        $store = $this->app->make(DatabaseOutboxRelayStore::class);
        $store->recordAttempt(self::EVENT_ID);
        $this->assertSame(1, (int) DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('delivery_attempts'));

        $this->assertTrue($store->markPublished(self::EVENT_ID));
        $store->recordAttempt(self::EVENT_ID);
        $this->assertSame(1, (int) DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('delivery_attempts'));
    }

    private function insertPendingRow(string $eventId, ?string $type = null): void
    {
        $this->insertOutboxRaw($this->cloudEvent($eventId, self::AGGREGATE_ID, $type ?? self::EVENT_TYPE), $type ?? self::EVENT_TYPE);
    }

    /** @param array<string, mixed> $event */
    private function insertOutboxRaw(array $event, string $type): void
    {
        DB::table('outbox_events')->insert([
            'event_id' => $event['id'],
            'aggregate_id' => $event['data']['record']['id'],
            'event_type' => $type,
            'cloud_event' => json_encode($event, JSON_THROW_ON_ERROR),
            'occurred_at' => '2026-07-30 09:00:00',
            'published_at' => null,
            'delivery_attempts' => 0,
            'created_at' => '2026-07-30 09:00:00',
            'updated_at' => '2026-07-30 09:00:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function cloudEvent(string $eventId, string $aggregateId, string $type): array
    {
        return [
            'specversion' => '1.0',
            'id' => $eventId,
            'source' => '/outbox',
            'type' => $type,
            'subject' => '/'.$aggregateId,
            'time' => '2026-07-30T09:00:00Z',
            'datacontenttype' => 'application/json',
            'data' => ['record' => ['id' => $aggregateId]],
        ];
    }
}
