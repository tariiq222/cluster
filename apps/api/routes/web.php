<?php

use App\Http\Controllers\Api\LinkDocumentController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskEngagementController;
use App\Http\Controllers\Api\WorkDefinitionController;
use App\Http\Controllers\Api\WorkflowController;
use App\Http\Controllers\Api\WorkRecordLifecycleController;
use App\Http\Controllers\Authorization\AuthorizationAdminController;
use App\Http\Controllers\Authorization\CompleteAuthorizationBootstrapController;
use App\Http\Controllers\Authorization\DecideAccessController;
use App\Http\Controllers\Authorization\ExplainAccessDecisionController;
use App\Http\Controllers\Authorization\GetAuthorizationBootstrapController;
use App\Http\Controllers\Documents\AddDocumentVersionController;
use App\Http\Controllers\Documents\CompleteDocumentUploadController;
use App\Http\Controllers\Documents\CreateDocumentController;
use App\Http\Controllers\Documents\CreateDocumentGrantController;
use App\Http\Controllers\Documents\DownloadDocumentController;
use App\Http\Controllers\Documents\GetDocumentController;
use App\Http\Controllers\Documents\GetDocumentUploadStatusController;
use App\Http\Controllers\Documents\InitiateDocumentUploadController;
use App\Http\Controllers\Documents\LinkDocumentController as DocumentLinkController;
use App\Http\Controllers\Documents\ListDocumentLinksController;
use App\Http\Controllers\Documents\ListDocumentsController;
use App\Http\Controllers\Documents\ListDocumentVersionsController;
use App\Http\Controllers\Documents\ReconcileDocumentPromotionController;
use App\Http\Controllers\Documents\ScanDocumentVersionController;
use App\Http\Controllers\Documents\TransitionDocumentController;
use App\Http\Controllers\Documents\UpdateDocumentController;
use App\Http\Controllers\Identity\ChangePasswordController;
use App\Http\Controllers\Identity\ConsumeActivationController;
use App\Http\Controllers\Identity\CreateUserAccountController;
use App\Http\Controllers\Identity\GetCurrentIdentityController;
use App\Http\Controllers\Identity\GetCurrentPrincipalController;
use App\Http\Controllers\Identity\GetUserAccountController;
use App\Http\Controllers\Identity\IdentityLoginController;
use App\Http\Controllers\Identity\IdentityLogoutController;
use App\Http\Controllers\Identity\IssueActivationController;
use App\Http\Controllers\Identity\ListMyScopesController;
use App\Http\Controllers\Identity\ListUserAccountsController;
use App\Http\Controllers\Identity\RefreshIdentityCsrfController;
use App\Http\Controllers\Identity\SelectMyScopeController;
use App\Http\Controllers\Identity\TransitionUserAccountController;
use App\Http\Controllers\Organization\CreateAssignmentController;
use App\Http\Controllers\Organization\CreateClusterController;
use App\Http\Controllers\Organization\CreateFacilityController;
use App\Http\Controllers\Organization\CreateJobTitleController;
use App\Http\Controllers\Organization\CreateOrganizationUnitController;
use App\Http\Controllers\Organization\CreatePersonController;
use App\Http\Controllers\Organization\CreatePositionController;
use App\Http\Controllers\Organization\CreateTemporaryAssignmentController;
use App\Http\Controllers\Organization\EndAssignmentController;
use App\Http\Controllers\Organization\GetClusterController;
use App\Http\Controllers\Organization\GetFacilityController;
use App\Http\Controllers\Organization\GetImportJobController;
use App\Http\Controllers\Organization\GetOrganizationUnitController;
use App\Http\Controllers\Organization\GetPersonController;
use App\Http\Controllers\Organization\GetPersonReferenceController;
use App\Http\Controllers\Organization\GetPositionController;
use App\Http\Controllers\Organization\GetTemporaryAssignmentController;
use App\Http\Controllers\Organization\ListAssignmentsController;
use App\Http\Controllers\Organization\ListFacilitiesController;
use App\Http\Controllers\Organization\ListImportJobRowsController;
use App\Http\Controllers\Organization\ListJobTitlesController;
use App\Http\Controllers\Organization\ListOrganizationUnitsController;
use App\Http\Controllers\Organization\ListPeopleController;
use App\Http\Controllers\Organization\ListPositionsController;
use App\Http\Controllers\Organization\ListTemporaryAssignmentsController;
use App\Http\Controllers\Organization\ReorderOrganizationUnitsController;
use App\Http\Controllers\Organization\RevokeTemporaryAssignmentController;
use App\Http\Controllers\Organization\SubmitImportJobController;
use App\Http\Controllers\Organization\SupervisoryRelationshipController;
use App\Http\Controllers\Organization\TransitionImportJobController;
use App\Http\Controllers\Organization\UpdateClusterController;
use App\Http\Controllers\Organization\UpdateFacilityController;
use App\Http\Controllers\Organization\UpdateOrganizationUnitController;
use App\Http\Controllers\Organization\UpdatePersonController;
use App\Http\Controllers\Organization\UpdatePositionController;
use App\Http\Middleware\ConsumeSubmittedNotification;
use App\Http\Middleware\IdentityCsrfMiddleware;
use App\Http\Middleware\IdentitySessionMiddleware;
use App\Http\Middleware\ProjectWorkRecordReadModels;
use App\Http\Middleware\RequireIdentitySessionPrincipal;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use Modules\Identity\Features\DevelopmentFixtureLogin\Http\DevelopmentFixtureLoginController;
use Modules\Notifications\Features\ListMyNotifications\Http\ListMyNotificationsController;
use Modules\Notifications\Features\ListMyNotifications\Http\MarkNotificationReadController;
use Modules\Reporting\Http\CreateReportExportController;
use Modules\Reporting\Http\DownloadExportController;
use Modules\Reporting\Http\GetDashboardController;
use Modules\Reporting\Http\GetReportController;
use Modules\Reporting\Http\ListDashboardsController;
use Modules\Reporting\Http\ListReportsController;
use Modules\Search\Http\SearchController;
use Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Http\GetAuthorizedWorkRecordController;
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
        IdentityCsrfMiddleware::class,
        RequireIdentitySessionPrincipal::class,
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
        Route::get('work-records', ListAuthorizedWorkRecordsController::class);
        Route::get('work-records/{recordId}', GetAuthorizedWorkRecordController::class);
        Route::get('authorization/access-decisions/{decisionId}/explanation', ExplainAccessDecisionController::class);
        Route::get('authorization/bootstrap', GetAuthorizationBootstrapController::class);
        Route::get('authorization/{adminResource}', AuthorizationAdminController::class);
        Route::get('authorization/{adminResource}/{resourceId}', AuthorizationAdminController::class);
    });
    Route::middleware([
        IdentitySessionMiddleware::class,
        RequireIdentitySessionPrincipal::class,
        IdentityCsrfMiddleware::class,
    ])->group(function (): void {
        Route::post('work-records', SubmitWorkRecordController::class)->middleware([
            ProjectWorkRecordReadModels::class,
            ConsumeSubmittedNotification::class,
        ]);
        Route::post('work-records/{recordId}/{recordAction}', [WorkRecordLifecycleController::class, 'transition'])
            ->whereIn('recordAction', ['submit', 'return', 'complete', 'complete-submission', 'cancel', 'archive'])
            ->middleware(ProjectWorkRecordReadModels::class);
        Route::post('work-records/{recordId}/documents', LinkDocumentController::class);
        Route::post('authorization/access-decisions', DecideAccessController::class);
        Route::post('authorization/bootstrap/complete', CompleteAuthorizationBootstrapController::class);
        Route::post('authorization/{adminResource}', AuthorizationAdminController::class);
        Route::patch('authorization/{adminResource}/{resourceId}', AuthorizationAdminController::class);
        Route::post('authorization/{adminResource}/{resourceId}/{authorizationAction}', AuthorizationAdminController::class);
    });
    Route::middleware([IdentitySessionMiddleware::class, RequireIdentitySessionPrincipal::class])->group(function (): void {
        Route::get('work-definitions', [WorkDefinitionController::class, 'index']);
        Route::get('work-definitions/{definitionId}', [WorkDefinitionController::class, 'show']);
        Route::get('work-definitions/{definitionId}/versions', [WorkDefinitionController::class, 'versions']);
        Route::get('work-definition-versions/{versionId}', [WorkDefinitionController::class, 'showVersionRoute']);
        Route::get('workflow/definitions', [WorkflowController::class, 'definitions']);
        Route::get('workflow/definitions/{definitionId}/versions', [WorkflowController::class, 'versions']);
        Route::get('workflow/instances', [WorkflowController::class, 'instances']);
        Route::get('workflow/instances/{instanceId}', [WorkflowController::class, 'showInstance']);
        Route::get('workflow/steps', [WorkflowController::class, 'listInbox']);
        Route::get('workflow/steps/{stepId}', [WorkflowController::class, 'showStep']);
        Route::get('tasks', [TaskController::class, 'index']);
        Route::get('tasks/{taskId}/comments', [TaskEngagementController::class, 'listComments']);
        Route::get('tasks/{taskId}', [TaskController::class, 'show']);
        Route::patch('tasks/{taskId}', [TaskController::class, 'update']);
        Route::get('documents', ListDocumentsController::class);
        Route::get('documents/{documentId}', GetDocumentController::class);
        Route::get('documents/{documentId}/versions', ListDocumentVersionsController::class);
        Route::get('documents/{documentId}/links', ListDocumentLinksController::class);
        Route::get('reports', ListReportsController::class);
        Route::get('dashboards', ListDashboardsController::class);
    });
    Route::middleware([
        IdentitySessionMiddleware::class,
        IdentityCsrfMiddleware::class,
        RequireIdentitySessionPrincipal::class,
    ])->group(function (): void {
        Route::post('work-definitions', [WorkDefinitionController::class, 'store']);
        Route::post('work-definitions/{definitionId}/versions', [WorkDefinitionController::class, 'versions']);
        Route::post('work-definition-versions/{versionId}/{versionAction}', [WorkDefinitionController::class, 'transition'])->whereIn('versionAction', ['test', 'approve', 'sign', 'publish']);
        Route::post('workflow/definitions', [WorkflowController::class, 'definitions']);
        Route::post('workflow/definitions/{definitionId}/versions', [WorkflowController::class, 'versions']);
        Route::post('workflow/versions/{versionId}/{workflowLifecycleAction}', [WorkflowController::class, 'publish'])->whereIn('workflowLifecycleAction', ['publish', 'test', 'approve', 'sign']);
        Route::post('workflow/instances', [WorkflowController::class, 'instances']);
        Route::post('tasks', [TaskController::class, 'store']);
        Route::post('tasks/from-step/{stepId}', [TaskController::class, 'fromStep']);
        Route::post('tasks/{taskId}/participants', [TaskEngagementController::class, 'addParticipant']);
        Route::post('tasks/{taskId}/comments', [TaskEngagementController::class, 'addComment']);
        Route::post('tasks/{taskId}/{workflowTaskAction}', [TaskController::class, 'transition'])->whereIn('workflowTaskAction', ['start', 'return', 'return-completion', 'submit-completion', 'complete', 'cancel']);
        Route::post('workflow/steps/{stepId}/decisions', [WorkflowController::class, 'decideStep']);
        Route::post('workflow/steps/{stepId}/{stepAction}', [WorkflowController::class, 'actOnStep'])->whereIn('stepAction', ['reassign', 'escalate']);
        Route::post('workflow/instances/{instanceId}/cancel', [WorkflowController::class, 'cancelInstance']);
        Route::post('documents', CreateDocumentController::class);
        Route::patch('documents/{documentId}', UpdateDocumentController::class);
        Route::post('documents/{documentId}/versions', AddDocumentVersionController::class);
        Route::post('documents/{documentId}/links', DocumentLinkController::class);
        Route::post('documents/{documentId}/{documentAction}', TransitionDocumentController::class)->whereIn('documentAction', ['archive', 'place-hold', 'release-hold']);
        Route::post('documents/{documentId}/{documentGrantType}-grant', CreateDocumentGrantController::class)->whereIn('documentGrantType', ['preview', 'download']);
    });
})->withoutMiddleware(['web', PreventRequestForgery::class]);
