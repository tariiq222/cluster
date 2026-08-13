<?php

namespace Tests\Support\Streams;

use Shared\Infrastructure\Streams\RedisStreamTransport;

trait BindsInMemoryRedisStreamTransport
{
    protected function bindInMemoryRedisStreamTransport(
        ?InMemoryRedisStreamTransport $transport = null,
    ): InMemoryRedisStreamTransport {
        $transport ??= new InMemoryRedisStreamTransport;
        $this->app->instance(RedisStreamTransport::class, $transport);

        return $transport;
    }
}
