<?php

namespace Tests\Unit\Shared\Infrastructure;

use Illuminate\Redis\Connections\Connection as LaravelConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Mockery;
use PHPUnit\Framework\TestCase;
use Predis\Client as PredisClient;
use Shared\Infrastructure\Streams\LaravelRedisStreamTransport;
use Shared\Infrastructure\Streams\PhpRedisStreamDriver;
use Shared\Infrastructure\Streams\PredisStreamDriver;

final class LaravelRedisStreamTransportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_transport_returns_message_id_from_active_driver(): void
    {
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('client')->andReturnUsing(function (): \Redis {
            $client = Mockery::mock(\Redis::class);
            $client->shouldReceive('xadd')->once()->with('platform.events.v1', '*', ['event', '{}'])->andReturn('1784198760000-0');

            return $client;
        });

        $transport = new LaravelRedisStreamTransport($connection);

        $this->assertSame('1784198760000-0', $transport->xadd('platform.events.v1', ['event' => '{}']));
    }

    public function test_transport_swallows_busygroup_when_creating_group(): void
    {
        $connection = Mockery::mock(PhpRedisConnection::class);
        $client = Mockery::mock(\Redis::class);
        $client->shouldReceive('xgroup')->twice()->with('CREATE', 'platform.events.v1', 'workers.v1', '0', true)
            ->andThrow(new \RuntimeException('BUSYGROUP Consumer Group name already exists'));
        $connection->shouldReceive('client')->andReturn($client);

        $transport = new LaravelRedisStreamTransport($connection);
        $transport->createGroup('platform.events.v1', 'workers.v1');
        $transport->createGroup('platform.events.v1', 'workers.v1');

        $this->addToAssertionCount(1);
    }

    public function test_transport_resolves_predis_driver_for_predis_connection(): void
    {
        $predis = Mockery::mock(PredisClient::class);
        $predis->shouldReceive('xadd')->once()->with('platform.events.v1', ['event' => '{}'], '*')->andReturn('1784198760000-0');

        $connection = Mockery::mock(PredisConnection::class);
        $connection->shouldReceive('client')->once()->andReturn($predis);

        $transport = new LaravelRedisStreamTransport($connection);

        $this->assertSame('1784198760000-0', $transport->xadd('platform.events.v1', ['event' => '{}']));
    }

    public function test_phpredis_driver_flattens_xadd_field_pairs(): void
    {
        $client = Mockery::mock(\Redis::class);
        $client->shouldReceive('xadd')->once()->with('platform.events.v1', '*', ['event', '{}'])->andReturn('1784198760000-0');

        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('client')->andReturn($client);

        $driver = new PhpRedisStreamDriver($connection);

        $this->assertSame('1784198760000-0', $driver->xadd('platform.events.v1', ['event' => '{}']));
    }

    public function test_phpredis_driver_invokes_xreadgroup_with_array_stream_id_map(): void
    {
        $client = Mockery::mock(\Redis::class);
        $client->shouldReceive('xreadgroup')->once()
            ->with('workers.v1', 'consumer-a', ['platform.events.v1' => '>'], 10, 0)
            ->andReturn(['platform.events.v1' => [['id' => '1784198760000-0', 'fields' => ['event' => '{}']]]]);

        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('client')->andReturn($client);

        $driver = new PhpRedisStreamDriver($connection);

        $this->assertSame(
            ['platform.events.v1' => [['id' => '1784198760000-0', 'fields' => ['event' => '{}']]]],
            $driver->readGroup('platform.events.v1', 'workers.v1', 'consumer-a', 10),
        );
    }

    public function test_phpredis_driver_passes_unprefixed_keys_to_eval(): void
    {
        $client = Mockery::mock(\Redis::class);
        $client->shouldReceive('eval')->once()
            ->with('return KEYS[1]', ['platform.events.v1', 'arg-1'], 1)
            ->andReturn('platform.events.v1');

        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('client')->andReturn($client);

        $driver = new PhpRedisStreamDriver($connection);

        $this->assertSame('platform.events.v1', $driver->eval('return KEYS[1]', ['platform.events.v1'], ['arg-1']));
    }

    public function test_predis_driver_invokes_stream_commands_with_positional_arguments(): void
    {
        $client = Mockery::mock(PredisClient::class);
        $client->shouldReceive('xgroup')->once()->with('CREATE', 'platform.events.v1', 'workers.v1', '0', true)->andReturn('OK');

        $driver = new PredisStreamDriver($client);
        $driver->createGroup('platform.events.v1', 'workers.v1');

        $this->addToAssertionCount(1);
    }

    public function test_predis_driver_passes_unprefixed_keys_to_eval(): void
    {
        $client = Mockery::mock(PredisClient::class);
        $client->shouldReceive('eval')->once()
            ->with('return KEYS[1]', 1, 'platform.events.v1', 'arg-1')
            ->andReturn('platform.events.v1');

        $driver = new PredisStreamDriver($client);

        $this->assertSame('platform.events.v1', $driver->eval('return KEYS[1]', ['platform.events.v1'], ['arg-1']));
    }
}