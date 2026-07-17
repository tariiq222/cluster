<?php

namespace Tests\Support\Streams;

use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Worker\NotificationsStreamWorker;
use Modules\WorkRecords\Infrastructure\Outbox\Relay\RedisOutboxRelay;
use Shared\Infrastructure\Streams\RedisStreamTransport;

trait BindsInMemoryRedisStreamTransport
{
    protected function bindInMemoryRedisStreamTransport(
        ?InMemoryRedisStreamTransport $transport = null,
    ): InMemoryRedisStreamTransport {
        $transport ??= new InMemoryRedisStreamTransport;
        $this->app->instance(RedisStreamTransport::class, $transport);
        $this->app->forgetInstance(RedisOutboxRelay::class);
        $this->app->forgetInstance(NotificationsStreamWorker::class);

        return $transport;
    }
}
