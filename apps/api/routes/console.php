<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Worker\NotificationsStreamWorker;
use Modules\WorkRecords\Infrastructure\Outbox\Relay\ValkeyOutboxRelay;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('work-records:relay-pending {--once}', function (): int {
    if (! $this->option('once')) {
        $this->error('The bounded --once mode is required.');

        return self::FAILURE;
    }

    $limit = max(1, min((int) env('OUTBOX_RELAY_BATCH_SIZE', 100), 100));

    try {
        $relay = app(ValkeyOutboxRelay::class);
        $published = $relay->relayPending($limit);
        $this->info("Relayed events: {$published}");

        return self::SUCCESS;
    } catch (Throwable) {
        $this->error('The bounded relay cycle failed.');

        return self::FAILURE;
    }
})->purpose('Relay one bounded batch of committed WorkRecord events');

Artisan::command(
    'notifications:consume-work-record-submitted {--once} {--consumer=}',
    function (): int {
        $consumer = $this->option('consumer');
        if (! $this->option('once')
            || ! is_string($consumer)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $consumer) !== 1) {
            $this->error('The bounded --once mode and a valid --consumer name are required.');

            return self::FAILURE;
        }

        $limit = max(1, min((int) env('NOTIFICATIONS_STREAM_BATCH_SIZE', 10), 100));

        try {
            $worker = app(NotificationsStreamWorker::class);
            $processed = $worker->consumeOnce($consumer, $limit);
            $this->info("Processed messages: {$processed}");

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('The bounded notification consumer cycle failed.');

            return self::FAILURE;
        }
    },
)->purpose('Consume one bounded WorkRecord submission cycle');
