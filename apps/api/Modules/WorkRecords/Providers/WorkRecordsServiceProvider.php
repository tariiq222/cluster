<?php

namespace Modules\WorkRecords\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Workflow\Contracts\ResolveWorkflowSourceAuthorizationFacts;
use Modules\WorkRecords\Application\WorkRecordAuthorizationFacts;
use Modules\WorkRecords\Application\WorkRecordWorkflowSourceAuthorizationFacts;
use Modules\WorkRecords\Contracts\ProvideWorkRecordsForIndexing;
use Modules\WorkRecords\Infrastructure\Persistence\DatabaseProvideWorkRecordsForIndexing;

final class WorkRecordsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag(WorkRecordAuthorizationFacts::class, 'documents.linked_resource_facts');
        $this->app->bind(ResolveWorkflowSourceAuthorizationFacts::class, WorkRecordWorkflowSourceAuthorizationFacts::class);
        $this->app->bind(ProvideWorkRecordsForIndexing::class, DatabaseProvideWorkRecordsForIndexing::class);
    }
}
