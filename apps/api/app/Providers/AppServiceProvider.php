<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Authorization\Contracts\DecideAccess;
use Modules\Authorization\Infrastructure\FixtureFacilityDecision;
use Modules\Identity\Contracts\ResolveDevelopmentFixturePrincipal;
use Modules\Identity\Features\ResolveDevelopmentFixturePrincipal\Http\DevelopmentFixturePrincipalResolver;
use Modules\Organization\Contracts\ResolveQuarantinedImport;
use Modules\Organization\Contracts\ValidatePersonReference;
use Modules\Organization\Infrastructure\Import\UnavailableQuarantinedImport;
use Modules\Organization\Infrastructure\Persistence\ValidatePersonReferenceFromPersistence;
use Modules\WorkDefinitions\Contracts\ResolvePublishedRequestFixture;
use Modules\WorkDefinitions\Infrastructure\ResolvePublishedRequestFixtureFromPersistence;
use Predis\Client;
use Shared\Infrastructure\Streams\PredisRedisStreamTransport;
use Shared\Infrastructure\Streams\RedisStreamTransport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DecideAccess::class, FixtureFacilityDecision::class);
        $this->app->bind(ResolvePublishedRequestFixture::class, ResolvePublishedRequestFixtureFromPersistence::class);
        $this->app->bind(ResolveQuarantinedImport::class, UnavailableQuarantinedImport::class);
        $this->app->bind(ValidatePersonReference::class, ValidatePersonReferenceFromPersistence::class);
        $this->app->singleton(ResolveDevelopmentFixturePrincipal::class, DevelopmentFixturePrincipalResolver::class);
        $this->app->singleton(RedisStreamTransport::class, function (): RedisStreamTransport {
            $url = config('database.redis.default.url');
            if (is_string($url) && $url !== '') {
                return new PredisRedisStreamTransport(new Client($url));
            }

            $parameters = [
                'scheme' => 'tcp',
                'host' => config('database.redis.default.host', '127.0.0.1'),
                'port' => (int) config('database.redis.default.port', 6379),
                'database' => (int) config('database.redis.default.database', 0),
            ];
            foreach (['username', 'password'] as $credential) {
                $value = config("database.redis.default.{$credential}");
                if (is_string($value) && $value !== '') {
                    $parameters[$credential] = $value;
                }
            }

            return new PredisRedisStreamTransport(new Client($parameters));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom([
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationCoreTables.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/SeedOrganizationFacilityTypes.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationTreeTables.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/SeedOrganizationUnitTypes.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationPeopleTable.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationWorkforceAssignmentsTable.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/CreateOrganizationZImportTables.php'),
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/CreateDevelopmentFacilitiesTable.php'),
            base_path('Modules/Identity/Infrastructure/Persistence/Migrations/CreateDevelopmentFixtureAccountsTable.php'),
            base_path('Modules/Identity/Infrastructure/Persistence/Migrations/CreateIdentityAccountTables.php'),
            base_path('Modules/WorkDefinitions/Infrastructure/Persistence/Migrations/CreateDevelopmentWorkTypeFixturesTable.php'),
            base_path('Modules/WorkRecords/Infrastructure/Persistence/Migrations/CreateWorkRecordsTable.php'),
            base_path('Modules/WorkRecords/Infrastructure/Outbox/Migrations/CreateOutboxTable.php'),
            base_path('Modules/Notifications/Infrastructure/Persistence/Migrations/CreateNotificationInboxTable.php'),
            base_path('Modules/Notifications/Infrastructure/Persistence/Migrations/CreateNotificationsTable.php'),
        ]);
    }
}
