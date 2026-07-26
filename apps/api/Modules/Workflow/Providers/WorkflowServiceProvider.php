<?php

namespace Modules\Workflow\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Workflow\Contracts\AdvanceWorkflowStep;
use Modules\Workflow\Contracts\ResolveStepAssignee;
use Modules\Workflow\Contracts\WorkflowStepExists;
use Modules\Workflow\Domain\AssignmentRules;
use Modules\Workflow\Infrastructure\Persistence\DatabaseWorkflowStepExists;
use Modules\Workflow\Infrastructure\Persistence\WorkflowStepAdvancer;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AdvanceWorkflowStep::class, WorkflowStepAdvancer::class);
        $this->app->bind(ResolveStepAssignee::class, AssignmentRules::class);
        $this->app->bind(WorkflowStepExists::class, DatabaseWorkflowStepExists::class);
    }
}
