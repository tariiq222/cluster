<?php

namespace Tests\Feature;

require_once dirname(__DIR__, 2).'/Shared/Infrastructure/Streams/ValkeyStreamTransport.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Handler\ConsumeWorkRecordSubmittedHandler;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Worker\NotificationsStreamWorker;
use Predis\Client;
use RuntimeException;
use Shared\Infrastructure\Streams\PredisValkeyStreamTransport;
use Shared\Infrastructure\Streams\ValkeyStreamTransport;
use Tests\TestCase;

final class WalkingSkeletonMySqlE2ETest extends TestCase
{
    use RefreshDatabase;

    private const CORRELATION_A = '018f6f7d-0c00-7000-8000-000000000101';

    private const CORRELATION_B = '018f6f7d-0c00-7000-8000-000000000102';

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires the explicit MySQL/Valkey integration lane.');
        }
    }

    public function test_real_mysql_valkey_lifecycle_isolated_and_replay_safe(): void
    {
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertNotNull(DB::connection()->getPdo());
        $transport = app(ValkeyStreamTransport::class);
        $this->assertInstanceOf(PredisValkeyStreamTransport::class, $transport);
        $this->clearStreams();

        $tokenA = $this->login('fixture-account-a', 'fixture-password-a', self::CORRELATION_A)
            ->json('data.access_token');
        $tokenB = $this->login('fixture-account-b', 'fixture-password-b', self::CORRELATION_B)
            ->json('data.access_token');

        $createdA = $this->createRecord($tokenA, self::CORRELATION_A, 'mysql-a', 'وصف أ');
        $createdB = $this->createRecord($tokenB, self::CORRELATION_B, 'mysql-b', 'وصف ب');
        $recordAId = $createdA->json('data.id');
        $recordBId = $createdB->json('data.id');
        $this->assertNotSame($recordAId, $recordBId);

        $this->assertOwnRecordOnly($tokenA, $recordAId, 'mysql-a', self::CORRELATION_A);
        $this->assertOwnRecordOnly($tokenB, $recordBId, 'mysql-b', self::CORRELATION_B);
        $crossA = $this->withToken($tokenA)->getJson("/api/v1/work-records/{$recordBId}", $this->headers(self::CORRELATION_A));
        $crossB = $this->withToken($tokenB)->getJson("/api/v1/work-records/{$recordAId}", $this->headers(self::CORRELATION_B));
        $this->assertSame($crossA->assertNotFound()->getContent(), $crossB->assertNotFound()->getContent());
        $this->assertStringNotContainsString('mysql-', $crossA->getContent());

        $this->assertDatabaseCount('outbox_events', 2);
        $this->artisan('work-records:relay-pending --once')->assertSuccessful();
        $this->assertSame(2, DB::table('outbox_events')->whereNotNull('published_at')->count());

        $failing = new FailOnceAckTransport($transport);
        $this->app->instance(ValkeyStreamTransport::class, $failing);
        $this->artisan('notifications:consume-work-record-submitted --once --consumer=mysql-crash')->assertFailed();
        $this->assertDatabaseCount('notification_inbox', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotEmpty($failing->pending('platform.work-record.submitted.v1', 'notifications.work-record-submitted.v1', 10));
        $this->app->instance(ValkeyStreamTransport::class, $failing);
        $worker = new NotificationsStreamWorker(
            $failing,
            app(ConsumeWorkRecordSubmittedHandler::class),
        );
        $this->assertSame(2, $worker->consumeOnce('mysql-reclaim'));
        $this->assertDatabaseCount('notification_inbox', 2);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame([], $failing->pending('platform.work-record.submitted.v1', 'notifications.work-record-submitted.v1', 10));

        foreach ([[$tokenA, $recordAId], [$tokenB, $recordBId]] as [$token, $recordId]) {
            $this->withToken($token)->getJson('/api/v1/notifications', $this->headers(self::CORRELATION_A))
                ->assertOk()
                ->assertJsonPath('items.0.source.record_id', $recordId);
        }

        if (getenv('W1_1_EXPECT_REAL_MYSQL_VALKEY_RED') === '1') {
            self::fail('MISSING_REAL_MYSQL_VALKEY_INTEGRATION');
        }
    }

    public function test_real_valkey_poison_event_reaches_dlq_before_ack(): void
    {
        $this->assertInstanceOf(PredisValkeyStreamTransport::class, app(ValkeyStreamTransport::class));
        /** @var ValkeyStreamTransport $transport */
        $transport = app(ValkeyStreamTransport::class);
        $this->clearStreams();
        $event = [
            'specversion' => '1.0',
            'id' => '018f6f7d-0c00-7000-8000-000000000199',
            'source' => '/work-records',
            'type' => 'com.cluster.workrecord.invalid.v1',
            'subject' => '/work-records/018f6f7d-0c00-7000-8000-000000000198',
            'time' => '2026-07-16T00:00:00.000Z',
            'datacontenttype' => 'application/json',
            'correlationid' => self::CORRELATION_A,
            'data' => [
                'record' => [
                    'id' => '018f6f7d-0c00-7000-8000-000000000198',
                    'owner' => ['user_id' => '018f6f7d-0c00-7000-8000-000000000197'],
                ],
                'access_context' => ['owner_facility_id' => '018f6f7d-0c00-7000-8000-000000000196'],
                'classification' => 'internal',
            ],
        ];
        $transport->xadd('platform.work-record.submitted.v1', ['event' => json_encode($event, JSON_THROW_ON_ERROR)]);
        $worker = new NotificationsStreamWorker(
            new FailOnceAckTransport($transport, true, false),
            app(ConsumeWorkRecordSubmittedHandler::class),
        );
        foreach (['poison-a', 'poison-b'] as $consumer) {
            try {
                $worker->consumeOnce($consumer);
                self::fail('poison event unexpectedly succeeded before its retry budget was exhausted');
            } catch (\Throwable) {
                self::assertTrue(true);
            }
        }
        $this->assertSame(1, $worker->consumeOnce('poison-c'));

        $client = new Client([
            'scheme' => 'tcp',
            'host' => config('database.redis.default.host'),
            'port' => (int) config('database.redis.default.port'),
        ]);
        $dlq = $client->executeRaw(['XRANGE', 'platform.dlq.v1', '-', '+']);
        $this->assertIsArray($dlq);
        $this->assertNotEmpty($dlq);
    }

    private function assertOwnRecordOnly(string $token, string $recordId, string $title, string $correlationId): void
    {
        $this->withToken($token)->getJson('/api/v1/work-records', $this->headers($correlationId))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $recordId)
            ->assertJsonPath('items.0.payload.title', $title);
        $this->withToken($token)->getJson("/api/v1/work-records/{$recordId}", $this->headers($correlationId))
            ->assertOk()
            ->assertJsonPath('data.id', $recordId);
    }

    private function login(string $username, string $password, string $correlationId): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', compact('username', 'password'), $this->headers($correlationId))
            ->assertOk();
    }

    private function createRecord(string $token, string $correlationId, string $title, string $description): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/work-records', [
            'work_definition_code' => 'request',
            'title' => $title,
            'description' => $description,
        ], [
            ...$this->headers($correlationId),
            'Idempotency-Key' => 'mysql-'.str_replace(' ', '-', $title),
        ])->assertCreated();
    }

    /** @return array<string, string> */
    private function headers(string $correlationId): array
    {
        return ['X-Correlation-ID' => $correlationId];
    }

    private function clearStreams(): void
    {
        $client = new Client([
            'scheme' => 'tcp',
            'host' => config('database.redis.default.host'),
            'port' => (int) config('database.redis.default.port'),
        ]);
        $client->executeRaw(['DEL', 'platform.work-record.submitted.v1', 'platform.dlq.v1']);
    }
}

