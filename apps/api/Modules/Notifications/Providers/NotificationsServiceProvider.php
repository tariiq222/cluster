<?php

declare(strict_types=1);

namespace Modules\Notifications\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Notifications\Contracts\ResolveTechnicalAlertRecipients;
use Modules\Notifications\Infrastructure\Persistence\DatabaseRecordNotifications;
use Modules\Notifications\Infrastructure\Persistence\DatabaseTechnicalAlertRecipientResolver;
use Modules\Tasks\Contracts\RecordTaskNotifications;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResolveTechnicalAlertRecipients::class, DatabaseTechnicalAlertRecipientResolver::class);
        // Tasks-owned mirror contract: Tasks (lower rank) consumes task
        // notifications without importing this module.
        $this->app->bind(RecordTaskNotifications::class, DatabaseRecordNotifications::class);
    }
}
