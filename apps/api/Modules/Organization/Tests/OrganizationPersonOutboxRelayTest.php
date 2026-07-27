<?php

declare(strict_types=1);

namespace Modules\Organization\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Organization\Infrastructure\Outbox\Relay\OrganizationPersonOutboxRelay;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Shared\Contracts\OutboxRelayStore;
use Shared\Infrastructure\Outbox\DatabaseTransactionalOutbox;
use Shared\Infrastructure\Streams\RedisStreamTransport;
use Tests\TestCase;

final class OrganizationPersonOutboxRelayTest extends TestCase
{
    use RefreshDatabase;

    private const STREAM_MAP = [
        'com.cluster.organization.identityprovisioningrequested.v1' => 'platform.organization.identity-provisioning-requested.v1',
        'com.cluster.organization.personaccessstatuschanged.v1' => 'platform.organization.person-access-status-changed.v1',
        'com.cluster.organization.personregistered.v1' => 'platform.organization.person-registered.v1',
        'com.cluster.organization.personupdated.v1' => 'platform.organization.person-updated.v1',
    ];

    private RecordingPersonStreamTransport $transport;

    private OrganizationPersonOutboxRelay $relay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new RecordingPersonStreamTransport;
        $this->relay = new OrganizationPersonOutboxRelay(
            $this->app->make(OutboxRelayStore::class),
            $this->transport,
        );
    }

    public function test_relay_ignores_event_types_outside_the_person_stream_set(): void
    {
        // A non-person Organization event (facility created) must NOT be relayed —
        // the relay only forwards the four person CloudEvents declared in STREAMS.
        $this->appendEvent(
            '018f6f7d-0c00-7000-8000-000000000b11',
            '018f6f7d-0c00-7000-8000-000000000b12',
            'com.cluster.organization.facilitycreated.v1',
            ['facility_id' => '018f6f7d-0c00-7000-8000-000000000b13'],
        );

        $published = $this->relay->relayPending();

        $this->assertSame(0, $published, 'Facility event must not be relayed by the person relay.');
        $this->assertSame('', $this->transport->stream);
        $this->assertSame('', $this->transport->event);
    }

    #[DataProvider('personEventProvider')]
    public function test_relay_publishes_each_person_event_to_its_declared_stream(
        string $eventType,
        string $expectedStream,
    ): void {
        $eventId = '018f6f7d-0c00-7000-8000-000000000b21';
        $aggregateId = '018f6f7d-0c00-7000-8000-000000000b22';
        $payload = ['aggregate_id' => $aggregateId, 'classification' => 'confidential'];

        $this->appendEvent($eventId, $aggregateId, $eventType, $payload);

        $published = $this->relay->relayPending();

        $this->assertSame(1, $published);
        $this->assertSame($expectedStream, $this->transport->stream);
        $envelope = json_decode($this->transport->event, true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame($eventId, $envelope['id']);
        $this->assertSame($eventType, $envelope['type']);
        $this->assertSame($payload, $envelope['data']);

        $row = DB::table('outbox_events')->where('event_id', $eventId)->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->published_at);
        $this->assertSame(1, (int) $row->delivery_attempts);
    }

    /** @return iterable<string, array{string, string}> */
    public static function personEventProvider(): iterable
    {
        foreach (self::STREAM_MAP as $eventType => $expectedStream) {
            yield $eventType => [$eventType, $expectedStream];
        }
    }

    public function test_relay_marks_each_pending_event_attempted_before_publishing_and_published_after(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-000000000b31';
        $aggregateId = '018f6f7d-0c00-7000-8000-000000000b32';
        $this->appendEvent($eventId, $aggregateId, 'com.cluster.organization.personregistered.v1', [
            'person_id' => $aggregateId,
        ]);

        // Capture the row state observed by a parallel store spy during relay.
        $spy = new SpyOutboxRelayStore($this->app->make(OutboxRelayStore::class));
        $relay = new OrganizationPersonOutboxRelay($spy, new RecordingPersonStreamTransport);

        $published = $relay->relayPending();

        $this->assertSame(1, $published);
        $this->assertSame([$eventId], $spy->attempted, 'recordAttempt must run before xadd.');
        $this->assertSame([$eventId], $spy->markedPublished, 'markPublished must run after xadd.');
        $this->assertSame(['recordAttempt', 'markPublished'], $spy->order);
    }

    public function test_relay_transport_failure_leaves_event_unpublished_but_records_attempt(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-000000000b41';
        $aggregateId = '018f6f7d-0c00-7000-8000-000000000b42';
        $this->appendEvent($eventId, $aggregateId, 'com.cluster.organization.personupdated.v1', [
            'person_id' => $aggregateId,
        ]);

        $transport = new RecordingPersonStreamTransport(true);
        $relay = new OrganizationPersonOutboxRelay(
            $this->app->make(OutboxRelayStore::class),
            $transport,
        );

        try {
            $relay->relayPending();
            $this->fail('Transport failure must escape the bounded relay cycle.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected transport failure', $exception->getMessage());
        }

        $row = DB::table('outbox_events')->where('event_id', $eventId)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->published_at, 'Failed publish must not set published_at.');
        $this->assertSame(1, (int) $row->delivery_attempts, 'recordAttempt must run even when xadd throws.');
    }

    public function test_relay_clamps_oversized_limits_to_the_maximum_batch_size(): void
    {
        $eventId = '018f6f7d-0c00-7000-8000-000000000b51';
        $aggregateId = '018f6f7d-0c00-7000-8000-000000000b52';
        $this->appendEvent($eventId, $aggregateId, 'com.cluster.organization.personregistered.v1', [
            'person_id' => $aggregateId,
        ]);

        $spy = new SpyOutboxRelayStore($this->app->make(OutboxRelayStore::class));
        $relay = new OrganizationPersonOutboxRelay($spy, new RecordingPersonStreamTransport);

        $relay->relayPending(50_000);

        $this->assertSame([100], $spy->limitsRequested, 'Limit must be clamped to MAX_BATCH_SIZE=100.');
    }

    /** @param array<string, mixed> $payload */
    private function appendEvent(string $eventId, string $aggregateId, string $eventType, array $payload): void
    {
        $this->app->make(DatabaseTransactionalOutbox::class)->append(
            $eventId,
            $aggregateId,
            $eventType,
            $payload,
        );
    }
}

