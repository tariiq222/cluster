# جدول الشاشات والـ Endpoints

> ملف مولّد آلياً من عقد `docs/contracts/api/openapi.yaml` وملف `docs/api/endpoints.md` وهوكات Orval في `apps/web/src/api/generated/cluster.ts`. لا تُعدَّل يدوياً.
> لإعادة التوليد: `python3 scripts/generate-endpoint-table.py`

- العمليات الحيّة (مسجّلة في `web.php`): **126**
- العمليات المخططة (موثّقة في العقد فقط): **41**
- الوحدات الحيّة: **12**
- الصفحات/الشاشات المربوطة: **43**
- هوكات Orval موجودة: **126**

أعمدة `الـ Request` و`الـ Response` بتنسيق مختصر: `Schema{الحقل*, الحقل?}` حيث `*` إلزامي و`?` اختياري؛ `enum{a,b}` قيم ثابتة؛ `X[]` مصفوفة؛ `?a, b` باراميترات استعلام.

## إعدادات المنصة

| # | الصفحة | Method | الـ Endpoint | الـ Request | الـ Response | Status Codes | Auth | Controller | Orval Hook |
|---|--------|--------|-------------|-------------|-------------|--------------|------|------------|-------------|
| 1 | التقويم الرسمي | `GET` | `/api/v1/platform-settings/calendars` | `?scope, cursor, limit` | `200: Collection` | 200, 401, 403 | جلسة | `Modules\PlatformSettings\Features\Calendars\Http\BusinessCalendarController::index` | `listPlatformSettingsCalendars` |
| 2 | التقويم الرسمي | `POST` | `/api/v1/platform-settings/calendars` | `{scope_type*, scope_id*} *` | `201: Entity` | 201, 400, 401, 403, 409 | جلسة + CSRF | `Modules\PlatformSettings\Features\Calendars\Http\BusinessCalendarController::store` | `createPlatformSettingsCalendar` |
| 3 | التقويم الرسمي | `PUT` | `/api/v1/platform-settings/calendars/{calendarId}/exceptions/{date}` | `{type*, ends_on?, is_working_day*, starts_at?, ends_at?, reason?} *` | `200: Entity` | 200, 400, 401, 403, 404, 409, 412 | جلسة + CSRF | `Modules\PlatformSettings\Features\Calendars\Http\BusinessCalendarController::setException` | `setPlatformSettingsCalendarException` |
| 4 | التقويم الرسمي | `POST` | `/api/v1/platform-settings/calendars/{calendarId}/publish` | `—` | `200: Entity` | 200, 401, 403, 404, 409, 412 | جلسة + CSRF | `Modules\PlatformSettings\Features\Calendars\Http\BusinessCalendarController::publish` | `publishPlatformSettingsCalendar` |
| 5 | التقويم الرسمي | `PUT` | `/api/v1/platform-settings/calendars/{calendarId}/weekdays/{weekday}` | `{is_working_day*, starts_at?, ends_at?} *` | `200: Entity` | 200, 400, 401, 403, 404, 409, 412 | جلسة + CSRF | `Modules\PlatformSettings\Features\Calendars\Http\BusinessCalendarController::setWeekday` | `setPlatformSettingsCalendarWeekday` |
| 6 | إعدادات المنصة الحالية | `GET` | `/api/v1/platform-settings/current` | `—` | `200: Entity` | 200, 401, 403, 404 | جلسة | `Modules\PlatformSettings\Features\Settings\Http\GetCurrentPlatformSettingsController` | `getCurrentPlatformSettings` |
| 7 | إصدارات إعدادات المنصة | `GET` | `/api/v1/platform-settings/versions` | `?cursor, limit` | `200: Collection` | 200, 401, 403 | جلسة | `Modules\PlatformSettings\Features\Settings\Http\ListSettingsVersionsController` | `listPlatformSettingsVersions` |
| 8 | إصدارات إعدادات المنصة | `POST` | `/api/v1/platform-settings/versions` | `{name*, based_on_version_id?} *` | `201: Entity` | 201, 400, 401, 403, 409 | جلسة + CSRF | `Modules\PlatformSettings\Features\Settings\Http\CreateSettingsVersionController` | `createPlatformSettingsDraft` |
| 9 | إصدارات إعدادات المنصة | `POST` | `/api/v1/platform-settings/versions/{versionId}/publish` | `—` | `200: Entity` | 200, 400, 401, 403, 404, 409, 412 | جلسة + CSRF | `Modules\PlatformSettings\Features\Settings\Http\PublishSettingsVersionController` | `publishPlatformSettingsVersion` |
| 10 | إصدارات إعدادات المنصة | `PUT` | `/api/v1/platform-settings/versions/{versionId}/settings/{settingKey}` | `{value_type*, value*} *` | `200: Entity` | 200, 400, 401, 403, 404, 409, 412 | جلسة + CSRF | `Modules\PlatformSettings\Features\Settings\Http\UpdateSettingsValueController` | `setPlatformSetting` |
| 11 | إصدارات إعدادات المنصة | `POST` | `/api/v1/platform-settings/versions/{versionId}/validate` | `—` | `200: Entity` | 200, 400, 401, 403, 404, 409, 412 | جلسة + CSRF | `Modules\PlatformSettings\Features\Settings\Http\ValidateSettingsVersionController` | `validatePlatformSettingsVersion` |

## الإشعارات

| # | الصفحة | Method | الـ Endpoint | الـ Request | الـ Response | Status Codes | Auth | Controller | Orval Hook |
|---|--------|--------|-------------|-------------|-------------|--------------|------|------------|-------------|
| 1 | الإشعارات | `GET` | `/api/v1/notifications` | `?cursor, limit` | `200: Notifications` | 200, 400, 401 | جلسة | `Modules\Notifications\Features\ListMyNotifications\Http\ListMyNotificationsController` | `listMyNotifications` |
| 2 | الإشعارات | `POST` | `/api/v1/notifications/{notificationId}/read` | `—` | `200: Entity` | 200, 400, 401, 403, 404 | جلسة + CSRF | `Modules\Notifications\Features\ListMyNotifications\Http\MarkNotificationReadController` | `markNotificationRead` |

## البحث

| # | الصفحة | Method | الـ Endpoint | الـ Request | الـ Response | Status Codes | Auth | Controller | Orval Hook |
|---|--------|--------|-------------|-------------|-------------|--------------|------|------------|-------------|
| 1 | البحث الشامل | `GET` | `/api/v1/search` | `?q, type, status, cursor, limit` | `200: Collection` | 200, 400, 401 | جلسة | `Modules\Search\Features\Search\Http\SearchController` | `search` |

## التقارير

