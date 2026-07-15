<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom([
            base_path('Modules/Organization/Infrastructure/Persistence/Migrations/CreateDevelopmentFacilitiesTable.php'),
            base_path('Modules/Identity/Infrastructure/Persistence/Migrations/CreateDevelopmentFixtureAccountsTable.php'),
            base_path('Modules/WorkDefinitions/Infrastructure/Persistence/Migrations/CreateDevelopmentWorkTypeFixturesTable.php'),
        ]);
    }
}
