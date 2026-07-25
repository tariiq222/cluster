<?php

namespace App\Providers;

use App\Http\Authentication\SessionPrincipalResolver;
use Illuminate\Support\ServiceProvider;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Documents\Contracts\WorkerPrincipalResolver;
use Modules\Documents\Infrastructure\Security\ClamAvConfiguration;
use Modules\Documents\Infrastructure\Storage\PrivateDocumentDiskConfiguration;
use Modules\Documents\Infrastructure\Storage\S3\S3CompatibleConfiguration;
use Modules\Organization\Features\TemporaryAssignment\Console\ExpireTemporaryAssignmentsCommand;
use Modules\PlatformSettings\Features\Operations\Console\RunPlatformOperationsDispatchCommand;
use Shared\Contracts\TransactionalOutbox;
use Shared\Infrastructure\Outbox\DatabaseTransactionalOutbox;
use Shared\Infrastructure\Streams\LaravelRedisStreamTransport;
use Shared\Infrastructure\Streams\RedisStreamTransport;

class AppServiceProvider extends ServiceProvider
{
    /** Bootstrap shared application services. */
    private const MODULE_PROVIDERS = [
        \Modules\PlatformSettings\Providers\PlatformSettingsServiceProvider::class, \Modules\Identity\Providers\IdentityServiceProvider::class, \Modules\Authorization\Providers\AuthorizationServiceProvider::class, \Modules\Organization\Providers\OrganizationServiceProvider::class,
        \Modules\Documents\Providers\DocumentsServiceProvider::class, \Modules\Workflow\Providers\WorkflowServiceProvider::class, \Modules\Tasks\Providers\TasksServiceProvider::class, \Modules\Notifications\Providers\NotificationsServiceProvider::class,
        \Modules\Search\Providers\SearchServiceProvider::class, \Modules\Reporting\Providers\ReportingServiceProvider::class, \Modules\WorkRecords\Providers\WorkRecordsServiceProvider::class, \Modules\WorkDefinitions\Providers\WorkDefinitionsServiceProvider::class];

    public function register(): void
    {
        $this->app->bind(TransactionalOutbox::class, DatabaseTransactionalOutbox::class);
        $this->app->singleton(SessionPrincipalResolver::class);
        $this->app->bind(\Modules\PlatformSettings\Contracts\ResolveOrganizationScopeAncestry::class, \Modules\Organization\Infrastructure\Persistence\DatabaseResolveOrganizationScopeAncestry::class);
        $this->app->singleton(RedisStreamTransport::class, function (): RedisStreamTransport {
            $connection = $this->app->make('redis')->connection();

            return new LaravelRedisStreamTransport($connection);
        });
        foreach (self::MODULE_PROVIDERS as $providerClass) {
            $this->app->register($providerClass);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(config('module_migrations'));
        $this->commands([ExpireTemporaryAssignmentsCommand::class, RunPlatformOperationsDispatchCommand::class]);
        if ($this->authorizationProduction()) {
            $this->assertAuthorizationRuntimeSafe();
        }
        if ($this->documentsRuntimeEnabled()) {
            if (config('documents.storage.upload_endpoint_allowlist') === []) {
                throw new \RuntimeException('Documents upload endpoint allowlist is required outside testing.');
            }
            if ($this->documentsProduction()) {
                $this->assertDocumentsStorageRuntimeSafe();
            }
            $this->app->make(S3CompatibleConfiguration::class);
            $this->app->make(ClamAvConfiguration::class);
            $this->app->make(WorkerPrincipalResolver::class);
        }
    }

    private function documentsProduction(): bool
    {
        $arguments = $_SERVER['argv'] ?? [];

        return app()->environment('production') && ! app()->runningUnitTests()
            && ! in_array('test', $arguments, true) && ! in_array('config:clear', $arguments, true)
            && ! in_array('package:discover', $arguments, true) && ! str_contains(implode(' ', $arguments), 'phpstan')
            && ! str_contains(implode(' ', $arguments), 'phpunit');
    }

    private function authorizationProduction(): bool
    {
        return app()->environment('production') && ! app()->runningUnitTests();
    }

    private function documentsRuntimeEnabled(): bool
    {
        return $this->documentsProduction() || (app()->environment('testing') && config('documents.runtime.testing_enabled') === true);
    }

    private function assertAuthorizationRuntimeSafe(): void
    {
        $engine = $this->app->make(DecideAccess::class);
        if (! $engine->usesProductionEngine()) {
            throw new \RuntimeException('Production must bind DecideAccess to the RBAC+ABAC engine.');
        }
    }

    private function assertDocumentsStorageRuntimeSafe(): void
    {
        $quarantine = config('filesystems.disks.documents-quarantine');
        $available = config('filesystems.disks.documents-available');
        PrivateDocumentDiskConfiguration::assertRuntimeSafe(false,
            ['key' => $quarantine['key'] ?? null, 'secret' => $quarantine['secret'] ?? null, 'region' => $quarantine['region'] ?? null, 'bucket' => $quarantine['bucket'] ?? null, 'kms_key_id' => $quarantine['options']['SSEKMSKeyId'] ?? null], ['key' => $available['key'] ?? null, 'secret' => $available['secret'] ?? null, 'region' => $available['region'] ?? null, 'bucket' => $available['bucket'] ?? null, 'kms_key_id' => $available['options']['SSEKMSKeyId'] ?? null]);
    }
}