| # | الصفحة | Method | الـ Endpoint | الـ Request | الـ Response | Status Codes | Auth | Controller | Orval Hook |
|---|--------|--------|-------------|-------------|-------------|--------------|------|------------|-------------|
| 1 | لوحات المعلومات | `GET` | `/api/v1/dashboards` | `?cursor, limit` | `200: Collection` | 200, 401, 403 | جلسة | `Modules\Reporting\Features\ListDashboards\Http\ListDashboardsController` | `listDashboards` |
| 2 | لوحات المعلومات | `GET` | `/api/v1/dashboards/{dashboardId}` | `?scope_id` | `200: Entity` | 200, 401, 403, 404 | جلسة | `Modules\Reporting\Features\Dashboards\Http\GetDashboardController` | `getDashboard` |
| 3 | تنزيل التصديرات | `GET` | `/api/v1/exports/{exportId}` | `—` | `200: {id*, resource_type*, code?, name?, title?, description?, status*, lifecycle_state?, classification*, version_number?, parent_id?, owner_organization_unit_id?, owner_user_id?, assignee_user_id?, source?, relation_type?, constraint_policy_key?, restriction_policy_key?, retention_policy_key?, retention_until?, due_at?, effective_from?, effective_to?, policy_version?, facts_version?, values?, lock_version*, created_at*, updated_at*, allowed_actions?}` | 200, 404, 406 | جلسة | `Modules\Reporting\Features\Exports\Http\DownloadExportController` | `getExport` |
| 4 | التقارير والمراقبة | `GET` | `/api/v1/reports` | `?cursor, limit` | `200: Collection` | 200, 401, 403 | جلسة | `Modules\Reporting\Features\ListReports\Http\ListReportsController` | `listReports` |
| 5 | التقارير والمراقبة | `GET` | `/api/v1/reports/{reportId}` | `?scope_id` | `200: Entity` | 200, 401, 403, 404 | جلسة | `Modules\Reporting\Features\Reports\Http\GetReportController` | `getReport` |
| 6 | التقارير والمراقبة | `POST` | `/api/v1/reports/{reportId}/exports` | `{format*, scope_id?} *` | `202: Entity` | 202, 400, 401, 403, 404, 409, 422 | جلسة + CSRF | `Modules\Reporting\Features\Exports\Http\CreateReportExportController` | `createReportExport` |

## الصلاحيات والوصول

| # | الصفحة | Method | الـ Endpoint | الـ Request | الـ Response | Status Codes | Auth | Controller | Orval Hook |
|---|--------|--------|-------------|-------------|-------------|--------------|------|------------|-------------|
| 1 | الصلاحيات والوصول (الإدارة) | `POST` | `/api/v1/authorization/access-decisions` | `{action*, access_context*, record_facts*} *` | `200: AccessDecision` | 200, 400, 401, 403, 404, 409 | جلسة + CSRF | `Modules\Authorization\Features\DecideAccess\Http\DecideAccessController` | `decideAccess` |
| 2 | الصلاحيات والوصول (الإدارة) | `GET` | `/api/v1/authorization/access-decisions/{decisionId}/explanation` | `—` | `200: AccessDecision` | 200, 401, 403, 404 | جلسة | `Modules\Authorization\Features\ExplainAccessDecision\Http\ExplainAccessDecisionController` | `explainAccessDecision` |
| 3 | الصلاحيات والوصول (الإدارة) | `GET` | `/api/v1/authorization/assignment-scope-targets` | `?scope_type, parent_scope_type, parent_scope_id, search, cursor, limit` | `200: {items*, next_cursor*}` | 200, 400, 401, 403, 422 | جلسة | `Modules\Authorization\Features\Administration\Http\ListAssignmentScopeTargetsController` | `listAuthorizationAssignmentScopeTargets` |
| 4 | الصلاحيات والوصول (الإدارة) | `GET` | `/api/v1/authorization/bootstrap` | `—` | `200: {status*, allowed_capabilities*, expires_at*}` | 200, 401, 403 | جلسة | `Modules\Authorization\Features\Bootstrap\Http\GetAuthorizationBootstrapController` | `getAuthorizationBootstrap` |
| 5 | الصلاحيات والوصول (الإدارة) | `POST` | `/api/v1/authorization/bootstrap/complete` | `{reason*} *` | `200: Entity` | 200, 400, 401, 403, 409, 412 | جلسة + CSRF | `Modules\Authorization\Features\Bootstrap\Http\CompleteAuthorizationBootstrapController` | `bootstrapComplete` |
| 6 | الصلاحيات والوصول (الإدارة) | `GET` | `/api/v1/authorization/{adminResource}` | `?cursor, limit` | `200: Collection` | 200, 401, 403 | جلسة | `Modules\Authorization\Features\Administration\Http\AuthorizationAdminController` | `listAuthorizationAdminResources` |
| 7 | الصلاحيات والوصول (الإدارة) | `POST` | `/api/v1/authorization/{adminResource}` | `{resource_type*, code*, name?, subject_user_id?, role_id?, scope_type?, scope_id?, start_at?, end_at?, policy_document?, capability_codes?} *` | `201: Entity` | 201, 400, 401, 403, 404, 409, 422 | جلسة + CSRF | `Modules\Authorization\Features\Administration\Http\AuthorizationAdminController` | `createAuthorizationAdminResource` |
| 8 | الصلاحيات والوصول (الإدارة) | `GET` | `/api/v1/authorization/{adminResource}/{resourceId}` | `—` | `200: Entity` | 200, 401, 403, 404 | جلسة | `Modules\Authorization\Features\Administration\Http\AuthorizationAdminController` | `getAuthorizationAdminResource` |
| 9 | الصلاحيات والوصول (الإدارة) | `PATCH` | `/api/v1/authorization/{adminResource}/{resourceId}` | `{name?, status?, end_at?, policy_document?, capability_codes?} *` | `200: Entity` | 200, 400, 401, 403, 404, 409, 412, 422 | جلسة + CSRF | `Modules\Authorization\Features\Administration\Http\AuthorizationAdminController` | `updateAuthorizationAdminResource` |
| 10 | الصلاحيات والوصول (الإدارة) | `POST` | `/api/v1/authorization/{adminResource}/{resourceId}/{authorizationAction}` | `(object \| {code?, name_ar?, name_en?, description_ar?, description_en?} \| {reason*}) ` | `200: Entity` | 200, 400, 401, 403, 404, 409, 412 | جلسة + CSRF | `Modules\Authorization\Features\Administration\Http\AuthorizationAdminController` | `transitionAuthorizationAdminResource` |

## المستندات

