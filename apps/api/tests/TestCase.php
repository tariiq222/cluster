<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Infrastructure\FixtureFacilityDecision;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;

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
        $this->app->bind(DecideAccess::class, fn ($app): DecideAccess => new RbacAbacDecideAccess(
            $app->make(GetActiveSupervisoryRelationships::class),
        ));
    }
}
