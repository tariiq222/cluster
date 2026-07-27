<?php

declare(strict_types=1);

namespace Modules\Organization\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Infrastructure\Outbox\OrganizationOutbox;
use Shared\Infrastructure\Outbox\DatabaseTransactionalOutbox;
use Tests\TestCase;
use ValueError;

final class OrganizationOutboxTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT_ID = '018f6f7d-0c00-7000-8000-000000000a01';

    private const AGGREGATE_ID = '018f6f7d-0c00-7000-8000-000000000a02';

    private const EVENT_TYPE = 'com.cluster.organization.personregistered.v1';

    private OrganizationOutbox $outbox;

    protected function setUp(): void
    {
        parent::setUp();

        // Resolve through the real binding the AppServiceProvider wires
        // (TransactionalOutboxEnvelope -> DatabaseTransactionalOutbox).
        $this->outbox = new OrganizationOutbox($this->app->make(DatabaseTransactionalOutbox::class));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_insert_persists_outbox_row_with_caller_supplied_time_and_preserves_cloud_event_payload(): void
    {
        $providedTime = '2026-07-20T08:30:00.000Z';
        $cloudEvent = [
            'id' => self::EVENT_ID,
            'type' => self::EVENT_TYPE,
            'source' => 'cluster.organization',
            'specversion' => '1.0',
            'datacontenttype' => 'application/json',
            'time' => $providedTime,
            'correlationid' => '018f6f7d-0c00-7000-8000-000000000a03',
            'data' => ['person_id' => self::AGGREGATE_ID],
        ];

        $this->outbox->insert($cloudEvent, self::AGGREGATE_ID);

        $row = DB::table('outbox_events')->where('event_id', self::EVENT_ID)->first();
        $this->assertNotNull($row, 'OrganizationOutbox::insert must persist a row in outbox_events.');
        $this->assertSame(self::EVENT_ID, (string) $row->event_id);
        $this->assertSame(self::AGGREGATE_ID, (string) $row->aggregate_id);
        $this->assertSame(self::EVENT_TYPE, (string) $row->event_type);

        $stored = json_decode((string) $row->cloud_event, true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame($cloudEvent, $stored, 'Caller-supplied CloudEvent must be stored byte-for-byte.');
        $this->assertNull($row->published_at);
        $this->assertSame(0, (int) $row->delivery_attempts);

        // The envelope adapter formats occurred_at with a space separator for
        // the SQL timestamp column; the caller's CloudEvent `time` must round-trip
        // through unchanged and the timestamp column must reflect it.
        $this->assertSame('2026-07-20 08:30:00', (string) $row->occurred_at);
        $this->assertSame($providedTime, $stored['time']);
    }

    public function test_insert_defaults_occurred_at_when_caller_omits_the_cloud_event_time(): void
    {
        CarbonImmutable::setTestNow('2026-07-21T12:00:00.123456Z');

        $cloudEvent = [
            'id' => self::EVENT_ID,
            'type' => self::EVENT_TYPE,
            'source' => 'cluster.organization',
            'specversion' => '1.0',
            'datacontenttype' => 'application/json',
            'data' => ['person_id' => self::AGGREGATE_ID],
        ];
        $this->assertArrayNotHasKey('time', $cloudEvent);

        $this->outbox->insert($cloudEvent, self::AGGREGATE_ID);

        $row = DB::table('outbox_events')->where('event_id', self::EVENT_ID)->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-07-21 12:00:00', (string) $row->occurred_at);
    }

    public function test_insert_falls_back_to_now_when_cloud_event_time_is_not_a_string(): void
    {
        CarbonImmutable::setTestNow('2026-07-22T09:15:30.000Z');

        $cloudEvent = [
            'id' => self::EVENT_ID,
            'type' => self::EVENT_TYPE,
            'source' => 'cluster.organization',
            'time' => 1715000000,
            'data' => ['person_id' => self::AGGREGATE_ID],
        ];

        $this->outbox->insert($cloudEvent, self::AGGREGATE_ID);

        $row = DB::table('outbox_events')->where('event_id', self::EVENT_ID)->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-07-22 09:15:30', (string) $row->occurred_at);
    }

    public function test_insert_rejects_an_event_type_that_is_not_in_the_catalog(): void
    {
        $cloudEvent = [
            'id' => self::EVENT_ID,
            'type' => 'com.cluster.organization.this_type_does_not_exist.v1',
            'source' => 'cluster.organization',
            'time' => '2026-07-20T08:30:00.000Z',
            'data' => ['person_id' => self::AGGREGATE_ID],
        ];

        $this->expectException(ValueError::class);
        $this->outbox->insert($cloudEvent, self::AGGREGATE_ID);
    }
}