| # | الصفحة | Method | الـ Endpoint | الـ Request | الـ Response | Status Codes | Auth | Controller | Orval Hook |
|---|--------|--------|-------------|-------------|-------------|--------------|------|------------|-------------|
| 1 | المستندات | `GET` | `/api/v1/documents` | `?cursor, limit, classification` | `200: Collection` | 200, 401, 403 | جلسة | `Modules\Documents\Features\DocumentLifecycle\Http\ListDocumentsController` | `listDocuments` |
| 2 | المستندات | `POST` | `/api/v1/documents` | `{title*, description?, classification*, owner_organization_unit_id*, restriction_policy_key*} *` | `201: Entity` | 201, 400, 401, 403, 409 | جلسة + CSRF | `Modules\Documents\Features\DocumentLifecycle\Http\CreateDocumentController` | `createDocument` |
| 3 | رفع المستندات | `POST` | `/api/v1/documents/uploads` | `{purpose*, name*, description?, classification*, file_name*, content_type*, byte_size*, sha256*} *` | `201: DocumentUploadInitiated` | 201, 400, 401, 403, 404, 409, 500, 503 | جلسة + CSRF | `Modules\Documents\Features\Upload\Http\InitiateDocumentUploadController` | `initiateDocumentUpload` |
| 4 | رفع المستندات | `GET` | `/api/v1/documents/uploads/{uploadId}` | `—` | `200: DocumentUploadStatus` | 200, 400, 401, 403, 404, 409, 500, 503 | جلسة | `Modules\Documents\Features\Upload\Http\GetDocumentUploadStatusController` | `getDocumentUploadStatus` |
| 5 | رفع المستندات | `POST` | `/api/v1/documents/uploads/{uploadId}/complete` | `{sha256*, byte_size*} *` | `202: DocumentUploadCompleted` | 202, 400, 401, 403, 404, 409, 412, 500, 503 | جلسة + CSRF | `Modules\Documents\Features\Upload\Http\CompleteDocumentUploadController` | `completeDocumentUpload` |
| 6 | المستندات | `GET` | `/api/v1/documents/{documentId}` | `—` | `200: Entity` | 200, 401, 403, 404 | جلسة | `Modules\Documents\Features\DocumentLifecycle\Http\GetDocumentController` | `getDocument` |
| 7 | المستندات | `PATCH` | `/api/v1/documents/{documentId}` | `{title?, description?, classification?, classification_change_reason?} *` | `200: Entity` | 200, 400, 401, 403, 404, 409, 412 | جلسة + CSRF | `Modules\Documents\Features\DocumentLifecycle\Http\UpdateDocumentController` | `updateDocument` |
| 8 | المستندات | `GET` | `/api/v1/documents/{documentId}/download` | `—` | `—` | 302, 401, 403, 404, 409 | جلسة | `Modules\Documents\Features\DocumentDownload\Http\DownloadDocumentController` | `downloadDocument` |
| 9 | المستندات | `GET` | `/api/v1/documents/{documentId}/links` | `?cursor, limit` | `200: Collection` | 200, 401, 403, 404 | جلسة | `Modules\Documents\Features\DocumentLink\Http\ListDocumentLinksController` | `listDocumentLinks` |
| 10 | المستندات | `POST` | `/api/v1/documents/{documentId}/links` | `{source*, relation_type*, constraint_policy_key?} *` | `201: Entity` | 201, 400, 401, 403, 404, 409, 412 | جلسة + CSRF | `Modules\Documents\Features\DocumentLink\Http\LinkDocumentController` | `linkDocument` |
| 11 | المستندات | `GET` | `/api/v1/documents/{documentId}/versions` | `?cursor, limit` | `200: Collection` | 200, 401, 403, 404 | جلسة | `Modules\Documents\Features\DocumentVersion\Http\ListDocumentVersionsController` | `listDocumentVersions` |
| 12 | المستندات | `POST` | `/api/v1/documents/{documentId}/versions` | `{file_name*, content_type*, byte_size*, sha256*} *` | `201: DocumentUploadInitiated` | 201, 400, 401, 403, 404, 409, 500, 503 | جلسة + CSRF | `Modules\Documents\Features\DocumentVersion\Http\AddDocumentVersionController` | `addDocumentVersion` |
| 13 | المستندات | `POST` | `/api/v1/documents/{documentId}/{documentAction}` | `{reason*} *` | `200: Entity` | 200, 400, 401, 403, 404, 409, 412 | جلسة + CSRF | `Modules\Documents\Features\DocumentLifecycle\Http\TransitionDocumentController` | `transitionDocument` |
| 14 | المستندات | `POST` | `/api/v1/documents/{documentId}/{documentGrantType}-grant` | `{version_id*, purpose?} *` | `201: Entity` | 201, 400, 401, 403, 404, 409 | جلسة + CSRF | `Modules\Documents\Features\DocumentGrant\Http\CreateDocumentGrantController` | `createDocumentAccessGrant` |

## المنظمة

