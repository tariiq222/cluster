<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\PersistAccessDecision;
use Modules\Authorization\Infrastructure\FixtureFacilityDecision;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use Modules\Reporting\Features\GetAuthorizedDashboard\Handler\GetAuthorizedDashboardHandler;
use Modules\Reporting\Features\RunAuthorizedReport\Handler\RunAuthorizedReportHandler;
use Modules\Search\Features\SearchAccessibleRecords\Handler\SearchAccessibleRecordsHandler;
use Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Handler\GetAuthorizedWorkRecordHandler;
use Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Handler\ListAuthorizedWorkRecordsHandler;
use Modules\WorkRecords\Features\SubmitWorkRecord\Http\SubmitWorkRecordController;

abstract class TestCase extends BaseTestCase
{
    /**
     * Legacy HTTP adapter tests exercise consumer mechanics against the
     * deterministic facility fixture; the production container binds the real
     * RBAC+ABAC engine (asserted by a dedicated production-binding test), and
     * security-behavior tests opt into it explicitly via
     * {@see bindRealAccessDecision()}.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(DecideAccess::class, FixtureFacilityDecision::class);
    }

    protected function bindRealAccessDecision(): void
    {
        $factory = fn ($app): DecideAccess => new RbacAbacDecideAccess(
            $app->make(GetActiveSupervisoryRelationships::class),
            $app->make(PersistAccessDecision::class),
        );
        $this->app->bind(DecideAccess::class, $factory);
        $this->app->when([
            GetAuthorizedWorkRecordHandler::class,
            ListAuthorizedWorkRecordsHandler::class,
            SubmitWorkRecordController::class,
            SearchAccessibleRecordsHandler::class,
            RunAuthorizedReportHandler::class,
            GetAuthorizedDashboardHandler::class,
        ])->needs(DecideAccess::class)->give($factory);
        $this->app->forgetInstance(DecideAccess::class);
        $this->app->forgetInstance(GetAuthorizedWorkRecordHandler::class);
        $this->app->forgetInstance(ListAuthorizedWorkRecordsHandler::class);
    }
}
