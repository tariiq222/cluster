<?php

namespace Tests\Architecture;

final class ModulePlacementInventory
{
    /**
     * Temporary, exact-path inventory of business code that predates the
     * app-placement guard. Each entry is paired with an ISO-8601 expiry date;
     * the architecture test fails when an entry is older than its expiry.
     *
     * Entries must be removed as their owning module is migrated. Adding a
     * path requires an explicit architecture-test change and a 90-day expiry.
     *
     * @return list<array{path: string, expiry: string}>
     */
    public static function misplacedBusinessFiles(): array
    {
        return [
            // Deferred: middleware for the legacy notification consumption path until Notifications owns its own pipeline.
            ['path' => 'app/Http/Middleware/ConsumeSubmittedNotification.php', 'expiry' => '2027-04-25'],
            // Deferred: Reporting HTTP controllers stay at Modules/Reporting/Http/ because Stage 8 rewired them to consume the Organization contract instead of migrating them; the feature-folder layout (Modules/Reporting/Features/<Name>/Http/) remains out of scope until a follow-up migration.
            ['path' => 'Modules/Reporting/Http/ListDashboardsController.php', 'expiry' => '2027-04-25'],
            ['path' => 'Modules/Reporting/Http/ListReportsController.php', 'expiry' => '2027-04-25'],
            // Deferred: these organization support files are test fixtures and are out of migration scope.
            ['path' => 'app/Support/OrganizationHierarchyDemoSeeder.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Support/RealisticClusterFacilitiesSeeder.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Support/W12E2EFixtureSeeder.php', 'expiry' => '2027-04-25'],
        ];
    }
}