| # | الصفحة | Method | الـ Endpoint | الـ Request | الـ Response | Status Codes | Auth | Controller | Orval Hook |
|---|--------|--------|-------------|-------------|-------------|--------------|------|------------|-------------|
| 1 | التكليفات | `GET` | `/api/v1/organization/assignments` | `?cursor, limit, person_id` | `200: AssignmentCollection` | 200, 400, 401, 403 | جلسة | `Modules\Organization\Features\Assignment\Http\ListAssignmentsController` | `listAssignments` |
| 2 | التكليفات | `POST` | `/api/v1/organization/assignments` | `{person_id*, position_id*, start_at*, end_at?, is_primary?} *` | `201: AssignmentEntity` | 201, 400, 401, 403, 404, 409, 500 | جلسة + CSRF | `Modules\Organization\Features\Assignment\Http\CreateAssignmentController` | `createAssignment` |
| 3 | التكليفات | `POST` | `/api/v1/organization/assignments/{assignmentId}/end` | `{end_at*, reason*} *` | `200: AssignmentEntity` | 200, 400, 401, 403, 404, 409, 412, 500 | جلسة + CSRF | `Modules\Organization\Features\Assignment\Http\EndAssignmentController` | `endAssignment` |
| 4 | إعداد المنظمة (المجمّع) | `GET` | `/api/v1/organization/cluster` | `—` | `200: ClusterEntity` | 200, 401, 403, 404 | جلسة | `Modules\Organization\Features\CreateCluster\Http\GetClusterController` | `getCluster` |
| 5 | إعداد المنظمة (المجمّع) | `PATCH` | `/api/v1/organization/cluster` | `{name*, reason?} *` | `200: ClusterEntity` | 200, 400, 401, 403, 404, 412, 500 | جلسة + CSRF | `Modules\Organization\Features\UpdateCluster\Http\UpdateClusterController` | `updateCluster` |
| 6 | إعداد المنظمة (المجمّع) | `POST` | `/api/v1/organization/cluster` | `{code*, name*, name_en?} *` | `201: ClusterEntity` | 201, 400, 401, 403, 409 | جلسة + CSRF | `Modules\Organization\Features\CreateCluster\Http\CreateClusterController` | `createCluster` |
| 7 | المنشآت | `GET` | `/api/v1/organization/facilities` | `?cursor, limit` | `200: FacilityCollection` | 200, 401, 403 | جلسة | `Modules\Organization\Features\CreateFacility\Http\ListFacilitiesController` | `listFacilities` |
| 8 | المنشآت | `POST` | `/api/v1/organization/facilities` | `{cluster_id*, type_code*, code*, name*, name_en?} *` | `201: FacilityEntity` | 201, 400, 401, 403, 409 | جلسة + CSRF | `Modules\Organization\Features\CreateFacility\Http\CreateFacilityController` | `createFacility` |
| 9 | المنشآت | `GET` | `/api/v1/organization/facilities/{facilityId}` | `—` | `200: FacilityEntity` | 200, 400, 401, 403, 404 | جلسة | `Modules\Organization\Features\UpdateFacility\Http\GetFacilityController` | `getFacility` |
| 10 | المنشآت | `PATCH` | `/api/v1/organization/facilities/{facilityId}` | `(— \| —) *` | `200: FacilityEntity` | 200, 400, 401, 403, 404, 409, 412, 500 | جلسة + CSRF | `Modules\Organization\Features\UpdateFacility\Http\UpdateFacilityController` | `updateFacility` |
| 11 | استيراد الموظفين (رفع الملف) | `POST` | `/api/v1/organization/import-files` | `*` | `201: ImportFileReferenceEntity` | 201, 400, 401, 403, 500 | جلسة + CSRF | `Modules\Organization\Features\ImportFile\Http\UploadImportFileController` | `uploadOrganizationImportFile` |
| 12 | استيراد الموظفين (مراجعة) | `POST` | `/api/v1/organization/import-jobs` | `{quarantine_object_id*, template_code*, import_type*, notes?} *` | `202: ImportJobEntity` | 202, 400, 401, 403, 409, 500 | جلسة + CSRF | `Modules\Organization\Features\ImportJob\Http\SubmitImportJobController` | `submitOrganizationImport` |
| 13 | استيراد الموظفين (مراجعة) | `GET` | `/api/v1/organization/import-jobs/{jobId}` | `—` | `200: ImportJobEntity` | 200, 400, 401, 403, 404 | جلسة | `Modules\Organization\Features\ImportJob\Http\GetImportJobController` | `getOrganizationImport` |
| 14 | استيراد الموظفين (مراجعة) | `GET` | `/api/v1/organization/import-jobs/{jobId}/rows` | `?cursor, limit` | `200: ImportJobRowCollection` | 200, 400, 401, 403, 404 | جلسة | `Modules\Organization\Features\ImportJob\Http\ListImportJobRowsController` | `listOrganizationImportRows` |
| 15 | استيراد الموظفين (مراجعة) | `POST` | `/api/v1/organization/import-jobs/{jobId}/{jobAction}` | `{reason?} ` | `200: ImportJobEntity` | 200, 400, 401, 403, 404, 409, 412, 500 | جلسة + CSRF | `Modules\Organization\Features\ImportJob\Http\TransitionImportJobController` | `transitionOrganizationImport` |
| 16 | المسميات الوظيفية | `GET` | `/api/v1/organization/job-titles` | `?cursor, limit` | `200: JobTitleCollection` | 200, 400, 401, 403 | جلسة | `Modules\Organization\Features\JobTitle\Http\ListJobTitlesController` | `listJobTitles` |
| 17 | المسميات الوظيفية | `POST` | `/api/v1/organization/job-titles` | `{code*, title_ar*} *` | `201: JobTitleEntity` | 201, 400, 401, 403, 409 | جلسة + CSRF | `Modules\Organization\Features\JobTitle\Http\CreateJobTitleController` | `createJobTitle` |
| 18 | الأشخاص | `GET` | `/api/v1/organization/people` | `?cursor, limit` | `200: PersonCollection` | 200, 400, 401, 403 | جلسة | `Modules\Organization\Features\Person\Http\ListPeopleController` | `listPeople` |
| 19 | الأشخاص | `POST` | `/api/v1/organization/people` | `{employee_number*, display_name_ar*, display_name_en?, status*} *` | `201: PersonEntity` | 201, 400, 401, 403, 409, 500 | جلسة + CSRF | `Modules\Organization\Features\Person\Http\CreatePersonController` | `registerPerson` |
| 20 | الأشخاص | `GET` | `/api/v1/organization/people/{personId}` | `—` | `200: PersonEntity` | 200, 400, 401, 403, 404 | جلسة | `Modules\Organization\Features\Person\Http\GetPersonController` | `getPerson` |
| 21 | الأشخاص | `PATCH` | `/api/v1/organization/people/{personId}` | `{display_name_ar?, display_name_en?, status?} *` | `200: PersonEntity` | 200, 400, 401, 403, 404, 409, 412, 500 | جلسة + CSRF | `Modules\Organization\Features\Person\Http\UpdatePersonController` | `updatePerson` |
| 22 | الأشخاص | `GET` | `/api/v1/organization/people/{personId}/reference` | `—` | `200: {person_id*, person_version*, status*, display_name_ar*, display_name_en?}` | 200, 400, 401, 403, 404 | جلسة | `Modules\Organization\Features\Person\Http\GetPersonReferenceController` | `validatePersonReference` |
| 23 | الوظائف | `GET` | `/api/v1/organization/positions` | `?cursor, limit, unit_id` | `200: PositionCollection` | 200, 400, 401, 403 | جلسة | `Modules\Organization\Features\Position\Http\ListPositionsController` | `listPositions` |
| 24 | الوظائف | `POST` | `/api/v1/organization/positions` | `{organization_unit_id*, code*, title?, job_title_id?, manager_position_id?} *` | `201: PositionEntity` | 201, 400, 401, 403, 409, 500 | جلسة + CSRF | `Modules\Organization\Features\Position\Http\CreatePositionController` | `createPosition` |
| 25 | الوظائف | `GET` | `/api/v1/organization/positions/{positionId}` | `—` | `200: PositionEntity` | 200, 400, 401, 403, 404 | جلسة | `Modules\Organization\Features\Position\Http\GetPositionController` | `getPosition` |
| 26 | الوظائف | `PATCH` | `/api/v1/organization/positions/{positionId}` | `{organization_unit_id?, title?, job_title_id?, manager_position_id?, is_active?} *` | `200: PositionEntity` | 200, 400, 401, 403, 404, 409, 412, 500 | جلسة + CSRF | `Modules\Organization\Features\Position\Http\UpdatePositionController` | `updatePosition` |
| 27 | العلاقات الإشرافية | `GET` | `/api/v1/organization/supervisory-relationships` | `?cursor, limit` | `200: Collection` | 200, 401, 403 | جلسة | `Modules\Organization\Features\Assignment\Http\SupervisoryRelationshipController` | `listSupervisoryRelationships` |
| 28 | العلاقات الإشرافية | `POST` | `/api/v1/organization/supervisory-relationships` | `{source_unit_id*, target_unit_id*, relationship_type*, start_at*, end_at?, capability_codes*} *` | `201: Entity` | 201, 400, 401, 403, 404, 409 | جلسة + CSRF | `Modules\Organization\Features\Assignment\Http\SupervisoryRelationshipController` | `createSupervisoryRelationship` |
| 29 | التكليفات المؤقتة | `GET` | `/api/v1/organization/temporary-assignments` | `?organization_unit_id, cursor, limit` | `200: TemporaryAssignmentCollection` | 200, 400, 401, 403, 404 | جلسة | `Modules\Organization\Features\TemporaryAssignment\Http\ListTemporaryAssignmentsController` | `listTemporaryAssignments` |
| 30 | التكليفات المؤقتة | `POST` | `/api/v1/organization/temporary-assignments` | `{person_id*, organization_unit_id*, capability_codes*, start_at*, end_at*, reason*} *` | `201: TemporaryAssignmentEntity` | 201, 400, 401, 403, 404, 409, 500, 503 | جلسة + CSRF | `Modules\Organization\Features\TemporaryAssignment\Http\CreateTemporaryAssignmentController` | `createTemporaryAssignment` |
| 31 | التكليفات المؤقتة | `GET` | `/api/v1/organization/temporary-assignments/{temporaryAssignmentId}` | `—` | `200: TemporaryAssignmentEntity` | 200, 304, 400, 401, 404 | جلسة | `Modules\Organization\Features\TemporaryAssignment\Http\GetTemporaryAssignmentController` | `getTemporaryAssignment` |
| 32 | التكليفات المؤقتة | `POST` | `/api/v1/organization/temporary-assignments/{temporaryAssignmentId}/revoke` | `{reason*} *` | `200: TemporaryAssignmentEntity` | 200, 400, 401, 403, 404, 409, 412, 500 | جلسة + CSRF | `Modules\Organization\Features\TemporaryAssignment\Http\RevokeTemporaryAssignmentController` | `revokeTemporaryAssignment` |
| 33 | الوحدات التنظيمية | `GET` | `/api/v1/organization/units` | `?cursor, limit, parent_id` | `200: OrganizationUnitCollection` | 200, 400, 401, 403 | جلسة | `Modules\Organization\Features\OrganizationUnit\Http\ListOrganizationUnitsController` | `listOrganizationUnits` |
| 34 | الوحدات التنظيمية | `POST` | `/api/v1/organization/units` | `{cluster_id*, parent_id?, code*, name*, name_en?, type_code*} *` | `201: OrganizationUnitEntity` | 201, 400, 401, 403, 409, 500 | جلسة + CSRF | `Modules\Organization\Features\OrganizationUnit\Http\CreateOrganizationUnitController` | `createOrganizationUnit` |
| 35 | الوحدات التنظيمية | `POST` | `/api/v1/organization/units/reorder` | `{ordered_unit_ids*} *` | `200: Entity` | 200, 400, 401, 403, 409, 412 | جلسة + CSRF | `Modules\Organization\Features\OrganizationUnit\Http\ReorderOrganizationUnitsController` | `reorderOrganizationUnits` |
| 36 | الوحدات التنظيمية | `GET` | `/api/v1/organization/units/{unitId}` | `—` | `200: OrganizationUnitEntity` | 200, 400, 401, 403, 404 | جلسة | `Modules\Organization\Features\OrganizationUnit\Http\GetOrganizationUnitController` | `getOrganizationUnit` |
| 37 | الوحدات التنظيمية | `PATCH` | `/api/v1/organization/units/{unitId}` | `{parent_id?, name?, status?, reason?} *` | `200: OrganizationUnitEntity` | 200, 400, 401, 403, 404, 409, 412, 500 | جلسة + CSRF | `Modules\Organization\Features\OrganizationUnit\Http\UpdateOrganizationUnitController` | `updateOrganizationUnit` |

