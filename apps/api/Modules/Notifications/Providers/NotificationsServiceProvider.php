<?php

declare(strict_types=1);

namespace Modules\Notifications\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Notifications\Contracts\RecordNotifications;
use Modules\Notifications\Contracts\ResolveTechnicalAlertRecipients;
use Modules\Notifications\Infrastructure\Persistence\DatabaseRecordNotifications;
use Modules\Notifications\Infrastructure\Persistence\DatabaseTechnicalAlertRecipientResolver;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResolveTechnicalAlertRecipients::class, DatabaseTechnicalAlertRecipientResolver::class);
        $this->app->bind(RecordNotifications::class, DatabaseRecordNotifications::class);
    }
}
