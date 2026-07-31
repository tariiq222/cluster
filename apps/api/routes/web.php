<?php

use App\Http\Middleware\EnforceWorkManagementFeature;
use App\Http\Middleware\IdentityCsrfMiddleware;
use App\Http\Middleware\IdentitySessionMiddleware;
use App\Http\Middleware\ProjectWorkRecordReadModels;
use App\Http\Middleware\RequireIdentitySessionPrincipal;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\Audit\Features\CreateAuditExport\Http\CreateAuditExportController;
use Modules\Audit\Features\DownloadAuditExport\Http\DownloadAuditExportController;
use Modules\Audit\Features\GetAuditEvent\Http\GetAuditEventController;
use Modules\Audit\Features\GetAuditExport\Http\GetAuditExportController;
use Modules\Audit\Features\ListAuditEvents\Http\ListAuditEventsController;
use Modules\Audit\Features\VerifyAuditIntegrity\Http\VerifyAuditIntegrityController;
use Modules\Authorization\Features\Administration\Http\AuthorizationAdminController;
use Modules\Authorization\Features\Administration\Http\ListAssignmentScopeTargetsController;
use Modules\Authorization\Features\Bootstrap\Http\CompleteAuthorizationBootstrapController;
use Modules\Authorization\Features\Bootstrap\Http\GetAuthorizationBootstrapController;
use Modules\Authorization\Features\DecideAccess\Http\DecideAccessController;
use Modules\Authorization\Features\ExplainAccessDecision\Http\ExplainAccessDecisionController;
use Modules\Documents\Features\DocumentDownload\Http\DownloadDocumentController;
use Modules\Documents\Features\DocumentGrant\Http\CreateDocumentGrantController;
use Modules\Documents\Features\DocumentLifecycle\Http\CreateDocumentController;
use Modules\Documents\Features\DocumentLifecycle\Http\GetDocumentController;
use Modules\Documents\Features\DocumentLifecycle\Http\ListDocumentsController;
use Modules\Documents\Features\DocumentLifecycle\Http\TransitionDocumentController;
use Modules\Documents\Features\DocumentLifecycle\Http\UpdateDocumentController;
use Modules\Documents\Features\DocumentLink\Http\LinkDocumentController as DocumentLinkController;
use Modules\Documents\Features\DocumentLink\Http\ListDocumentLinksController;
use Modules\Documents\Features\DocumentVersion\Http\AddDocumentVersionController;
use Modules\Documents\Features\DocumentVersion\Http\ListDocumentVersionsController;
use Modules\Documents\Features\DocumentVersion\Http\ReconcileDocumentPromotionController;
use Modules\Documents\Features\DocumentVersion\Http\ScanDocumentVersionController;
use Modules\Documents\Features\Upload\Http\CompleteDocumentUploadController;
use Modules\Documents\Features\Upload\Http\GetDocumentUploadStatusController;
use Modules\Documents\Features\Upload\Http\InitiateDocumentUploadController;
use Modules\Identity\Features\Activation\Http\ConsumeActivationController;
use Modules\Identity\Features\Activation\Http\IssueActivationController;
use Modules\Identity\Features\Authentication\Http\IdentityLoginController;
use Modules\Identity\Features\Authentication\Http\IdentityLogoutController;
use Modules\Identity\Features\Credentials\Http\ChangePasswordController;
use Modules\Identity\Features\DevelopmentFixtureLogin\Http\DevelopmentFixtureLoginController;
use Modules\Identity\Features\Sessions\Http\GetCurrentIdentityController;
use Modules\Identity\Features\Sessions\Http\GetCurrentPrincipalController;
use Modules\Identity\Features\Sessions\Http\ListMyScopesController;
use Modules\Identity\Features\Sessions\Http\RefreshIdentityCsrfController;
use Modules\Identity\Features\Sessions\Http\SelectMyScopeController;
use Modules\Identity\Features\UserAccount\Http\CreateUserAccountController;
use Modules\Identity\Features\UserAccount\Http\GetUserAccountController;
use Modules\Identity\Features\UserAccount\Http\ListUserAccountsController;
use Modules\Identity\Features\UserAccount\Http\TransitionUserAccountController;
use Modules\Notifications\Features\Http\ConsumeSubmittedNotification;
use Modules\Notifications\Features\ListMyNotifications\Http\ListMyNotificationsController;
use Modules\Notifications\Features\ListMyNotifications\Http\MarkNotificationReadController;
use Modules\Organization\Features\Assignment\Http\CreateAssignmentController;
use Modules\Organization\Features\Assignment\Http\EndAssignmentController;
use Modules\Organization\Features\Assignment\Http\ListAssignmentsController;
use Modules\Organization\Features\Assignment\Http\SupervisoryRelationshipController;
use Modules\Organization\Features\CreateCluster\Http\CreateClusterController;
use Modules\Organization\Features\CreateCluster\Http\GetClusterController;
use Modules\Organization\Features\CreateFacility\Http\CreateFacilityController;
use Modules\Organization\Features\CreateFacility\Http\ListFacilitiesController;
use Modules\Organization\Features\ImportJob\Http\GetImportJobController;
use Modules\Organization\Features\ImportJob\Http\ListImportJobRowsController;
use Modules\Organization\Features\ImportJob\Http\SubmitImportJobController;
use Modules\Organization\Features\ImportJob\Http\TransitionImportJobController;
use Modules\Organization\Features\JobTitle\Http\CreateJobTitleController;
use Modules\Organization\Features\JobTitle\Http\ListJobTitlesController;
use Modules\Organization\Features\OrganizationUnit\Http\CreateOrganizationUnitController;
use Modules\Organization\Features\OrganizationUnit\Http\GetOrganizationUnitController;
use Modules\Organization\Features\OrganizationUnit\Http\ListOrganizationUnitsController;
use Modules\Organization\Features\OrganizationUnit\Http\ReorderOrganizationUnitsController;
use Modules\Organization\Features\OrganizationUnit\Http\UpdateOrganizationUnitController;
use Modules\Organization\Features\Person\Http\CreatePersonController;
use Modules\Organization\Features\Person\Http\GetPersonController;
use Modules\Organization\Features\Person\Http\GetPersonReferenceController;
use Modules\Organization\Features\Person\Http\ListPeopleController;
use Modules\Organization\Features\Person\Http\UpdatePersonController;
use Modules\Organization\Features\Position\Http\CreatePositionController;
use Modules\Organization\Features\Position\Http\GetPositionController;
use Modules\Organization\Features\Position\Http\ListPositionsController;
use Modules\Organization\Features\Position\Http\UpdatePositionController;
use Modules\Organization\Features\TemporaryAssignment\Http\CreateTemporaryAssignmentController;
use Modules\Organization\Features\TemporaryAssignment\Http\GetTemporaryAssignmentController;
use Modules\Organization\Features\TemporaryAssignment\Http\ListTemporaryAssignmentsController;
use Modules\Organization\Features\TemporaryAssignment\Http\RevokeTemporaryAssignmentController;
use Modules\Organization\Features\UpdateCluster\Http\UpdateClusterController;
use Modules\Organization\Features\UpdateFacility\Http\GetFacilityController;
use Modules\Organization\Features\UpdateFacility\Http\UpdateFacilityController;
use Modules\PlatformSettings\Features\Alerts\Http\AlertPoliciesController;
use Modules\PlatformSettings\Features\Calendars\Http\BusinessCalendarController;
use Modules\PlatformSettings\Features\Logs\Http\TechnicalLogsController;
use Modules\PlatformSettings\Features\Maintenance\Http\MaintenanceWindowsController;
use Modules\PlatformSettings\Features\Operations\Http\DispatchBackupController;
use Modules\PlatformSettings\Features\Operations\Http\GetPlatformOverviewController;
use Modules\PlatformSettings\Features\Operations\Http\PlatformOperationsController;
use Modules\PlatformSettings\Features\Settings\Http\CreateSettingsVersionController;
use Modules\PlatformSettings\Features\Settings\Http\GetCurrentPlatformSettingsController;
use Modules\PlatformSettings\Features\Settings\Http\ListSettingsVersionsController;
use Modules\PlatformSettings\Features\Settings\Http\PublishSettingsVersionController;
use Modules\PlatformSettings\Features\Settings\Http\UpdateSettingsValueController;
use Modules\PlatformSettings\Features\Settings\Http\ValidateSettingsVersionController;
use Modules\Reporting\Features\Dashboards\Http\GetDashboardController;
use Modules\Reporting\Features\Exports\Http\CreateReportExportController;
use Modules\Reporting\Features\Exports\Http\DownloadExportController;
use Modules\Reporting\Features\ListDashboards\Http\ListDashboardsController;
use Modules\Reporting\Features\ListReports\Http\ListReportsController;
use Modules\Reporting\Features\Reports\Http\GetReportController;
use Modules\Search\Features\Search\Http\SearchController;
use Modules\Tasks\Features\DocumentLink\Http\TaskDocumentController;
use Modules\Tasks\Features\Http\TaskController;
use Modules\Tasks\Features\Http\TaskEngagementController;
use Modules\WorkDefinitions\Features\Definition\Http\WorkDefinitionController;
use Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController;
use Modules\WorkRecords\Features\DocumentLink\Http\WorkRecordDocumentLinkController;
use Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Http\GetAuthorizedWorkRecordController;
use Modules\WorkRecords\Features\Lifecycle\Http\WorkRecordLifecycleController;
use Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Http\ListAuthorizedWorkRecordsController;
use Modules\WorkRecords\Features\SubmitWorkRecord\Http\SubmitWorkRecordController;