## المهام

| # | الصفحة | Method | الـ Endpoint | الـ Request | الـ Response | Status Codes | Auth | Controller | Orval Hook |
|---|--------|--------|-------------|-------------|-------------|--------------|------|------------|-------------|
| 1 | المهام | `GET` | `/api/v1/tasks` | `?cursor, limit, state, relationship` | `200: Collection` | 200, 401, 403 | جلسة | `Modules\Tasks\Features\Http\TaskController::index` | `listTasks` |
| 2 | المهام | `POST` | `/api/v1/tasks` | `{title*, description?, assignee_user_id?, priority?, due_at?, classification?, participant_user_ids?} *` | `201: Entity` | 201, 400, 401, 403, 404, 409 | جلسة + CSRF | `Modules\Tasks\Features\Http\TaskController::store` | `createTask` |
| 3 | المهام | `GET` | `/api/v1/tasks/{taskId}` | `—` | `200: Entity` | 200, 401, 403, 404 | جلسة | `Modules\Tasks\Features\Http\TaskController::show` | `getTask` |
| 4 | المهام | `PATCH` | `/api/v1/tasks/{taskId}` | `{title?, description?, assignee_user_id?, priority?, due_at?} *` | `200: Entity` | 200, 400, 401, 403, 404, 409, 412 | جلسة + CSRF | `Modules\Tasks\Features\Http\TaskController::update` | `updateTask` |
| 5 | المهام | `GET` | `/api/v1/tasks/{taskId}/comments` | `?cursor, limit` | `200: Collection` | 200, 401, 403, 404 | جلسة | `Modules\Tasks\Features\Http\TaskEngagementController::listComments` | `listTaskComments` |
| 6 | المهام | `POST` | `/api/v1/tasks/{taskId}/comments` | `{body*, mentioned_user_ids?} *` | `201: Entity` | 201, 400, 401, 403, 404, 409 | جلسة + CSRF | `Modules\Tasks\Features\Http\TaskEngagementController::addComment` | `addTaskComment` |
| 7 | المهام | `POST` | `/api/v1/tasks/{taskId}/documents` | `{document_id*} *` | `201: Entity` | 201, 400, 401, 403, 404, 409, 412, 422 | جلسة + CSRF | `Modules\Tasks\Features\DocumentLink\Http\TaskDocumentController::attach` | `attachTaskDocument` |
| 8 | المهام | `POST` | `/api/v1/tasks/{taskId}/participants` | `{user_id*, role?} *` | `200: Entity` | 200, 400, 401, 403, 404, 409, 412 | جلسة + CSRF | `Modules\Tasks\Features\Http\TaskEngagementController::addParticipant` | `addTaskParticipant` |
| 9 | المهام | `POST` | `/api/v1/tasks/{taskId}/{taskAction}` | `{reason?, note?} ` | `200: Entity` | 200, 400, 401, 403, 404, 409, 412 | جلسة + CSRF | `Modules\Tasks\Features\Http\TaskController::transition` | `transitionTask` |

