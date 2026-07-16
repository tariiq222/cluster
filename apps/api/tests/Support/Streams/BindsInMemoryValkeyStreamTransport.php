<?php

namespace Tests\Support\Streams;

use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Worker\NotificationsStreamWorker;
use Modules\WorkRecords\Infrastructure\Outbox\Relay\ValkeyOutboxRelay;
use Shared\Infrastructure\Streams\ValkeyStreamTransport;

trait BindsInMemoryValkeyStreamTransport
{
    protected function bindInMemoryValkeyStreamTransport(
        ?InMemoryValkeyStreamTransport $transport = null,
    ): InMemoryValkeyStreamTransport {
        $transport ??= new InMemoryValkeyStreamTransport;
        $this->app->instance(ValkeyStreamTransport::class, $transport);
        $this->app->forgetInstance(ValkeyOutboxRelay::class);
        $this->app->forgetInstance(NotificationsStreamWorker::class);

        return $transport;
    }
}
