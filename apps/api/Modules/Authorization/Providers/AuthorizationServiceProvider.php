<?php

namespace Modules\Authorization\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Authorization\Contracts\CountOperationsOfficeMembers;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\PersistAccessDecision;
use Modules\Authorization\Contracts\ResolveAuthorizationSimulationFacts;
use Modules\Authorization\Infrastructure\BootstrapGatedDecideAccess;
use Modules\Authorization\Infrastructure\Persistence\CountOperationsOfficeMembers as DatabaseCountOperationsOfficeMembers;
use Modules\Authorization\Infrastructure\Persistence\DatabasePersistAccessDecision;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Authorization\Infrastructure\Simulation\RegisteredAuthorizationSimulationFactsResolver;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;

final class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PersistAccessDecision::class, DatabasePersistAccessDecision::class);
        $this->app->bind(CountOperationsOfficeMembers::class, DatabaseCountOperationsOfficeMembers::class);
        $this->app->bind(RbacAbacDecideAccess::class, fn ($app): RbacAbacDecideAccess => new RbacAbacDecideAccess(
            $app->make(GetActiveSupervisoryRelationships::class),
            $app->bound(PersistAccessDecision::class)
                ? $app->make(PersistAccessDecision::class)
                : null,
        ));
        $this->app->bind(DecideAccess::class, BootstrapGatedDecideAccess::class);
        $this->app->bind(ResolveAuthorizationSimulationFacts::class, RegisteredAuthorizationSimulationFactsResolver::class);
    }
}
