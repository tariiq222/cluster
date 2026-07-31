<?php

namespace Modules\Organization\Providers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Modules\Organization\Contracts\GetActiveSupervisoryRelationships;
use Modules\Organization\Contracts\GetDefaultClusterId;
use Modules\Organization\Contracts\ListOrganizationScopeTargets;
use Modules\Organization\Contracts\ResolveAssignmentSupervisor;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\Organization\Contracts\ResolvePersonOrganizationScope;
use Modules\Organization\Contracts\ResolveQuarantinedImport;
use Modules\Organization\Contracts\ResolveScopeDescendants;
use Modules\Organization\Contracts\ValidatePersonReference;
use Modules\Organization\Features\ImportFile\Handler\ImportFileHandler;
use Modules\Organization\Features\TemporaryAssignment\Console\HandlerTemporaryAssignmentExpiration;
use Modules\Organization\Features\TemporaryAssignment\Console\RunTemporaryAssignmentExpiration;
use Modules\Organization\Features\TemporaryAssignment\Contracts\ValidateTemporaryAssignmentCapabilities;
use Modules\Organization\Features\TemporaryAssignment\Events\BuildTemporaryAssignmentEvent;
use Modules\Organization\Features\TemporaryAssignment\Events\TemporaryAssignmentEventFactory;
use Modules\Organization\Features\TemporaryAssignment\Http\DatabaseTemporaryAssignmentHttpGateway;
use Modules\Organization\Features\TemporaryAssignment\Http\TemporaryAssignmentHttpGateway;
use Modules\Organization\Infrastructure\Authorization\ConfiguredTemporaryAssignmentCapabilityValidator;
use Modules\Organization\Infrastructure\Import\StorageQuarantinedImport;
use Modules\Organization\Infrastructure\Persistence\DatabaseGetActiveSupervisoryRelationships;
use Modules\Organization\Infrastructure\Persistence\DatabaseGetDefaultClusterId;
use Modules\Organization\Infrastructure\Persistence\DatabaseListOrganizationScopeTargets;
use Modules\Organization\Infrastructure\Persistence\DatabaseResolveAssignmentSupervisor;
use Modules\Organization\Infrastructure\Persistence\DatabaseResolveOrganizationScopeAncestry;
use Modules\Organization\Infrastructure\Persistence\DatabaseResolvePersonOrganizationScope;
use Modules\Organization\Infrastructure\Persistence\DatabaseResolveScopeDescendants;
use Modules\Organization\Infrastructure\Persistence\ValidatePersonReferenceFromPersistence;

final class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ResolveQuarantinedImport::class, fn (): ResolveQuarantinedImport => new StorageQuarantinedImport(
            Storage::disk((string) config('organization.import.quarantine_disk', 'organization-quarantine')),
        ));
        $this->app->bind(ImportFileHandler::class, fn (): ImportFileHandler => new ImportFileHandler(
            Storage::disk((string) config('organization.import.quarantine_disk', 'organization-quarantine')),
        ));
        $this->app->bind(ValidatePersonReference::class, ValidatePersonReferenceFromPersistence::class);
        $this->app->bind(GetActiveSupervisoryRelationships::class, DatabaseGetActiveSupervisoryRelationships::class);
        $this->app->bind(GetDefaultClusterId::class, DatabaseGetDefaultClusterId::class);
        $this->app->bind(ResolvePersonOrganizationScope::class, DatabaseResolvePersonOrganizationScope::class);
        $this->app->bind(ResolveOrganizationScopeAncestry::class, DatabaseResolveOrganizationScopeAncestry::class);
        $this->app->bind(ResolveAssignmentSupervisor::class, DatabaseResolveAssignmentSupervisor::class);
        $this->app->bind(ListOrganizationScopeTargets::class, DatabaseListOrganizationScopeTargets::class);
        $this->app->bind(ResolveScopeDescendants::class, DatabaseResolveScopeDescendants::class);
        $this->app->bind(TemporaryAssignmentHttpGateway::class, DatabaseTemporaryAssignmentHttpGateway::class);
        $this->app->bind(RunTemporaryAssignmentExpiration::class, HandlerTemporaryAssignmentExpiration::class);
        $this->app->bind(BuildTemporaryAssignmentEvent::class, TemporaryAssignmentEventFactory::class);
        $this->app->bind(ValidateTemporaryAssignmentCapabilities::class, ConfiguredTemporaryAssignmentCapabilityValidator::class);
    }
}
