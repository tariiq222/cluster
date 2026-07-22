<?php

use App\Support\OrganizationHierarchyDemoSeeder;
use App\Support\W12E2EFixtureSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Modules\Identity\Features\ConsumeOrganizationPersonEvents\Worker\IdentityPersonStreamWorker;
use Modules\Notifications\Features\ConsumeWorkRecordSubmitted\Worker\NotificationsStreamWorker;
use Modules\Organization\Infrastructure\Outbox\Relay\OrganizationPersonOutboxRelay;
use Modules\WorkRecords\Infrastructure\Outbox\Relay\RedisOutboxRelay;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('work-records:relay-pending {--once}', function (): int {
    if (! $this->option('once')) {
        $this->error('The bounded --once mode is required.');

        return Command::FAILURE;
    }

    $limit = max(1, min((int) env('OUTBOX_RELAY_BATCH_SIZE', 100), 100));

    try {
        $relay = app(RedisOutboxRelay::class);
        $published = $relay->relayPending($limit);
        $this->info("Relayed events: {$published}");

        return Command::SUCCESS;
    } catch (Throwable) {
        $this->error('The bounded relay cycle failed.');

        return Command::FAILURE;
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

            return Command::FAILURE;
        }

        $limit = max(1, min((int) env('NOTIFICATIONS_STREAM_BATCH_SIZE', 10), 100));

        try {
            $worker = app(NotificationsStreamWorker::class);
            $processed = $worker->consumeOnce($consumer, $limit);
            $this->info("Processed messages: {$processed}");

            return Command::SUCCESS;
        } catch (Throwable) {
            $this->error('The bounded notification consumer cycle failed.');

            return Command::FAILURE;
        }
    },
)->purpose('Consume one bounded WorkRecord submission cycle');

Artisan::command('organization:relay-person-events {--once}', function (): int {
    if (! $this->option('once')) {
        $this->error('The bounded --once mode is required.');

        return Command::FAILURE;
    }

    try {
        $published = app(OrganizationPersonOutboxRelay::class)->relayPending();
        $this->info("Relayed events: {$published}");

        return Command::SUCCESS;
    } catch (Throwable) {
        $this->error('The bounded Organization Person relay cycle failed.');

        return Command::FAILURE;
    }
})->purpose('Relay one bounded batch of committed Organization Person events');

Artisan::command('identity:consume-person-events {--once} {--consumer=}', function (): int {
    $consumer = $this->option('consumer');
    if (! $this->option('once')
        || ! is_string($consumer)
        || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $consumer) !== 1) {
        $this->error('The bounded --once mode and a valid --consumer name are required.');

        return Command::FAILURE;
    }

    try {
        $processed = app(IdentityPersonStreamWorker::class)->consumeOnce($consumer);
        $this->info("Processed messages: {$processed}");

        return Command::SUCCESS;
    } catch (Throwable) {
        $this->error('The bounded Identity Person consumer cycle failed.');

        return Command::FAILURE;
    }
})->purpose('Consume one bounded Organization Person event cycle');

Artisan::command('e2e:w1-2:seed', function (): int {
    if (! app()->environment('testing')) {
        $this->error('W1.2 E2E fixture seeding is available only when APP_ENV=testing.');

        return Command::FAILURE;
    }

    try {
        $this->line(json_encode(app(W12E2EFixtureSeeder::class)->seed(), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    } catch (Throwable $e) {
        $this->error('W1.2 E2E fixture seeding failed: '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());

        return Command::FAILURE;
    }
})->purpose('Create one disposable W1.2 browser E2E fixture set for the testing runtime');

Artisan::command('organization:demo-seed {--force : Re-run even if the demo hierarchy already exists}', function (): int {
    if (! in_array(app()->environment(), ['local', 'testing'], true)) {
        $this->error('organization:demo-seed is available only in local/testing; refuse to run in '.app()->environment().'.');

        return Command::FAILURE;
    }

    try {
        $seeder = app(OrganizationHierarchyDemoSeeder::class);
        $this->line(json_encode($seeder->seed((bool) $this->option('force')), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    } catch (Throwable $e) {
        $this->error('organization:demo-seed failed: '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());

        return Command::FAILURE;
    }
})->purpose('Seed the four-layer healthcare cluster hierarchy into the local database');