## الهوية والمصادقة

| # | الصفحة | Method | الـ Endpoint | الـ Request | الـ Response | Status Codes | Auth | Controller | Orval Hook |
|---|--------|--------|-------------|-------------|-------------|--------------|------|------------|-------------|
| 1 | تسجيل الدخول (بيئة التطوير) | `POST` | `/api/v1/auth/login` | `{username*, password*} *` | `200: {data*}` | 200, 400, 401 | عام | `Modules\Identity\Features\DevelopmentFixtureLogin\Http\DevelopmentFixtureLoginController` | `login` |
| 2 | إدارة الحسابات | `GET` | `/api/v1/identity/accounts` | `?cursor, limit` | `200: UserAccountCollection` | 200, 400, 401, 403 | جلسة | `Modules\Identity\Features\UserAccount\Http\ListUserAccountsController` | `listUserAccounts` |
| 3 | إدارة الحسابات | `POST` | `/api/v1/identity/accounts` | `{person_id*, person_version*, username*} *` | `201: UserAccountEntity` | 201, 400, 401, 403, 409, 500 | جلسة + CSRF | `Modules\Identity\Features\UserAccount\Http\CreateUserAccountController` | `createUserAccount` |
| 4 | إدارة الحسابات | `GET` | `/api/v1/identity/accounts/{accountId}` | `—` | `200: UserAccountEntity` | 200, 400, 401, 403, 404 | جلسة | `Modules\Identity\Features\UserAccount\Http\GetUserAccountController` | `getUserAccount` |
| 5 | إدارة الحسابات | `POST` | `/api/v1/identity/accounts/{accountId}/activation` | `—` | `202: IdentityActivationIssued` | 202, 400, 401, 403, 409, 500 | جلسة + CSRF | `Modules\Identity\Features\Activation\Http\IssueActivationController` | `issueIdentityActivation` |
| 6 | إدارة الحسابات | `POST` | `/api/v1/identity/accounts/{accountId}/{accountAction}` | `{reason*} ` | `200: UserAccountEntity` | 200, 400, 401, 403, 404, 409, 412, 500 | جلسة + CSRF | `Modules\Identity\Features\UserAccount\Http\TransitionUserAccountController` | `transitionUserAccount` |
| 7 | تفعيل الحساب | `POST` | `/api/v1/identity/activation` | `{token*, password*, totp_code?} *` | `204: CorrelationNoContent` | 204, 400, 401, 422, 500 | عام | `Modules\Identity\Features\Activation\Http\ConsumeActivationController` | `consumeIdentityActivation` |
| 8 | تحديث رمز CSRF | `POST` | `/api/v1/identity/csrf` | `—` | `200: {data*}` | 200, 400, 401 | جلسة | `Modules\Identity\Features\Sessions\Http\RefreshIdentityCsrfController` | `refreshIdentityCsrf` |
| 9 | تسجيل الدخول | `POST` | `/api/v1/identity/login` | `{username*, password*, totp_code?} *` | `200: IdentitySession` | 200, 400, 401, 429, 500 | عام | `Modules\Identity\Features\Authentication\Http\IdentityLoginController` | `identityLogin` |
| 10 | تسجيل الخروج | `POST` | `/api/v1/identity/logout` | `—` | `204: IdentitySessionRevoked` | 204, 400, 401, 403, 500 | جلسة + CSRF | `Modules\Identity\Features\Authentication\Http\IdentityLogoutController` | `identityLogout` |
| 11 | الجلسة الحالية (الهوية) | `GET` | `/api/v1/identity/me` | `—` | `200: CurrentIdentity` | 200, 400, 401 | جلسة | `Modules\Identity\Features\Sessions\Http\GetCurrentIdentityController` | `getCurrentIdentity` |
| 12 | تغيير كلمة المرور | `POST` | `/api/v1/identity/password` | `{current_password*, new_password*, new_password_confirmation?} *` | `204: IdentitySessionRevoked` | 204, 400, 401, 403, 422 | جلسة + CSRF | `Modules\Identity\Features\Credentials\Http\ChangePasswordController` | `changeIdentityPassword` |
| 13 | الشريط العلوي / الحساب | `GET` | `/api/v1/me` | `—` | `200: Principal` | 200, 401 | جلسة | `Modules\Identity\Features\Sessions\Http\GetCurrentPrincipalController` | `getCurrentPrincipal` |
| 14 | اختيار نطاق الصلاحية | `PUT` | `/api/v1/me/scope` | `{scope_type*, scope_id*} *` | `200: {available_scopes*, effective_scope*}` | 200, 400, 401, 403, 409, 412 | جلسة + CSRF | `Modules\Identity\Features\Sessions\Http\SelectMyScopeController` | `selectMyScope` |
| 15 | تبديل نطاق الصلاحية | `GET` | `/api/v1/me/scopes` | `—` | `200: {available_scopes*, effective_scope*}` | 200, 401, 403 | جلسة | `Modules\Identity\Features\Sessions\Http\ListMyScopesController` | `listMyScopes` |

## داخلي (المستندات)

| # | الصفحة | Method | الـ Endpoint | الـ Request | الـ Response | Status Codes | Auth | Controller | Orval Hook |
|---|--------|--------|-------------|-------------|-------------|--------------|------|------------|-------------|
| 1 | داخلي (عامل المستندات) | `POST` | `/api/v1/internal/documents/versions/{versionId}/reconcile-promotion` | `—` | `200: DocumentVersionScan` | 200, 400, 401, 403, 404, 409, 500, 503 | داخلي (Worker Token) | `Modules\Documents\Features\DocumentVersion\Http\ReconcileDocumentPromotionController` | `reconcileDocumentPromotion` |
| 2 | داخلي (عامل المستندات) | `POST` | `/api/v1/internal/documents/versions/{versionId}/scan` | `—` | `202: DocumentVersionScan` | 202, 400, 401, 403, 404, 409, 500, 503 | داخلي (Worker Token) | `Modules\Documents\Features\DocumentVersion\Http\ScanDocumentVersionController` | `scanDocumentVersion` |

## سجل التدقيق

