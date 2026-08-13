<?php

use App\Support\OrganizationHierarchyDemoSeeder;
use App\Support\W12E2EFixtureSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Documents\Features\Retention\ExpireExpiredDocuments;
use Modules\Documents\Infrastructure\Outbox\Relay\DocumentsOutboxRelay;
use Modules\Identity\Features\ConsumeOrganizationPersonEvents\Worker\IdentityPersonStreamWorker;
use Modules\Notifications\Features\ConsumeTechnicalAlert\Worker\NotificationsTechnicalAlertWorker;
use Modules\Notifications\Features\ReplayDeadLetters\Handler\ReplayDeadLettersHandler;
use Modules\Organization\Infrastructure\Outbox\Relay\OrganizationPersonOutboxRelay;
use Modules\PlatformSettings\Infrastructure\Outbox\PlatformSettingsOutboxRelay;
use Modules\PlatformSettings\Infrastructure\Outbox\TechnicalAlertOutboxRelay;
use Modules\Reporting\Features\PurgeExpiredReporting\Handler\PurgeExpiredReportingHandler;
use Modules\Tasks\Infrastructure\Outbox\Relay\TasksOutboxRelay;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('documents:relay-events {--once} {--limit=100}', function (): int {
    if (! $this->option('once')) {
        $this->error('The bounded --once mode is required.');

        return Command::FAILURE;
    }

    $limit = max(1, min((int) $this->option('limit'), 100));
    try {
        $published = app(DocumentsOutboxRelay::class)->relayPending($limit);
        $this->info("Relayed document events: {$published}");

        return Command::SUCCESS;
    } catch (Throwable) {
        $this->error('The bounded Documents relay cycle failed.');

        return Command::FAILURE;
    }
})->purpose('Relay one bounded batch of committed Documents events');

Artisan::command('tasks:relay-events {--once} {--limit=100}', function (): int {
    if (! $this->option('once')) {
        $this->error('The bounded --once mode is required.');

        return Command::FAILURE;
    }

    $limit = max(1, min((int) $this->option('limit'), 100));

    try {
        $published = app(TasksOutboxRelay::class)->relayPending($limit);
        $this->info("Relayed task events: {$published}");

        return Command::SUCCESS;
    } catch (Throwable) {
        $this->error('The bounded Tasks relay cycle failed.');

        return Command::FAILURE;
    }
})->purpose('Relay one bounded batch of committed Task events');

Artisan::command('documents:expire-retention {--once} {--limit=100}', function (): int {
    if (! $this->option('once')) {
        $this->error('The bounded --once mode is required.');

        return Command::FAILURE;
    }

    $limit = max(1, min((int) $this->option('limit'), 100));

    try {
        $expired = app(ExpireExpiredDocuments::class)->expireOnce($limit);
        $this->info("Expired documents: {$expired}");

        return Command::SUCCESS;
    } catch (Throwable) {
        $this->error('The bounded Documents retention expiry cycle failed.');

        return Command::FAILURE;
    }
})->purpose('Expire one bounded batch of Documents whose retention period has elapsed');

Artisan::command('platform-settings:relay-technical-alerts {--once} {--limit=100}', function (): int {
    if (! $this->option('once')) {
        $this->error('The bounded --once mode is required.');

        return Command::FAILURE;
    }

    $limit = max(1, min((int) $this->option('limit'), 100));

    try {
        $relay = app(TechnicalAlertOutboxRelay::class);
        $published = $relay->relayPending($limit);
        $this->info("Relayed technical alert events: {$published}");

        return Command::SUCCESS;
    } catch (Throwable) {
        $this->error('The bounded technical alert relay cycle failed.');

        return Command::FAILURE;
    }
})->purpose('Relay one bounded batch of committed technical alert events');