/**
 * In-memory {@see RedisStreamTransport} double that records the most recent
 * stream / fields pair and optionally throws on xadd.
 */
final class RecordingPersonStreamTransport implements RedisStreamTransport
{
    public string $stream = '';

    public string $event = '';

    public function __construct(private readonly bool $fail = false) {}

    public function xadd(string $stream, array $fields): string
    {
        if ($this->fail) {
            throw new RuntimeException('injected transport failure');
        }
        $this->stream = $stream;
        $this->event = (string) $fields['event'];

        return '1-0';
    }

    public function createGroup(string $stream, string $group): void {}

    public function readGroup(string $stream, string $group, string $consumer, int $limit): array
    {
        return [];
    }

    public function pending(string $stream, string $group, int $limit): array
    {
        return [];
    }

    public function reclaim(string $stream, string $group, string $consumer, int $minimumIdleMilliseconds, array $messageIds): array
    {
        return [];
    }

    public function ack(string $stream, string $group, string $messageId): void {}

    public function publishDlq(string $stream, string $sourceMessageId, array $payload): string
    {
        return '2-0';
    }

    public function purgeDlq(string $stream): void {}
}

/**
 * Wraps the real relay store and records which event ids went through
 * recordAttempt / markPublished and in what order.
 */
final class SpyOutboxRelayStore implements OutboxRelayStore
{
    /** @var list<string> */
    public array $attempted = [];

    /** @var list<string> */
    public array $markedPublished = [];

    /** @var list<int> */
    public array $limitsRequested = [];

    /** @var list<string> */
    public array $order = [];

    public function __construct(private readonly OutboxRelayStore $inner) {}

    public function pending(array $eventTypes, int $limit): array
    {
        $this->limitsRequested[] = $limit;

        return $this->inner->pending($eventTypes, $limit);
    }

    public function recordAttempt(string $eventId): void
    {
        $this->attempted[] = $eventId;
        $this->order[] = 'recordAttempt';
        $this->inner->recordAttempt($eventId);
    }

    public function markPublished(string $eventId): bool
    {
        $this->markedPublished[] = $eventId;
        $this->order[] = 'markPublished';

        return $this->inner->markPublished($eventId);
    }
}
