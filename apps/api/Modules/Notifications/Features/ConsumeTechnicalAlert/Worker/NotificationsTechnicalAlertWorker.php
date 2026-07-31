<?php

namespace Modules\Notifications\Features\ConsumeTechnicalAlert\Worker;

use Modules\Notifications\Features\ConsumeTechnicalAlert\Handler\ConsumeTechnicalAlertHandler;
use Modules\Notifications\Features\Worker\AbstractStreamWorker;
use Shared\Infrastructure\Streams\RedisStreamTransport;

final class NotificationsTechnicalAlertWorker extends AbstractStreamWorker
{
    private const STREAM = 'platform.technical-alert.v1';

    private const GROUP = 'notifications.technical-alert.v1';

    public function __construct(
        RedisStreamTransport $transport,
        private readonly ConsumeTechnicalAlertHandler $handler,
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
