<?php

namespace Tests\Architecture;

/**
 * Temporary, exact-path inventory of business code that predates the
 * app-placement guard. Each entry is paired with an ISO-8601 `expires_on`
 * date and a non-empty `reason` explaining why the placement exception
 * exists; the architecture test fails when:
 *
 *   1. the entry is missing a `reason` or `expires_on` key,
 *   2. the `reason` is empty,
 *   3. the `expires_on` date is invalid or already in the past,
 *   4. the declared `path` does not exist on disk.
 *
 * Entries must be removed as their owning module is migrated. Adding a
 * path requires an explicit architecture-test change and a 90-day expiry
 * with a written justification.
 *
 * The architecture test also enforces that every TABLE_OWNERS key maps to
 * a real `Schema::create` migration; virtual resources (in-memory read
 * models, projected tables that are not migrated) are NOT tracked here and
 * MUST NOT be added to TABLE_OWNERS. If a future virtual resource needs
 * to be inventoried, add a sibling constant `VIRTUAL_RESOURCES` and a
 * matching guard assertion rather than weakening TABLE_OWNERS.
 */
final class ModulePlacementInventory
{
    /**
     * @return list<array{path: string, reason: string, expires_on: string}>
     */
    public static function misplacedBusinessFiles(): array
    {
        return [
            // Deferred: middleware for the legacy notification consumption path until Notifications owns its own pipeline.
            [
                'path' => 'app/Http/Middleware/ConsumeSubmittedNotification.php',
                'reason' => 'Legacy notification consumption middleware is intentionally left under app/Http/Middleware until Notifications exposes its own inbox-driven pipeline; relocating it now would break the bootstrap wiring of the existing identity sessions. Tracked for the Notifications follow-up migration.',
                'expires_on' => '2027-04-25',
            ],
            // NOTE: the two previous Reporting HTTP controller entries
            // (Modules/Reporting/Http/ListDashboardsController.php and
            // Modules/Reporting/Http/ListReportsController.php) were dropped
            // because the corresponding files no longer exist; their real
            // locations are Modules/Reporting/Features/ListDashboards/Http/
            // ListDashboardsController.php and Modules/Reporting/Features/
            // ListReports/Http/ListReportsController.php, which already
            // comply with the feature-folder placement rule and therefore
            // require no exception. Re-adding them here would mask
            // controller-placement drift.
            // Deferred: these organization support files are test fixtures and are out of migration scope.
            [
                'path' => 'app/Support/OrganizationHierarchyDemoSeeder.php',
                'reason' => 'Test fixture seeder for the organization hierarchy exercise; it is referenced by Pest tests and stage-2 demo flows and is explicitly out of migration scope.',
                'expires_on' => '2027-04-25',
            ],
            [
                'path' => 'app/Support/RealisticClusterFacilitiesSeeder.php',
                'reason' => 'Test fixture seeder for the realistic cluster facilities exercise; it is referenced by Pest tests and stage-2 demo flows and is explicitly out of migration scope.',
                'expires_on' => '2027-04-25',
            ],
            [
                'path' => 'app/Support/W12E2EFixtureSeeder.php',
                'reason' => 'Test fixture seeder used by the W12 E2E runner; it is referenced by Pest tests and the stage-3 E2E walkthroughs and is explicitly out of migration scope.',
                'expires_on' => '2027-04-25',
            ],
        ];
    }
}
