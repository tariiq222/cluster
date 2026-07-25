<?php

namespace Modules\Notifications\Providers;

use Modules\Notifications\Infrastructure\Persistence\DatabaseTechnicalAlertRecipientResolver;
use Illuminate\Support\ServiceProvider;
use Modules\Notifications\Contracts\ResolveTechnicalAlertRecipients;

final class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResolveTechnicalAlertRecipients::class, DatabaseTechnicalAlertRecipientResolver::class);
    }
}
