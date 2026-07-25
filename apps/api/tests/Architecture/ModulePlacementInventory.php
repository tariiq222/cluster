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
            // Deferred: the owning module does not yet provide an equivalent HTTP controller or middleware.
            ['path' => 'app/Http/Controllers/Api/LinkDocumentController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Api/WorkDefinitionController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Api/WorkRecordLifecycleController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Api/WorkflowController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Authorization/AuthorizationAdminController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Authorization/CompleteAuthorizationBootstrapController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Authorization/DecideAccessController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Authorization/ExplainAccessDecisionController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Authorization/GetAuthorizationBootstrapController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/AddDocumentVersionController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/CompleteDocumentUploadController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/CreateDocumentController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/CreateDocumentGrantController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/DocumentAccessSupport.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/DownloadDocumentController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/GetDocumentController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/GetDocumentUploadStatusController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/InitiateDocumentUploadController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/LinkDocumentController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/ListDocumentLinksController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/ListDocumentVersionsController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/ListDocumentsController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/ReconcileDocumentPromotionController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/ScanDocumentVersionController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/TransitionDocumentController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Documents/UpdateDocumentController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/ChangePasswordController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/ConsumeActivationController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/CreateUserAccountController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/GetCurrentIdentityController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/GetCurrentPrincipalController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/GetUserAccountController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/IdentityIdempotency.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/IdentityLoginController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/IdentityLogoutController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/IssueActivationController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/ListMyScopesController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/ListUserAccountsController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/RefreshIdentityCsrfController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/SelectMyScopeController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Identity/TransitionUserAccountController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/CreateAssignmentController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/CreateClusterController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/CreateFacilityController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/CreateJobTitleController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/CreateOrganizationUnitController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/CreatePersonController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/CreatePositionController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/CreateTemporaryAssignmentController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/EndAssignmentController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/GetClusterController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/GetFacilityController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/GetImportJobController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/GetOrganizationUnitController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/GetPersonController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/GetPersonReferenceController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/GetPositionController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/GetTemporaryAssignmentController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/ListAssignmentsController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/ListFacilitiesController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/ListImportJobRowsController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/ListJobTitlesController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/ListOrganizationUnitsController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/ListPeopleController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/ListPositionsController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/ListTemporaryAssignmentsController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/ReorderOrganizationUnitsController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/RevokeTemporaryAssignmentController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/SubmitImportJobController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/SupervisoryRelationshipController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/TransitionImportJobController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/UpdateClusterController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/UpdateFacilityController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/UpdateOrganizationUnitController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/UpdatePersonController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Controllers/Organization/UpdatePositionController.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Http/Middleware/ConsumeSubmittedNotification.php', 'expiry' => '2027-04-25'],
            // Deferred: Reporting HTTP controllers stay at Modules/Reporting/Http/ because Stage 8 rewired them to consume the Organization contract instead of migrating them; the feature-folder layout (Modules/Reporting/Features/<Name>/Http/) remains out of scope until a follow-up migration.
            ['path' => 'Modules/Reporting/Http/ListDashboardsController.php', 'expiry' => '2027-04-25'],
            ['path' => 'Modules/Reporting/Http/ListReportsController.php', 'expiry' => '2027-04-25'],
            // Deferred: application integrations are cross-module glue pending extraction into their owning modules.
            ['path' => 'app/Integrations/Notifications/DatabaseTechnicalAlertRecipientResolver.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Integrations/WorkRecordAuthorizationFacts.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Integrations/WorkRecordWorkflowSourceAuthorizationFacts.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Integrations/PlatformOperations/LaravelPlatformHealthGateway.php', 'expiry' => '2027-04-25'],
            // Deferred: these organization support files are test fixtures and are out of migration scope.
            ['path' => 'app/Support/OrganizationHierarchyDemoSeeder.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Support/RealisticClusterFacilitiesSeeder.php', 'expiry' => '2027-04-25'],
            ['path' => 'app/Support/W12E2EFixtureSeeder.php', 'expiry' => '2027-04-25'],
        ];
    }
}