final class FailOnceAckTransport implements ValkeyStreamTransport
{
    private bool $failed = false;

    public function __construct(
        private readonly ValkeyStreamTransport $delegate,
        private readonly bool $forceReclaim = false,
        private readonly bool $failAck = true,
    ) {}

    public function xadd(string $stream, array $fields): string
    {
        return $this->delegate->xadd($stream, $fields);
    }

    public function createGroup(string $stream, string $group): void
    {
        $this->delegate->createGroup($stream, $group);
    }

    public function readGroup(string $stream, string $group, string $consumer, int $limit): array
    {
        return $this->delegate->readGroup($stream, $group, $consumer, $limit);
    }

    public function pending(string $stream, string $group, int $limit): array
    {
        $pending = $this->delegate->pending($stream, $group, $limit);
        if ($this->failed || $this->forceReclaim) {
            return array_map(static fn (array $entry): array => [...$entry, 'idle_ms' => 60_001], $pending);
        }

        return $pending;
    }

    public function reclaim(string $stream, string $group, string $consumer, int $minimumIdleMilliseconds, array $messageIds): array
    {
        return $this->delegate->reclaim($stream, $group, $consumer, ($this->failed || $this->forceReclaim) ? 0 : $minimumIdleMilliseconds, $messageIds);
    }

    public function ack(string $stream, string $group, string $messageId): void
    {
        if ($this->failAck && ! $this->failed) {
            $this->failed = true;
            throw new RuntimeException('controlled post-commit/pre-ack crash');
        }

        $this->delegate->ack($stream, $group, $messageId);
    }

    public function publishDlq(string $stream, array $deadLetter): string
    {
        return $this->delegate->publishDlq($stream, $deadLetter);
    }
}
