<?php

namespace Modules\Authorization\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Authorization\Adapter\AuthorizeIdentityManagementAdapter;
use Modules\Authorization\Contracts\CountOperationsOfficeMembers;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Contracts\PersistAccessDecision;
use Modules\Authorization\Contracts\RecordSensitiveAccessEvent;
use Modules\Authorization\Contracts\ResolveActiveFacilityScopesForUser;
use Modules\Authorization\Contracts\ResolveAuthorizationSimulationFacts;
use Modules\Authorization\Infrastructure\BootstrapGatedDecideAccess;
use Modules\Authorization\Infrastructure\OrganizationDecideAccessAdapter;
use Modules\Authorization\Infrastructure\Persistence\CountOperationsOfficeMembers as DatabaseCountOperationsOfficeMembers;
use Modules\Authorization\Infrastructure\Persistence\DatabaseAuthorizationIdempotencyKeyLookup;
use Modules\Authorization\Infrastructure\Persistence\DatabasePersistAccessDecision;
use Modules\Authorization\Infrastructure\Persistence\DatabaseRecordSensitiveAccessEvent;
use Modules\Authorization\Infrastructure\Persistence\DatabaseResolveActiveFacilityScopesForUser;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Authorization\Infrastructure\Simulation\RegisteredAuthorizationSimulationFactsResolver;
use Modules\Identity\Contracts\AuthorizeIdentityManagement;
use Modules\Organization\Contracts\AuthorizationIdempotencyKeyLookup;
use Modules\Organization\Contracts\DecideAccess as OrganizationDecideAccess;
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
        $this->app->bind(AuthorizeIdentityManagement::class, AuthorizeIdentityManagementAdapter::class);
        $this->app->bind(ResolveAuthorizationSimulationFacts::class, fn ($app) => new RegisteredAuthorizationSimulationFactsResolver($app->tagged('authorization.simulation_facts')));
        // Bind the Organization-owned contracts to the Authorization-side adapters so
        // lower-ranked controllers never reference Authorization types directly.
        $this->app->bind(OrganizationDecideAccess::class, OrganizationDecideAccessAdapter::class);
        $this->app->bind(AuthorizationIdempotencyKeyLookup::class, DatabaseAuthorizationIdempotencyKeyLookup::class);
        $this->app->bind(ResolveActiveFacilityScopesForUser::class, DatabaseResolveActiveFacilityScopesForUser::class);
        $this->app->bind(RecordSensitiveAccessEvent::class, DatabaseRecordSensitiveAccessEvent::class);
    }
}
