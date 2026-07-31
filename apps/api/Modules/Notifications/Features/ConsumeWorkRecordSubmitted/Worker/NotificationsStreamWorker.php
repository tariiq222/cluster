<?php

namespace Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Worker;

use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Handler\ConsumeWorkRecordSubmittedHandler;
use Modules\Notifications\Features\Worker\AbstractStreamWorker;
use Shared\Infrastructure\Streams\RedisStreamTransport;

final class NotificationsStreamWorker extends AbstractStreamWorker
{
    private const STREAM = 'platform.work-record.submitted.v1';

    private const GROUP = 'notifications.work-record-submitted.v1';

    public function __construct(
        RedisStreamTransport $transport,
        private readonly ConsumeWorkRecordSubmittedHandler $handler,
    ) {
        parent::__construct($transport);
    }

    protected function stream(): string
    {
        return self::STREAM;
    }

    protected function group(): string
    {
        return self::GROUP;
    }

    /** @param array<string, mixed> $event */
    protected function handleEvent(array $event): void
    {
        $this->handler->handle($event);
    }
}