Route::prefix('api/v1')->group(function (): void {
    Route::post('auth/login', DevelopmentFixtureLoginController::class)
        ->middleware('web')
        ->withoutMiddleware(PreventRequestForgery::class);
    Route::post('identity/login', IdentityLoginController::class);
    Route::post('identity/activation', ConsumeActivationController::class)->middleware('throttle:6,1');
    Route::get('identity/me', GetCurrentIdentityController::class)->middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class]);
    Route::post('identity/csrf', RefreshIdentityCsrfController::class)->middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class]);
    Route::get('me', GetCurrentPrincipalController::class)->middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class]);
    Route::get('me/scopes', ListMyScopesController::class)->middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class]);
    Route::put('me/scope', SelectMyScopeController::class)->middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class, IdentityCsrfMiddleware::class]);
    Route::middleware([
        IdentitySessionMiddleware::class,
        RequireIdentitySessionPrincipal::class,
        IdentityCsrfMiddleware::class,
    ])->group(function (): void {
        Route::post('identity/logout', IdentityLogoutController::class);
        Route::post('identity/password', ChangePasswordController::class);
        Route::post('identity/accounts/{accountId}/activation', IssueActivationController::class);
    });
    Route::middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class])->group(function (): void {
        Route::get('documents/uploads/{uploadId}', GetDocumentUploadStatusController::class);
        Route::get('documents/{documentId}/download', DownloadDocumentController::class);
        Route::get('organization/temporary-assignments', ListTemporaryAssignmentsController::class);
        Route::get('organization/temporary-assignments/{temporaryAssignmentId}', GetTemporaryAssignmentController::class);
    });
    Route::middleware([
        IdentitySessionMiddleware::class,
        RequireIdentitySessionPrincipal::class,
        IdentityCsrfMiddleware::class,
    ])->group(function (): void {
        Route::post('documents/uploads', InitiateDocumentUploadController::class);
        Route::post('documents/uploads/{uploadId}/complete', CompleteDocumentUploadController::class);
        Route::post('organization/temporary-assignments', CreateTemporaryAssignmentController::class);
        Route::post('organization/temporary-assignments/{temporaryAssignmentId}/revoke', RevokeTemporaryAssignmentController::class);
    });
    Route::post('internal/documents/versions/{versionId}/scan', ScanDocumentVersionController::class)->middleware('throttle:60,1');
    Route::post('internal/documents/versions/{versionId}/reconcile-promotion', ReconcileDocumentPromotionController::class)->middleware('throttle:60,1');
    Route::middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class])->group(function (): void {
        Route::get('notifications', ListMyNotificationsController::class);
        Route::post('notifications/{notificationId}/read', MarkNotificationReadController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('search', SearchController::class);
    });
    Route::middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class])->group(function (): void {
        Route::get('reports/{reportId}', GetReportController::class);
        Route::get('exports/{exportId}', DownloadExportController::class);
        Route::get('dashboards/{dashboardId}', GetDashboardController::class);
    });
    Route::middleware([
        IdentitySessionMiddleware::class,
        RequireIdentitySessionPrincipal::class,
        IdentityCsrfMiddleware::class,
    ])->group(function (): void {
        Route::post('reports/{reportId}/exports', CreateReportExportController::class);
    });
    Route::middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class])->group(function (): void {
        Route::get('organization/cluster', GetClusterController::class);
        Route::post('organization/cluster', CreateClusterController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::patch('organization/cluster', UpdateClusterController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('organization/facilities', ListFacilitiesController::class);
        Route::post('organization/facilities', CreateFacilityController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('organization/facilities/{facilityId}', GetFacilityController::class);
        Route::patch('organization/facilities/{facilityId}', UpdateFacilityController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('organization/units', ListOrganizationUnitsController::class);
        Route::post('organization/units', CreateOrganizationUnitController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::post('organization/units/reorder', ReorderOrganizationUnitsController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('organization/units/{unitId}', GetOrganizationUnitController::class);
        Route::patch('organization/units/{unitId}', UpdateOrganizationUnitController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('organization/job-titles', ListJobTitlesController::class);
        Route::post('organization/job-titles', CreateJobTitleController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('organization/positions', ListPositionsController::class);
        Route::post('organization/positions', CreatePositionController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('organization/positions/{positionId}', GetPositionController::class);
        Route::patch('organization/positions/{positionId}', UpdatePositionController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('organization/people', ListPeopleController::class);
        Route::post('organization/people', CreatePersonController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('organization/people/{personId}/reference', GetPersonReferenceController::class);
        Route::get('organization/people/{personId}', GetPersonController::class);
        Route::patch('organization/people/{personId}', UpdatePersonController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('organization/assignments', ListAssignmentsController::class);
        Route::post('organization/assignments', CreateAssignmentController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::post('organization/assignments/{assignmentId}/end', EndAssignmentController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('organization/supervisory-relationships', SupervisoryRelationshipController::class);
        Route::post('organization/supervisory-relationships', SupervisoryRelationshipController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::post('organization/import-jobs', SubmitImportJobController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('organization/import-jobs/{jobId}', GetImportJobController::class);
        Route::get('organization/import-jobs/{jobId}/rows', ListImportJobRowsController::class);
        Route::post('organization/import-jobs/{jobId}/{jobAction}', TransitionImportJobController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('identity/accounts', ListUserAccountsController::class);
        Route::post('identity/accounts', CreateUserAccountController::class)->middleware(IdentityCsrfMiddleware::class);
        Route::get('identity/accounts/{accountId}', GetUserAccountController::class);
        Route::post('identity/accounts/{accountId}/{accountAction}', TransitionUserAccountController::class)->middleware(IdentityCsrfMiddleware::class);
    });
    Route::middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class])->group(function (): void {
        Route::get('audit/events', ListAuditEventsController::class)->name('audit.events.index');
        Route::get('audit/events/{eventId}', GetAuditEventController::class)->name('audit.events.show');
        Route::get('audit/exports/{exportId}', GetAuditExportController::class)->name('audit.exports.show');
        Route::get('audit/exports/{exportId}/download', DownloadAuditExportController::class)->name('audit.exports.download');
        Route::get('platform-settings/current', GetCurrentPlatformSettingsController::class);
        Route::get('platform-operations/maintenance-windows', [MaintenanceWindowsController::class, 'index']);
        Route::get('platform-operations/alert-policies', [AlertPoliciesController::class, 'index']);
        Route::get('platform-operations/technical-logs', [TechnicalLogsController::class, 'index']);
        Route::get('platform-settings/versions', ListSettingsVersionsController::class);
        Route::get('platform-settings/calendars', [BusinessCalendarController::class, 'index']);
        Route::get('platform-operations/overview', GetPlatformOverviewController::class);
        Route::get('platform-operations/health', [PlatformOperationsController::class, 'health']);
        Route::get('platform-operations/backups', [PlatformOperationsController::class, 'backups']);
        Route::get('work-records', ListAuthorizedWorkRecordsController::class)->middleware(EnforceWorkManagementFeature::class);
        Route::get('work-records/{recordId}', GetAuthorizedWorkRecordController::class)->middleware(EnforceWorkManagementFeature::class);
        Route::get('authorization/access-decisions/{decisionId}/explanation', ExplainAccessDecisionController::class);
        Route::get('authorization/bootstrap', GetAuthorizationBootstrapController::class);
        Route::get('authorization/assignment-scope-targets', ListAssignmentScopeTargetsController::class);
        Route::get('authorization/{adminResource}', AuthorizationAdminController::class);
        Route::get('authorization/{adminResource}/{resourceId}', AuthorizationAdminController::class);
    });
    Route::middleware([
        IdentitySessionMiddleware::class,
        RequireIdentitySessionPrincipal::class,
        IdentityCsrfMiddleware::class,
    ])->group(function (): void {
        Route::post('audit/exports', CreateAuditExportController::class)->name('audit.exports.store');
        Route::post('audit/integrity-verifications', VerifyAuditIntegrityController::class)->name('audit.integrity-verifications.store');
        Route::post('platform-settings/versions', CreateSettingsVersionController::class);
        Route::put('platform-settings/versions/{versionId}/settings/{settingKey}', UpdateSettingsValueController::class);
        Route::post('platform-settings/versions/{versionId}/validate', ValidateSettingsVersionController::class);
        Route::post('platform-settings/versions/{versionId}/publish', PublishSettingsVersionController::class);
        Route::post('platform-settings/calendars', [BusinessCalendarController::class, 'store']);
        Route::put('platform-settings/calendars/{calendarId}/weekdays/{weekday}', [BusinessCalendarController::class, 'setWeekday'])->where('weekday', '[1-7]');
        Route::put('platform-settings/calendars/{calendarId}/exceptions/{date}', [BusinessCalendarController::class, 'setException'])->where('date', '\d{4}-\d{2}-\d{2}');
        Route::post('platform-settings/calendars/{calendarId}/publish', [BusinessCalendarController::class, 'publish']);
        Route::post('platform-operations/backups', DispatchBackupController::class);
        Route::post('platform-operations/restore-requests', [PlatformOperationsController::class, 'requestRestore']);
        Route::post('platform-operations/restore-requests/{requestId}/confirm', [PlatformOperationsController::class, 'confirmRestore']);
        Route::post('platform-operations/maintenance-windows', [MaintenanceWindowsController::class, 'store']);
        Route::post('platform-operations/maintenance-windows/{windowId}/cancel', [MaintenanceWindowsController::class, 'cancel'])->where('windowId', '[0-9a-fA-F-]+');
        Route::patch('platform-operations/alert-policies/{policyId}', [AlertPoliciesController::class, 'update'])->where('policyId', '[0-9a-fA-F-]+');
        Route::post('platform-operations/technical-logs/restore', [TechnicalLogsController::class, 'restore']);
        Route::post('work-records', SubmitWorkRecordController::class)->middleware([
            EnforceWorkManagementFeature::class,
            ProjectWorkRecordReadModels::class,
            ConsumeSubmittedNotification::class,
        ]);
        Route::post('work-records/{recordId}/{recordAction}', [WorkRecordLifecycleController::class, 'transition'])
            ->middleware([EnforceWorkManagementFeature::class, ProjectWorkRecordReadModels::class])
            ->whereIn('recordAction', ['submit', 'return', 'complete', 'complete-submission', 'cancel', 'archive']);
        Route::post('work-records/{recordId}/documents', WorkRecordDocumentLinkController::class)->middleware(EnforceWorkManagementFeature::class);
        Route::post('authorization/access-decisions', DecideAccessController::class);
        Route::post('authorization/bootstrap/complete', CompleteAuthorizationBootstrapController::class);
        Route::post('authorization/{adminResource}', AuthorizationAdminController::class);
        Route::patch('authorization/{adminResource}/{resourceId}', AuthorizationAdminController::class);
        Route::post('authorization/{adminResource}/{resourceId}/{authorizationAction}', AuthorizationAdminController::class);
    });
    Route::middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class])->group(function (): void {
        Route::get('work-definitions', [WorkDefinitionController::class, 'index'])->middleware(EnforceWorkManagementFeature::class);
        Route::get('work-definitions/{definitionId}', [WorkDefinitionController::class, 'show'])->middleware(EnforceWorkManagementFeature::class);
        Route::get('work-definitions/{definitionId}/versions', [WorkDefinitionController::class, 'versions'])->middleware(EnforceWorkManagementFeature::class);
        Route::get('work-definition-versions/{versionId}', [WorkDefinitionController::class, 'showVersionRoute'])->middleware(EnforceWorkManagementFeature::class);
        Route::get('workflow/definitions', [WorkflowController::class, 'definitions'])->middleware(EnforceWorkManagementFeature::class);
        Route::get('workflow/definitions/{definitionId}/versions', [WorkflowController::class, 'versions'])->middleware(EnforceWorkManagementFeature::class);
        Route::get('workflow/instances', [WorkflowController::class, 'instances'])->middleware(EnforceWorkManagementFeature::class);
        Route::get('workflow/instances/{instanceId}', [WorkflowController::class, 'showInstance'])->middleware(EnforceWorkManagementFeature::class);
        Route::get('workflow/steps', [WorkflowController::class, 'listInbox'])->middleware(EnforceWorkManagementFeature::class);
        Route::get('workflow/steps/{stepId}', [WorkflowController::class, 'showStep'])->middleware(EnforceWorkManagementFeature::class);
        Route::get('tasks', [TaskController::class, 'index']);
        Route::get('tasks/{taskId}/comments', [TaskEngagementController::class, 'listComments']);
        Route::get('tasks/{taskId}', [TaskController::class, 'show']);
        Route::get('documents', ListDocumentsController::class);
        Route::get('documents/{documentId}', GetDocumentController::class);
        Route::get('documents/{documentId}/versions', ListDocumentVersionsController::class);
        Route::get('documents/{documentId}/links', ListDocumentLinksController::class);
        Route::get('reports', ListReportsController::class);
        Route::get('dashboards', ListDashboardsController::class);
    });
    Route::middleware([
        IdentitySessionMiddleware::class,
        RequireIdentitySessionPrincipal::class,
        IdentityCsrfMiddleware::class,
    ])->group(function (): void {
        Route::post('work-definitions', [WorkDefinitionController::class, 'store'])->middleware(EnforceWorkManagementFeature::class);
        Route::post('work-definitions/{definitionId}/versions', [WorkDefinitionController::class, 'versions'])->middleware(EnforceWorkManagementFeature::class);
        Route::post('work-definition-versions/{versionId}/{versionAction}', [WorkDefinitionController::class, 'transition'])->middleware(EnforceWorkManagementFeature::class)->whereIn('versionAction', ['test', 'approve', 'sign', 'publish']);
        Route::post('workflow/definitions', [WorkflowController::class, 'definitions'])->middleware(EnforceWorkManagementFeature::class);
        Route::post('workflow/definitions/{definitionId}/versions', [WorkflowController::class, 'versions'])->middleware(EnforceWorkManagementFeature::class);
        Route::post('workflow/versions/{versionId}/{workflowLifecycleAction}', [WorkflowController::class, 'publish'])->middleware(EnforceWorkManagementFeature::class)->whereIn('workflowLifecycleAction', ['publish', 'test', 'approve', 'sign']);
        Route::post('workflow/instances', [WorkflowController::class, 'instances'])->middleware(EnforceWorkManagementFeature::class);
        Route::post('tasks', [TaskController::class, 'store']);
        Route::patch('tasks/{taskId}', [TaskController::class, 'update']);
        // removed: POST /tasks/from-step/{stepId}
        Route::post('tasks/{taskId}/participants', [TaskEngagementController::class, 'addParticipant']);
        Route::post('tasks/{taskId}/comments', [TaskEngagementController::class, 'addComment']);
        Route::post('tasks/{taskId}/documents', [TaskDocumentController::class, 'attach']);
        Route::post('tasks/{taskId}/{taskAction}', [TaskController::class, 'transition'])->whereIn('taskAction', ['start', 'block', 'unblock', 'complete', 'cancel']);
        Route::post('workflow/steps/{stepId}/decisions', [WorkflowController::class, 'decideStep'])->middleware(EnforceWorkManagementFeature::class);
        Route::post('workflow/steps/{stepId}/{stepAction}', [WorkflowController::class, 'actOnStep'])->middleware(EnforceWorkManagementFeature::class)->whereIn('stepAction', ['reassign', 'escalate']);
        Route::post('workflow/instances/{instanceId}/cancel', [WorkflowController::class, 'cancelInstance'])->middleware(EnforceWorkManagementFeature::class);
        Route::post('documents', CreateDocumentController::class);
        Route::patch('documents/{documentId}', UpdateDocumentController::class);
        Route::post('documents/{documentId}/versions', AddDocumentVersionController::class);
        Route::post('documents/{documentId}/links', DocumentLinkController::class);
        Route::post('documents/{documentId}/{documentAction}', TransitionDocumentController::class)->whereIn('documentAction', ['archive', 'unarchive', 'place-hold', 'release-hold']);
        Route::post('documents/{documentId}/{documentGrantType}-grant', CreateDocumentGrantController::class)->whereIn('documentGrantType', ['preview', 'download']);
    });
})->withoutMiddleware(['web', PreventRequestForgery::class]);
