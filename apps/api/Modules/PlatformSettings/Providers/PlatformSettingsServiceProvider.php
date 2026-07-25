<?php

namespace Modules\PlatformSettings\Providers;

use App\Http\Authentication\SessionPrincipalResolver;
use App\Integrations\PlatformOperations\CommandBackupOperationsGateway;
use App\Integrations\PlatformOperations\LaravelPlatformHealthGateway;
use App\Integrations\PlatformOperations\ObjectStorageTechnicalLogArchive;
use App\Integrations\PlatformOperations\TechnicalLogSourceUnavailable;
use App\Integrations\PlatformOperations\UnavailableTechnicalLogSource;
use App\Integrations\PlatformSettings\CatalogTechnicalAlertRecipientCapabilityValidator;
use App\Integrations\PlatformSettings\PlatformSettingsApi;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Infrastructure\RbacAbacDecideAccess;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Organization\Contracts\ResolveOrganizationScopeAncestry;
use Modules\PlatformSettings\Contracts\BackupOperationsGateway;
use Modules\PlatformSettings\Contracts\GetEffectivePlatformSettings;
use Modules\PlatformSettings\Contracts\PlatformHealthGateway;
use Modules\PlatformSettings\Contracts\PublishTechnicalAlert;
use Modules\PlatformSettings\Contracts\ResolveBusinessCalendar;
use Modules\PlatformSettings\Contracts\TechnicalLogArchive;
use Modules\PlatformSettings\Contracts\TechnicalLogArchiveStore;
use Modules\PlatformSettings\Contracts\TechnicalLogSource;
use Modules\PlatformSettings\Contracts\ValidateTechnicalAlertRecipientCapability;
use Modules\PlatformSettings\Features\Alerts\Handler\AlertPolicyHandler;
use Modules\PlatformSettings\Features\Calendars\Handler\BusinessCalendarHandler;
use Modules\PlatformSettings\Features\Maintenance\Handler\MaintenanceWindowHandler;
use Modules\PlatformSettings\Features\Settings\Handler\PlatformSettingsHandler;
use Modules\PlatformSettings\Infrastructure\Outbox\PlatformSettingsOutbox;
use Modules\PlatformSettings\Infrastructure\Persistence\DatabaseBusinessCalendars;
use Modules\PlatformSettings\Infrastructure\Persistence\DatabasePlatformSettings;
use Modules\PlatformSettings\Infrastructure\Persistence\DatabaseTechnicalLogArchiveStore;

final class PlatformSettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GetEffectivePlatformSettings::class, DatabasePlatformSettings::class);
        $this->app->bind(ResolveBusinessCalendar::class, fn (): ResolveBusinessCalendar => new DatabaseBusinessCalendars(
            fn (string $scopeType, string $scopeId): ?array => $this->app->make(ResolveOrganizationScopeAncestry::class)->ancestry($scopeType, $scopeId),
        ));
        $this->app->bind(BusinessCalendarHandler::class, fn (): BusinessCalendarHandler => new BusinessCalendarHandler(
            $this->app->make(ResolveBusinessCalendar::class),
        ));
        $this->app->bind(MaintenanceWindowHandler::class, fn (): MaintenanceWindowHandler => new MaintenanceWindowHandler);
        $this->app->bind(PlatformSettingsHandler::class, fn ($app): PlatformSettingsHandler => new PlatformSettingsHandler(
            $app->make(PlatformSettingsOutbox::class),
        ));
        $this->app->bind(TechnicalLogArchiveStore::class, DatabaseTechnicalLogArchiveStore::class);
        $this->app->bind(TechnicalLogSource::class, fn (): TechnicalLogSource => $this->resolveTechnicalLogSource());
        $this->app->bind(TechnicalLogArchive::class, function (): TechnicalLogArchive {
            try {
                return new ObjectStorageTechnicalLogArchive(
                    Storage::disk((string) config('platform_operations.logs.archive_disk', 'local')),
                    $this->app->make(TechnicalLogArchiveStore::class),
                );
            } catch (\Throwable) {
                return new class implements TechnicalLogArchive
                {
                    public function archive(\Modules\PlatformSettings\Domain\ArchiveBatch $batch): \Modules\PlatformSettings\Domain\ArchiveManifest
                    {
                        throw new TechnicalLogSourceUnavailable('Technical log archive is not configured.');
                    }

                    public function requestRestore(string $manifestId, string $actorId, string $reason): string
                    {
                        throw new TechnicalLogSourceUnavailable('Technical log archive is not configured.');
                    }
                };
            }
        });
        $this->app->bind(BackupOperationsGateway::class, fn (): BackupOperationsGateway => new CommandBackupOperationsGateway(config('platform_operations')));
        $this->app->bind(PlatformHealthGateway::class, fn (): PlatformHealthGateway => new LaravelPlatformHealthGateway($this->app->make(BackupOperationsGateway::class)));
        $this->app->bind(ValidateTechnicalAlertRecipientCapability::class, CatalogTechnicalAlertRecipientCapabilityValidator::class);
        $this->app->bind(PublishTechnicalAlert::class, AlertPolicyHandler::class);
        $this->app->when(PlatformSettingsApi::class)
            ->needs(ResolveDevelopmentFixturePrincipal::class)
            ->give(fn ($app) => $app->make(SessionPrincipalResolver::class));
        $this->app->when(PlatformSettingsApi::class)
            ->needs(DecideAccess::class)
            ->give(fn ($app) => $app->make(RbacAbacDecideAccess::class));
    }

    private function resolveTechnicalLogSource(): TechnicalLogSource
    {
        return new UnavailableTechnicalLogSource;
    }
}
