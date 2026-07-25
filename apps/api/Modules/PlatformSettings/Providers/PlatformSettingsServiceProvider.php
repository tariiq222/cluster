<?php

namespace Modules\PlatformSettings\Providers;

use App\Integrations\PlatformOperations\CommandBackupOperationsGateway;
use App\Integrations\PlatformOperations\ObjectStorageTechnicalLogArchive;
use App\Integrations\PlatformOperations\UnavailableTechnicalLogArchive;
use App\Integrations\PlatformOperations\UnavailableTechnicalLogSource;
use App\Integrations\PlatformSettings\CatalogTechnicalAlertRecipientCapabilityValidator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Modules\PlatformSettings\Contracts\BackupOperationsGateway;
use Modules\PlatformSettings\Contracts\GetEffectivePlatformSettings;
use Modules\PlatformSettings\Contracts\PlatformHealthGateway;
use Modules\PlatformSettings\Contracts\PublishTechnicalAlert;
use Modules\PlatformSettings\Contracts\ResolveBusinessCalendar;
use Modules\PlatformSettings\Contracts\ResolveOrganizationScopeAncestry;
use Modules\PlatformSettings\Contracts\TechnicalLogArchive;
use Modules\PlatformSettings\Contracts\TechnicalLogArchiveStore;
use Modules\PlatformSettings\Contracts\TechnicalLogSource;
use Modules\PlatformSettings\Contracts\ValidateTechnicalAlertRecipientCapability;
use Modules\PlatformSettings\Features\Alerts\Handler\AlertPolicyHandler;
use Modules\PlatformSettings\Features\Calendars\Handler\BusinessCalendarHandler;
use Modules\PlatformSettings\Features\Maintenance\Handler\MaintenanceWindowHandler;
use Modules\PlatformSettings\Features\Settings\Handler\PlatformSettingsHandler;
use Modules\PlatformSettings\Infrastructure\LaravelPlatformHealthGateway;
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
            } catch (\Throwable $exception) {
                return new UnavailableTechnicalLogArchive($exception);
            }
        });
        $this->app->bind(BackupOperationsGateway::class, fn (): BackupOperationsGateway => new CommandBackupOperationsGateway(config('platform_operations')));
        $this->app->bind(PlatformHealthGateway::class, fn (): PlatformHealthGateway => new LaravelPlatformHealthGateway($this->app->make(BackupOperationsGateway::class)));
        $this->app->bind(ValidateTechnicalAlertRecipientCapability::class, CatalogTechnicalAlertRecipientCapabilityValidator::class);
        $this->app->bind(PublishTechnicalAlert::class, AlertPolicyHandler::class);
    }

    private function resolveTechnicalLogSource(): TechnicalLogSource
    {
        return new UnavailableTechnicalLogSource;
    }
}
