<?php

declare(strict_types=1);

namespace Modules\Tasks\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Tasks\Application\TaskAuthorizationFacts;

final class TasksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag(TaskAuthorizationFacts::class, 'documents.linked_resource_facts');
    }
}
