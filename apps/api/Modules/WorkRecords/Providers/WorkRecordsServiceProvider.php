<?php

namespace Modules\WorkRecords\Providers;

use App\Integrations\WorkRecordAuthorizationFacts;
use Illuminate\Support\ServiceProvider;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;

final class WorkRecordsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LinkedResourceAuthorizationFacts::class, WorkRecordAuthorizationFacts::class);
    }
}
