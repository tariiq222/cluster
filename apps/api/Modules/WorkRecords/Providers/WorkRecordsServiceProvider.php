<?php

namespace Modules\WorkRecords\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Documents\Contracts\LinkedResourceAuthorizationFacts;
use Modules\Workflow\Contracts\ResolveWorkflowSourceAuthorizationFacts;
use Modules\WorkRecords\Application\WorkRecordAuthorizationFacts;
use Modules\WorkRecords\Application\WorkRecordWorkflowSourceAuthorizationFacts;

final class WorkRecordsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LinkedResourceAuthorizationFacts::class, WorkRecordAuthorizationFacts::class);
        $this->app->bind(ResolveWorkflowSourceAuthorizationFacts::class, WorkRecordWorkflowSourceAuthorizationFacts::class);
    }
}
