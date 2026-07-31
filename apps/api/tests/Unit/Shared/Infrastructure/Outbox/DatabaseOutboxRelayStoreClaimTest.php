<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Outbox;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use Shared\Infrastructure\Outbox\DatabaseOutboxRelayStore;
use Tests\TestCase;

/**
 * Relay-store contract tests for the atomic claim/release API.
 *
 * The Shared-owned relay must guarantee that XADD never runs unless the
 * caller has won an exclusive claim on the row, and that a failed XADD
 * releases the claim so the next relay iteration can retry. These tests
 * intentionally exercise the storage adapter directly (not the relay) so
 * the atomicity properties are pinned to the persistence boundary.
 *
 * @see \Shared\Infrastructure\Outbox\Relay\RedisOutboxRelay for the relay-side
 *      tests that verify the relay refuses to XADD when claim fails.
 */
#[CoversClass(DatabaseOutboxRelayStore::class)]
final class DatabaseOutboxRelayStoreClaimTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT_ID = '0197f0e0-0000-7000-8000-000000000a01';

    private const AGGREGATE_ID = '0197f0e0-0000-7000-8000-000000000a02';

    private const EVENT_TYPE = 'com.cluster.outbox.claim.v1';

    public function test_claim_returns_true_only_for_the_first_caller_and_increments_attempts(): void
    {
        $this->insertPendingRow(self::EVENT_ID, attempts: 0);

        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $this->assertTrue($store->claim(self::EVENT_ID), 'first caller must win the claim');
        $this->assertSame(
            1,
            (int) DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('delivery_attempts'),
            'first claim must be observable via delivery_attempts',
        );
    }

    public function test_claim_returns_false_for_a_row_already_claimed_by_another_worker(): void
    {
        $this->insertPendingRow(self::EVENT_ID, attempts: 0);

        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $this->assertTrue($store->claim(self::EVENT_ID));
        $this->assertFalse(
            $store->claim(self::EVENT_ID),
            'second caller must lose the claim; otherwise two relays would both XADD',
        );
        $this->assertSame(
            1,
            (int) DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('delivery_attempts'),
            'lost claim must not mutate delivery_attempts',
        );
    }

    public function test_claim_is_a_noop_for_an_already_published_row(): void
    {
        $this->insertPendingRow(self::EVENT_ID, attempts: 0);

        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $this->assertTrue($store->markPublished(self::EVENT_ID));
        $this->assertFalse(
            $store->claim(self::EVENT_ID),
            'a published row must never be re-claimed; otherwise the relay would re-XADD',
        );
    }

    public function test_release_after_xadd_failure_returns_the_row_to_unclaimed_state(): void
    {
        $this->insertPendingRow(self::EVENT_ID, attempts: 0);

        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $this->assertTrue($store->claim(self::EVENT_ID));
        $this->assertSame(1, (int) DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('delivery_attempts'));

        $store->release(self::EVENT_ID);

        $this->assertSame(
            0,
            (int) DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('delivery_attempts'),
            'release must restore the row so the next claim can succeed',
        );
        $this->assertNull(
            DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('published_at'),
            'release must not leak into published_at',
        );
        $this->assertTrue(
            $store->claim(self::EVENT_ID),
            'after release, the row must be claimable again so XADD failures stay retryable',
        );
    }

    public function test_release_is_a_noop_on_a_published_row(): void
    {
        $this->insertPendingRow(self::EVENT_ID, attempts: 0);

        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $this->assertTrue($store->markPublished(self::EVENT_ID));
        $store->release(self::EVENT_ID);

        $row = DB::table('outbox_events')->where('event_id', self::EVENT_ID)->first();
        $this->assertNotNull($row->published_at, 'release must not unset published_at');
        $this->assertSame(0, (int) $row->delivery_attempts, 'release must not flip delivery_attempts on a published row');
    }

    public function test_release_is_a_noop_on_a_row_that_was_never_claimed(): void
    {
        $this->insertPendingRow(self::EVENT_ID, attempts: 0);

        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $store->release(self::EVENT_ID);

        $this->assertSame(
            0,
            (int) DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('delivery_attempts'),
            'release on an unclaimed row must be a no-op, not a negative-count hazard',
        );
    }

    public function test_mark_published_is_still_atomic_and_observably_idempotent(): void
    {
        $this->insertPendingRow(self::EVENT_ID, attempts: 0);

        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $this->assertTrue($store->markPublished(self::EVENT_ID));
        $this->assertFalse(
            $store->markPublished(self::EVENT_ID),
            'second markPublished must report false; the relay relies on this to suppress duplicate XADD counts',
        );
    }

    public function test_record_attempt_is_still_conditional_on_unpublished(): void
    {
        $this->insertPendingRow(self::EVENT_ID, attempts: 0);

        $store = $this->app->make(DatabaseOutboxRelayStore::class);

        $store->recordAttempt(self::EVENT_ID);
        $this->assertSame(1, (int) DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('delivery_attempts'));

        $this->assertTrue($store->markPublished(self::EVENT_ID));
        $store->recordAttempt(self::EVENT_ID);
        $this->assertSame(
            1,
            (int) DB::table('outbox_events')->where('event_id', self::EVENT_ID)->value('delivery_attempts'),
            'recordAttempt must not mutate a published row',
        );
    }

    private function insertPendingRow(string $eventId, int $attempts): void
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
                'data' => ['value' => 'relay-claim-store-fixture'],
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => '2026-07-30 09:00:00',
            'published_at' => null,
            'delivery_attempts' => $attempts,
            'created_at' => '2026-07-30 09:00:00',
            'updated_at' => '2026-07-30 09:00:00',
        ]);
    }
}