| # | الصفحة | Method | الـ Endpoint | الـ Request | الـ Response | Status Codes | Auth | Controller | Orval Hook |
|---|--------|--------|-------------|-------------|-------------|--------------|------|------------|-------------|
| 1 | سجل التدقيق | `GET` | `/api/v1/audit/events` | `?cursor, limit, source_module, action, actor_id, subject_type, subject_id, correlation_id, classification, occurred_from, occurred_to` | `200: AuditEventCollection` | 200, 400, 401, 403, 500 | جلسة | `Modules\Audit\Features\ListAuditEvents\Http\ListAuditEventsController` | `listAuditEvents` |
| 2 | سجل التدقيق | `GET` | `/api/v1/audit/events/{eventId}` | `—` | `200: AuditEventEntity` | 200, 400, 401, 404, 500 | جلسة | `Modules\Audit\Features\GetAuditEvent\Http\GetAuditEventController` | `getAuditEvent` |
| 3 | سجل التدقيق | `POST` | `/api/v1/audit/exports` | `{format*, reason*, filters?} *` | `201: {id*, principal_id*, facility_id*, query*, format*, snapshot_recorded_at*, status*, event_count*, expires_at*, created_at*}` | 201, 400, 401, 403, 409, 422, 500 | جلسة + CSRF | `Modules\Audit\Features\CreateAuditExport\Http\CreateAuditExportController` | `createAuditExport` |
| 4 | سجل التدقيق | `GET` | `/api/v1/audit/exports/{exportId}` | `—` | `200: {id*, principal_id*, facility_id*, query*, format*, snapshot_recorded_at*, status*, event_count*, expires_at*, created_at*}` | 200, 404, 500 | جلسة | `Modules\Audit\Features\GetAuditExport\Http\GetAuditExportController` | `getAuditExport` |
| 5 | سجل التدقيق | `GET` | `/api/v1/audit/exports/{exportId}/download` | `—` | `200: text/csv; charset=utf-8` | 200, 400, 401, 404, 410, 500 | جلسة | `Modules\Audit\Features\DownloadAuditExport\Http\DownloadAuditExportController` | `downloadAuditExport` |
| 6 | سجل التدقيق | `POST` | `/api/v1/audit/integrity-verifications` | `{stream_key*, first_sequence?, last_sequence?} *` | `201: {stream_key*, first_sequence*, last_sequence*, verified_event_count*, integrity_status*, checkpoint_id*}` | 201, 400, 401, 403, 409, 503, 500 | جلسة + CSRF | `Modules\Audit\Features\VerifyAuditIntegrity\Http\VerifyAuditIntegrityController` | `verifyAuditIntegrity` |

## عمليات المنصة

| # | الصفحة | Method | الـ Endpoint | الـ Request | الـ Response | Status Codes | Auth | Controller | Orval Hook |
|---|--------|--------|-------------|-------------|-------------|--------------|------|------------|-------------|
| 1 | سياسات التنبيهات | `GET` | `/api/v1/platform-operations/alert-policies` | `?cursor, limit` | `200: Collection` | 200, 401, 403 | جلسة | `Modules\PlatformSettings\Features\Alerts\Http\AlertPoliciesController::index` | `listPlatformAlertPolicies` |
| 2 | سياسات التنبيهات | `PATCH` | `/api/v1/platform-operations/alert-policies/{policyId}` | `{status?, severity?, channel?} *` | `200: Entity` | 200, 400, 401, 403, 404, 412 | جلسة + CSRF | `Modules\PlatformSettings\Features\Alerts\Http\AlertPoliciesController::update` | `updatePlatformAlertPolicy` |
| 3 | النسخ الاحتياطي | `GET` | `/api/v1/platform-operations/backups` | `—` | `200: Entity` | 200, 401, 403 | جلسة | `Modules\PlatformSettings\Features\Operations\Http\PlatformOperationsController::backups` | `getPlatformBackups` |
| 4 | النسخ الاحتياطي | `POST` | `/api/v1/platform-operations/backups` | `—` | `202: Entity` | 202, 400, 401, 403, 500 | جلسة + CSRF | `Modules\PlatformSettings\Features\Operations\Http\DispatchBackupController` | `dispatchPlatformBackup` |
| 5 | فحص الحالة (Health) | `GET` | `/api/v1/platform-operations/health` | `—` | `200: Entity` | 200, 401, 403 | جلسة | `Modules\PlatformSettings\Features\Operations\Http\PlatformOperationsController::health` | `getPlatformHealth` |
| 6 | نوافذ الصيانة | `GET` | `/api/v1/platform-operations/maintenance-windows` | `?cursor, limit` | `200: Collection` | 200, 401, 403 | جلسة | `Modules\PlatformSettings\Features\Maintenance\Http\MaintenanceWindowsController::index` | `listPlatformMaintenanceWindows` |
| 7 | نوافذ الصيانة | `POST` | `/api/v1/platform-operations/maintenance-windows` | `{starts_at*, ends_at?, message_ar*, message_en*} *` | `201: Entity` | 201, 400, 401, 403 | جلسة + CSRF | `Modules\PlatformSettings\Features\Maintenance\Http\MaintenanceWindowsController::store` | `schedulePlatformMaintenanceWindow` |
| 8 | نوافذ الصيانة | `POST` | `/api/v1/platform-operations/maintenance-windows/{windowId}/cancel` | `—` | `200: Entity` | 200, 401, 403, 404, 412 | جلسة + CSRF | `Modules\PlatformSettings\Features\Maintenance\Http\MaintenanceWindowsController::cancel` | `cancelPlatformMaintenanceWindow` |
| 9 | نظرة عامة على المنصة | `GET` | `/api/v1/platform-operations/overview` | `—` | `200: Entity` | 200, 401, 403 | جلسة | `Modules\PlatformSettings\Features\Operations\Http\GetPlatformOverviewController` | `getPlatformOperationsOverview` |
| 10 | طلبات الاستعادة | `POST` | `/api/v1/platform-operations/restore-requests` | `{backup_id*, reason*} *` | `202: Entity` | 202, 400, 401, 403 | جلسة + CSRF | `Modules\PlatformSettings\Features\Operations\Http\PlatformOperationsController::requestRestore` | `requestPlatformRestore` |
| 11 | طلبات الاستعادة | `POST` | `/api/v1/platform-operations/restore-requests/{requestId}/confirm` | `—` | `200: Entity` | 200, 401, 403, 404 | جلسة + CSRF | `Modules\PlatformSettings\Features\Operations\Http\PlatformOperationsController::confirmRestore` | `confirmPlatformRestore` |
| 12 | السجلات التقنية | `GET` | `/api/v1/platform-operations/technical-logs` | `?category, source, correlation_id, cursor, per_page` | `200: Collection` | 200, 401, 403, 503 | جلسة | `Modules\PlatformSettings\Features\Logs\Http\TechnicalLogsController::index` | `listPlatformTechnicalLogs` |
| 13 | السجلات التقنية | `POST` | `/api/v1/platform-operations/technical-logs/restore` | `{manifest_id*, reason*} *` | `202: Entity` | 202, 400, 401, 403, 503 | جلسة + CSRF | `Modules\PlatformSettings\Features\Logs\Http\TechnicalLogsController::restore` | `requestPlatformTechnicalLogsRestore` |

## ملحق: مسارات مخططة (عقد فقط — بلا تنفيذ)

هذه المسارات موثّقة في `openapi.yaml` لكنها غير مسجّلة في `apps/api/routes/web.php` ولا تملك وحدة تحكم أو شاشة.