Artisan::command('platform-settings:relay-events {--once} {--limit=100}', function (): int {
    if (! $this->option('once')) {
        $this->error('The bounded --once mode is required.');

        return Command::FAILURE;
    }

    $limit = max(1, min((int) $this->option('limit'), 100));

    try {
        $relay = app(PlatformSettingsOutboxRelay::class);
        $published = $relay->relayPending($limit);
        $this->info("Relayed platform settings events: {$published}");

        return Command::SUCCESS;
    } catch (Throwable) {
        $this->error('The bounded platform settings relay cycle failed.');

        return Command::FAILURE;
    }
})->purpose('Relay one bounded batch of committed PlatformSettings events');

Artisan::command(
    'notifications:consume-technical-alert {--once} {--consumer=} {--limit=100}',
    function (): int {
        $consumer = $this->option('consumer');
        if (! $this->option('once')
            || ! is_string($consumer)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $consumer) !== 1) {
            $this->error('The bounded --once mode and a valid --consumer name are required.');

            return Command::FAILURE;
        }

        $limit = max(1, min((int) $this->option('limit'), 100));

        try {
            $worker = app(NotificationsTechnicalAlertWorker::class);
            $processed = $worker->consumeOnce($consumer, $limit);
            $this->info("Processed messages: {$processed}");

            return Command::SUCCESS;
        } catch (Throwable) {
            $this->error('The bounded technical alert consumer cycle failed.');

            return Command::FAILURE;
        }
    },
)->purpose('Consume one bounded technical alert cycle');

Artisan::command('notifications:replay-dlq {--once} {--limit=100}', function (): int {
    if (! $this->option('once')) {
        $this->error('The bounded --once mode is required.');

        return Command::FAILURE;
    }

    $limit = max(1, min((int) $this->option('limit'), 100));

    try {
        $result = app(ReplayDeadLettersHandler::class)->replayOnce($limit);
        $this->info("Replayed dead letters: {$result['replayed']} (skipped: {$result['skipped']}, failed: {$result['failed']})");

        return Command::SUCCESS;
    } catch (Throwable) {
        $this->error('The bounded DLQ replay cycle failed.');

        return Command::FAILURE;
    }
})->purpose('Replay one bounded batch of notification dead letters');

Artisan::command('reporting:purge-expired {--once} {--limit=100}', function (): int {
    if (! $this->option('once')) {
        $this->error('The bounded --once mode is required.');

        return Command::FAILURE;
    }

    $limit = max(1, min((int) $this->option('limit'), 100));

    try {
        $result = app(PurgeExpiredReportingHandler::class)->purge($limit);
        $this->info(sprintf(
            'Purged %d expired artifact(s) and %d orphaned run(s); more due: %s.',
            $result['artifacts_purged'],
            $result['runs_purged'],
            $result['has_more'] ? 'yes' : 'no',
        ));

        return Command::SUCCESS;
    } catch (Throwable) {
        $this->error('The bounded Reporting purge cycle failed.');

        return Command::FAILURE;
    }
})->purpose('Purge one bounded batch of expired report exports and orphaned runs');

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

Artisan::command('e2e:platform-settings:seed', function () {
    if (! in_array(app()->environment(), ['local', 'testing'], true)) {
        $this->error('PlatformSettings E2E fixture seeding is available only in local/testing; refuse to run in '.app()->environment().'.');

        return Command::FAILURE;
    }

    try {
        app(\Database\Seeders\PlatformSettingsE2EAccountSeeder::class)->run();
        $this->line('OK: PlatformSettings E2E personas seeded.');

        return Command::SUCCESS;
    } catch (Throwable $e) {
        $this->error('PlatformSettings E2E fixture seeding failed: '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());

        return Command::FAILURE;
    }
})->purpose('Seed the four dedicated PlatformSettings E2E personas in the local/testing runtime');
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

Schedule::command('platform-operations:dispatch --once --limit=10')
    ->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('platform-settings:relay-technical-alerts --once --limit=100')
    ->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('platform-settings:relay-events --once --limit=100')
    ->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('notifications:consume-technical-alert --once --consumer=production-scheduler --limit=100')
    ->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('notifications:replay-dlq --once --limit=100')
    ->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('reporting:purge-expired --once --limit=100')
    ->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('documents:expire-retention --once --limit=100')
    ->dailyAt('02:15')->withoutOverlapping()->onOneServer();