| Method | الـ Endpoint | الـ Request | Status Codes |
|--------|-------------|-------------|--------------|
| `GET` | `/api/v1/workspace` | `—` | 200, 401 |
| `POST` | `/api/v1/authorization/bootstrap` | `{reason*} *` | 200, 400, 401, 403, 409, 412 |
| `GET` | `/api/v1/strategy/{strategyResource}` | `?cursor, limit` | 200, 401, 403 |
| `POST` | `/api/v1/strategy/{strategyResource}` | `{resource_type*, code*, name*, owner_organization_unit_id*, parent_id?, indicator_version_id?, period_id?, measured_value?, classification*} *` | 201, 400, 401, 403, 404, 409 |
| `GET` | `/api/v1/strategy/{strategyResource}/{resourceId}` | `—` | 200, 401, 403, 404 |
| `PATCH` | `/api/v1/strategy/{strategyResource}/{resourceId}` | `{name?, owner_organization_unit_id?, measured_value?, notes?} *` | 200, 400, 401, 403, 404, 409, 412 |
| `POST` | `/api/v1/strategy/{strategyResource}/{resourceId}/{strategyAction}` | `{reason?, signature?, decision_id?} ` | 200, 400, 401, 403, 404, 409, 412 |
| `GET` | `/api/v1/strategy/measurements/pending` | `?cursor, limit` | 200, 401, 403 |
| `GET` | `/api/v1/strategy/indicators/{indicatorId}/scorecard` | `?scope_id, period_id` | 200, 400, 401, 403, 404 |
| `GET` | `/api/v1/portfolio/{portfolioResource}` | `?cursor, limit` | 200, 401, 403 |
| `POST` | `/api/v1/portfolio/{portfolioResource}` | `{resource_type*, code*, name*, owner_organization_unit_id*, parent_id?, template_version_id?, planned_start_at?, planned_end_at?, classification*} *` | 201, 400, 401, 403, 404, 409 |
| `GET` | `/api/v1/portfolio/{portfolioResource}/{resourceId}` | `—` | 200, 401, 403, 404 |
| `PATCH` | `/api/v1/portfolio/{portfolioResource}/{resourceId}` | `{name?, owner_organization_unit_id?, planned_start_at?, planned_end_at?, description?} *` | 200, 400, 401, 403, 404, 409, 412 |
| `POST` | `/api/v1/portfolio/projects/{projectId}/{projectAction}` | `{reason?, signature?, decision_id?} ` | 200, 400, 401, 403, 404, 409, 412 |
| `GET` | `/api/v1/portfolio/projects/{projectId}/milestones` | `?cursor, limit` | 200, 401, 403, 404 |
| `POST` | `/api/v1/portfolio/projects/{projectId}/milestones` | `{code*, name*, due_at*, weight*, evidence_document_ids?} *` | 201, 400, 401, 403, 404, 409 |
| `POST` | `/api/v1/portfolio/projects/{projectId}/{snapshotType}-snapshots` | `{captured_at*, values*, override_reason?, override_expires_at?} *` | 201, 400, 401, 403, 404, 409 |
| `GET` | `/api/v1/portfolio/projects/{projectId}/indicator-links` | `?cursor, limit` | 200, 401, 403, 404 |
| `POST` | `/api/v1/portfolio/projects/{projectId}/indicator-links` | `{indicator_id*, scope_id*, period_id?, baseline*, expected_impact*} *` | 201, 400, 401, 403, 404, 409 |
| `GET` | `/api/v1/risk/{riskResource}` | `?cursor, limit` | 200, 401, 403 |
| `POST` | `/api/v1/risk/{riskResource}` | `{resource_type*, code*, name*, owner_organization_unit_id*, owner_user_id?, register_id?, risk_id?, next_review_at?, classification*} *` | 201, 400, 401, 403, 404, 409 |
| `GET` | `/api/v1/risk/risks/{riskId}` | `—` | 200, 401, 403, 404 |
| `PATCH` | `/api/v1/risk/risks/{riskId}` | `{name?, owner_user_id?, next_review_at?, description?} *` | 200, 400, 401, 403, 404, 409, 412 |
| `POST` | `/api/v1/risk/risks/{riskId}/{riskLifecycleAction}` | `{reason*, policy_version_id?, likelihood?, impact?, evidence_document_ids?} *` | 200, 400, 401, 403, 404, 409, 412 |
| `GET` | `/api/v1/risk/risks/{riskId}/indicator-readings` | `?cursor, limit` | 200, 401, 403, 404 |
| `POST` | `/api/v1/risk/risks/{riskId}/indicator-readings` | `{indicator_id*, observed_at*, value*, evidence_document_ids?} *` | 201, 400, 401, 403, 404, 409 |
| `GET` | `/api/v1/risk/heatmap` | `?scope_id` | 200, 400, 401, 403 |
| `GET` | `/api/v1/risk/reviews/due` | `?cursor, limit` | 200, 401, 403 |
| `GET` | `/api/v1/records-governance/retention-policy-versions` | `?cursor, limit` | 200, 401, 403 |
| `POST` | `/api/v1/records-governance/retention-policy-versions` | `{code*, name*, rules*} *` | 201, 400, 401, 403, 409 |
| `POST` | `/api/v1/records-governance/retention-policy-versions/{versionId}/publish` | `—` | 200, 401, 403, 404, 409, 412 |
| `GET` | `/api/v1/records-governance/governed-records` | `?cursor, limit, status` | 200, 401, 403 |
| `POST` | `/api/v1/records-governance/governed-records` | `{source*, retention_policy_version_id*, retention_start_at?} *` | 201, 400, 401, 403, 404, 409 |
| `GET` | `/api/v1/records-governance/governed-records/{governedRecordId}` | `—` | 200, 401, 403, 404 |
| `GET` | `/api/v1/records-governance/holds` | `?cursor, limit` | 200, 401, 403 |
| `POST` | `/api/v1/records-governance/holds` | `{scope_type*, scope_id*, reason*, expires_at?} *` | 201, 400, 401, 403, 404, 409 |
| `POST` | `/api/v1/records-governance/holds/{holdId}/release` | `{reason*} *` | 200, 400, 401, 403, 404, 409, 412 |
| `GET` | `/api/v1/records-governance/disposition-reviews` | `?cursor, limit, status` | 200, 401, 403 |
| `POST` | `/api/v1/records-governance/disposition-reviews` | `{governed_record_id*, decision*, reason*} *` | 201, 400, 401, 403, 404, 409 |
| `POST` | `/api/v1/records-governance/disposition-reviews/{reviewId}/confirm` | `{outcome*, source_confirmation_id*, confirmed_at*, evidence_document_id?, detail?} *` | 200, 400, 401, 403, 404, 409, 412 |
| `GET` | `/api/v1/up` | `—` | 200, 503 |
