---
doc_id: API-RBAC-001
title: RBAC Matrix
type: engineering
status: accepted
version: 1.0.0
date: 2026-07-22
owner: مكتب هندسة البرمجيات
reviewers:
  - مكتب هندسة المنصة
  - مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير routes
sources:
  - docs/api/endpoints.md
  - docs/contracts/api/openapi.yaml
references:
  - docs/api/endpoints.md
  - docs/contracts/api/openapi.yaml
---
# RBAC Matrix

> Generated from the live route inventory; anchors are stable row identifiers.

### row-101

- **Method:** `POST`
- **Path:** `/api/v1/auth/login`
- **Endpoint tag:** `api-v1-auth-login:post:developmentfixturelogincontroller`
- **Middleware:** `web`
- **Session:** `no`
- **Principal:** `no`
- **CSRF:** `no`
- **Throttle:** `none`

### row-104

- **Method:** `POST`
- **Path:** `/api/v1/identity/login`
- **Endpoint tag:** `api-v1-identity-login:post:identitylogincontroller`
- **Middleware:** `none`
- **Session:** `no`
- **Principal:** `no`
- **CSRF:** `no`
- **Throttle:** `none`

### row-105

- **Method:** `POST`
- **Path:** `/api/v1/identity/activation`
- **Endpoint tag:** `api-v1-identity-activation:post:consumeactivationcontroller`
- **Middleware:** `throttle:6,1`
- **Session:** `no`
- **Principal:** `no`
- **CSRF:** `no`
- **Throttle:** `6,1`

### row-106

- **Method:** `GET`
- **Path:** `/api/v1/identity/me`
- **Endpoint tag:** `api-v1-identity-me:get:getcurrentidentitycontroller`
- **Middleware:** `identity_session → require_identity_session_principal`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `no`
- **Throttle:** `none`

### row-107

- **Method:** `POST`
- **Path:** `/api/v1/identity/csrf`
- **Endpoint tag:** `api-v1-identity-csrf:post:refreshidentitycsrfcontroller`
- **Middleware:** `identity_session → require_identity_session_principal`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `no`
- **Throttle:** `none`

### row-108

- **Method:** `GET`
- **Path:** `/api/v1/me`
- **Endpoint tag:** `api-v1-me:get:getcurrentprincipalcontroller`
- **Middleware:** `identity_session → require_identity_session_principal`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `no`
- **Throttle:** `none`

### row-109

- **Method:** `GET`
- **Path:** `/api/v1/me/scopes`
- **Endpoint tag:** `api-v1-me-scopes:get:listmyscopescontroller`
- **Middleware:** `identity_session → require_identity_session_principal`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `no`
- **Throttle:** `none`

### row-110

- **Method:** `PUT`
- **Path:** `/api/v1/me/scope`
- **Endpoint tag:** `api-v1-me-scope:put:selectmyscopecontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-116

- **Method:** `POST`
- **Path:** `/api/v1/identity/logout`
- **Endpoint tag:** `api-v1-identity-logout:post:identitylogoutcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-117

- **Method:** `POST`
- **Path:** `/api/v1/identity/password`
- **Endpoint tag:** `api-v1-identity-password:post:changepasswordcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-118

- **Method:** `POST`
- **Path:** `/api/v1/identity/accounts/{accountId}/activation`
- **Endpoint tag:** `api-v1-identity-accounts-accountId-activation:post:issueactivationcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-121

- **Method:** `GET`
- **Path:** `/api/v1/documents/uploads/{uploadId}`
- **Endpoint tag:** `api-v1-documents-uploads-uploadId:get:getdocumentuploadstatuscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-122

- **Method:** `GET`
- **Path:** `/api/v1/documents/{documentId}/download`
- **Endpoint tag:** `api-v1-documents-documentId-download:get:downloaddocumentcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-123

- **Method:** `GET`
- **Path:** `/api/v1/organization/temporary-assignments`
- **Endpoint tag:** `api-v1-organization-temporary-assignments:get:listtemporaryassignmentscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-124

- **Method:** `GET`
- **Path:** `/api/v1/organization/temporary-assignments/{temporaryAssignmentId}`
- **Endpoint tag:** `api-v1-organization-temporary-assignments-temporaryAssignmentId:get:gettemporaryassignmentcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-131

- **Method:** `POST`
- **Path:** `/api/v1/documents/uploads`
- **Endpoint tag:** `api-v1-documents-uploads:post:initiatedocumentuploadcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-132

- **Method:** `POST`
- **Path:** `/api/v1/documents/uploads/{uploadId}/complete`
- **Endpoint tag:** `api-v1-documents-uploads-uploadId-complete:post:completedocumentuploadcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-133

- **Method:** `POST`
- **Path:** `/api/v1/organization/temporary-assignments`
- **Endpoint tag:** `api-v1-organization-temporary-assignments:post:createtemporaryassignmentcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-134

- **Method:** `POST`
- **Path:** `/api/v1/organization/temporary-assignments/{temporaryAssignmentId}/revoke`
- **Endpoint tag:** `api-v1-organization-temporary-assignments-temporaryAssignmentId-revoke:post:revoketemporaryassignmentcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-136

- **Method:** `POST`
- **Path:** `/api/v1/internal/documents/versions/{versionId}/scan`
- **Endpoint tag:** `api-v1-internal-documents-versions-versionId-scan:post:scandocumentversioncontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf → throttle:60,1`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `60,1`

### row-137

- **Method:** `POST`
- **Path:** `/api/v1/internal/documents/versions/{versionId}/reconcile-promotion`
- **Endpoint tag:** `api-v1-internal-documents-versions-versionId-reconcile-promotion:post:reconciledocumentpromotioncontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf → throttle:60,1`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `60,1`

### row-139

- **Method:** `GET`
- **Path:** `/api/v1/notifications`
- **Endpoint tag:** `api-v1-notifications:get:listmynotificationscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-140

- **Method:** `POST`
- **Path:** `/api/v1/notifications/{notificationId}/read`
- **Endpoint tag:** `api-v1-notifications-notificationId-read:post:marknotificationreadcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-141

- **Method:** `GET`
- **Path:** `/api/v1/search`
- **Endpoint tag:** `api-v1-search:get:searchcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-144

- **Method:** `GET`
- **Path:** `/api/v1/reports/{reportId}`
- **Endpoint tag:** `api-v1-reports-reportId:get:getreportcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-145

- **Method:** `GET`
- **Path:** `/api/v1/exports/{exportId}`
- **Endpoint tag:** `api-v1-exports-exportId:get:downloadexportcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-146

- **Method:** `GET`
- **Path:** `/api/v1/dashboards/{dashboardId}`
- **Endpoint tag:** `api-v1-dashboards-dashboardId:get:getdashboardcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-153

- **Method:** `POST`
- **Path:** `/api/v1/reports/{reportId}/exports`
- **Endpoint tag:** `api-v1-reports-reportId-exports:post:createreportexportcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-156

- **Method:** `GET`
- **Path:** `/api/v1/organization/cluster`
- **Endpoint tag:** `api-v1-organization-cluster:get:getclustercontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-157

- **Method:** `POST`
- **Path:** `/api/v1/organization/cluster`
- **Endpoint tag:** `api-v1-organization-cluster:post:createclustercontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-158

- **Method:** `PATCH`
- **Path:** `/api/v1/organization/cluster`
- **Endpoint tag:** `api-v1-organization-cluster:patch:updateclustercontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-159

- **Method:** `GET`
- **Path:** `/api/v1/organization/facilities`
- **Endpoint tag:** `api-v1-organization-facilities:get:listfacilitiescontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-160

- **Method:** `POST`
- **Path:** `/api/v1/organization/facilities`
- **Endpoint tag:** `api-v1-organization-facilities:post:createfacilitycontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-161

- **Method:** `GET`
- **Path:** `/api/v1/organization/facilities/{facilityId}`
- **Endpoint tag:** `api-v1-organization-facilities-facilityId:get:getfacilitycontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-162

- **Method:** `PATCH`
- **Path:** `/api/v1/organization/facilities/{facilityId}`
- **Endpoint tag:** `api-v1-organization-facilities-facilityId:patch:updatefacilitycontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-163

- **Method:** `GET`
- **Path:** `/api/v1/organization/units`
- **Endpoint tag:** `api-v1-organization-units:get:listorganizationunitscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-164

- **Method:** `POST`
- **Path:** `/api/v1/organization/units`
- **Endpoint tag:** `api-v1-organization-units:post:createorganizationunitcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-165

- **Method:** `POST`
- **Path:** `/api/v1/organization/units/reorder`
- **Endpoint tag:** `api-v1-organization-units-reorder:post:reorderorganizationunitscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-166

- **Method:** `GET`
- **Path:** `/api/v1/organization/units/{unitId}`
- **Endpoint tag:** `api-v1-organization-units-unitId:get:getorganizationunitcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-167

- **Method:** `PATCH`
- **Path:** `/api/v1/organization/units/{unitId}`
- **Endpoint tag:** `api-v1-organization-units-unitId:patch:updateorganizationunitcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-168

- **Method:** `GET`
- **Path:** `/api/v1/organization/job-titles`
- **Endpoint tag:** `api-v1-organization-job-titles:get:listjobtitlescontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-169

- **Method:** `POST`
- **Path:** `/api/v1/organization/job-titles`
- **Endpoint tag:** `api-v1-organization-job-titles:post:createjobtitlecontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-170

- **Method:** `GET`
- **Path:** `/api/v1/organization/positions`
- **Endpoint tag:** `api-v1-organization-positions:get:listpositionscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-171

- **Method:** `POST`
- **Path:** `/api/v1/organization/positions`
- **Endpoint tag:** `api-v1-organization-positions:post:createpositioncontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-172

- **Method:** `GET`
- **Path:** `/api/v1/organization/positions/{positionId}`
- **Endpoint tag:** `api-v1-organization-positions-positionId:get:getpositioncontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-173

- **Method:** `PATCH`
- **Path:** `/api/v1/organization/positions/{positionId}`
- **Endpoint tag:** `api-v1-organization-positions-positionId:patch:updatepositioncontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-174

- **Method:** `GET`
- **Path:** `/api/v1/organization/people`
- **Endpoint tag:** `api-v1-organization-people:get:listpeoplecontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-175

- **Method:** `POST`
- **Path:** `/api/v1/organization/people`
- **Endpoint tag:** `api-v1-organization-people:post:createpersoncontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-176

- **Method:** `GET`
- **Path:** `/api/v1/organization/people/{personId}/reference`
- **Endpoint tag:** `api-v1-organization-people-personId-reference:get:getpersonreferencecontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-177

- **Method:** `GET`
- **Path:** `/api/v1/organization/people/{personId}`
- **Endpoint tag:** `api-v1-organization-people-personId:get:getpersoncontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-178

- **Method:** `PATCH`
- **Path:** `/api/v1/organization/people/{personId}`
- **Endpoint tag:** `api-v1-organization-people-personId:patch:updatepersoncontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-179

- **Method:** `GET`
- **Path:** `/api/v1/organization/assignments`
- **Endpoint tag:** `api-v1-organization-assignments:get:listassignmentscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-180

- **Method:** `POST`
- **Path:** `/api/v1/organization/assignments`
- **Endpoint tag:** `api-v1-organization-assignments:post:createassignmentcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-181

- **Method:** `POST`
- **Path:** `/api/v1/organization/assignments/{assignmentId}/end`
- **Endpoint tag:** `api-v1-organization-assignments-assignmentId-end:post:endassignmentcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-182

- **Method:** `GET`
- **Path:** `/api/v1/organization/supervisory-relationships`
- **Endpoint tag:** `api-v1-organization-supervisory-relationships:get:supervisoryrelationshipcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-183

- **Method:** `POST`
- **Path:** `/api/v1/organization/supervisory-relationships`
- **Endpoint tag:** `api-v1-organization-supervisory-relationships:post:supervisoryrelationshipcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-184

- **Method:** `POST`
- **Path:** `/api/v1/organization/import-jobs`
- **Endpoint tag:** `api-v1-organization-import-jobs:post:submitimportjobcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-185

- **Method:** `GET`
- **Path:** `/api/v1/organization/import-jobs/{jobId}`
- **Endpoint tag:** `api-v1-organization-import-jobs-jobId:get:getimportjobcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-186

- **Method:** `GET`
- **Path:** `/api/v1/organization/import-jobs/{jobId}/rows`
- **Endpoint tag:** `api-v1-organization-import-jobs-jobId-rows:get:listimportjobrowscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-187

- **Method:** `POST`
- **Path:** `/api/v1/organization/import-jobs/{jobId}/{jobAction}`
- **Endpoint tag:** `api-v1-organization-import-jobs-jobId-jobAction:post:transitionimportjobcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-188

- **Method:** `GET`
- **Path:** `/api/v1/identity/accounts`
- **Endpoint tag:** `api-v1-identity-accounts:get:listuseraccountscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-189

- **Method:** `POST`
- **Path:** `/api/v1/identity/accounts`
- **Endpoint tag:** `api-v1-identity-accounts:post:createuseraccountcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-190

- **Method:** `GET`
- **Path:** `/api/v1/identity/accounts/{accountId}`
- **Endpoint tag:** `api-v1-identity-accounts-accountId:get:getuseraccountcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-191

- **Method:** `POST`
- **Path:** `/api/v1/identity/accounts/{accountId}/{accountAction}`
- **Endpoint tag:** `api-v1-identity-accounts-accountId-accountAction:post:transitionuseraccountcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-194

- **Method:** `GET`
- **Path:** `/api/v1/work-records`
- **Endpoint tag:** `api-v1-work-records:get:listauthorizedworkrecordscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-195

- **Method:** `GET`
- **Path:** `/api/v1/work-records/{recordId}`
- **Endpoint tag:** `api-v1-work-records-recordId:get:getauthorizedworkrecordcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-196

- **Method:** `GET`
- **Path:** `/api/v1/authorization/access-decisions/{decisionId}/explanation`
- **Endpoint tag:** `api-v1-authorization-access-decisions-decisionId-explanation:get:explainaccessdecisioncontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-197

- **Method:** `GET`
- **Path:** `/api/v1/authorization/bootstrap`
- **Endpoint tag:** `api-v1-authorization-bootstrap:get:getauthorizationbootstrapcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-198

- **Method:** `GET`
- **Path:** `/api/v1/authorization/{adminResource}`
- **Endpoint tag:** `api-v1-authorization-adminResource:get:authorizationadmincontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-199

- **Method:** `GET`
- **Path:** `/api/v1/authorization/{adminResource}/{resourceId}`
- **Endpoint tag:** `api-v1-authorization-adminResource-resourceId:get:authorizationadmincontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-206

- **Method:** `POST`
- **Path:** `/api/v1/work-records`
- **Endpoint tag:** `api-v1-work-records:post:submitworkrecordcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-210

- **Method:** `POST`
- **Path:** `/api/v1/work-records/{recordId}/{recordAction}`
- **Endpoint tag:** `api-v1-work-records-recordId-recordAction-transition:post:workrecordlifecyclecontroller::transition`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf → project_work_record_read_models`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-213

- **Method:** `POST`
- **Path:** `/api/v1/work-records/{recordId}/documents`
- **Endpoint tag:** `api-v1-work-records-recordId-documents:post:linkdocumentcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-214

- **Method:** `POST`
- **Path:** `/api/v1/authorization/access-decisions`
- **Endpoint tag:** `api-v1-authorization-access-decisions:post:decideaccesscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-215

- **Method:** `POST`
- **Path:** `/api/v1/authorization/bootstrap/complete`
- **Endpoint tag:** `api-v1-authorization-bootstrap-complete:post:completeauthorizationbootstrapcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-216

- **Method:** `POST`
- **Path:** `/api/v1/authorization/{adminResource}`
- **Endpoint tag:** `api-v1-authorization-adminResource:post:authorizationadmincontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-217

- **Method:** `PATCH`
- **Path:** `/api/v1/authorization/{adminResource}/{resourceId}`
- **Endpoint tag:** `api-v1-authorization-adminResource-resourceId:patch:authorizationadmincontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-218

- **Method:** `POST`
- **Path:** `/api/v1/authorization/{adminResource}/{resourceId}/{authorizationAction}`
- **Endpoint tag:** `api-v1-authorization-adminResource-resourceId-authorizationAction:post:authorizationadmincontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-221

- **Method:** `GET`
- **Path:** `/api/v1/work-definitions`
- **Endpoint tag:** `api-v1-work-definitions-index:get:workdefinitioncontroller::index`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-222

- **Method:** `GET`
- **Path:** `/api/v1/work-definitions/{definitionId}`
- **Endpoint tag:** `api-v1-work-definitions-definitionId-show:get:workdefinitioncontroller::show`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-223

- **Method:** `GET`
- **Path:** `/api/v1/work-definitions/{definitionId}/versions`
- **Endpoint tag:** `api-v1-work-definitions-definitionId-versions-versions:get:workdefinitioncontroller::versions`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-224

- **Method:** `GET`
- **Path:** `/api/v1/work-definition-versions/{versionId}`
- **Endpoint tag:** `api-v1-work-definition-versions-versionId-showversionroute:get:workdefinitioncontroller::showversionroute`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-225

- **Method:** `GET`
- **Path:** `/api/v1/workflow/definitions`
- **Endpoint tag:** `api-v1-workflow-definitions-definitions:get:workflowcontroller::definitions`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-226

- **Method:** `GET`
- **Path:** `/api/v1/workflow/definitions/{definitionId}/versions`
- **Endpoint tag:** `api-v1-workflow-definitions-definitionId-versions-versions:get:workflowcontroller::versions`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-227

- **Method:** `GET`
- **Path:** `/api/v1/workflow/instances`
- **Endpoint tag:** `api-v1-workflow-instances-instances:get:workflowcontroller::instances`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-228

- **Method:** `GET`
- **Path:** `/api/v1/workflow/instances/{instanceId}`
- **Endpoint tag:** `api-v1-workflow-instances-instanceId-showinstance:get:workflowcontroller::showinstance`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-229

- **Method:** `GET`
- **Path:** `/api/v1/tasks`
- **Endpoint tag:** `api-v1-tasks-index:get:taskcontroller::index`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-230

- **Method:** `GET`
- **Path:** `/api/v1/tasks/{taskId}/comments`
- **Endpoint tag:** `api-v1-tasks-taskId-comments-listcomments:get:taskengagementcontroller::listcomments`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-231

- **Method:** `GET`
- **Path:** `/api/v1/tasks/{taskId}`
- **Endpoint tag:** `api-v1-tasks-taskId-show:get:taskcontroller::show`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-232

- **Method:** `PATCH`
- **Path:** `/api/v1/tasks/{taskId}`
- **Endpoint tag:** `api-v1-tasks-taskId-update:patch:taskcontroller::update`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-233

- **Method:** `GET`
- **Path:** `/api/v1/documents`
- **Endpoint tag:** `api-v1-documents:get:listdocumentscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-234

- **Method:** `GET`
- **Path:** `/api/v1/documents/{documentId}`
- **Endpoint tag:** `api-v1-documents-documentId:get:getdocumentcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-235

- **Method:** `GET`
- **Path:** `/api/v1/documents/{documentId}/versions`
- **Endpoint tag:** `api-v1-documents-documentId-versions:get:listdocumentversionscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-236

- **Method:** `GET`
- **Path:** `/api/v1/documents/{documentId}/links`
- **Endpoint tag:** `api-v1-documents-documentId-links:get:listdocumentlinkscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-237

- **Method:** `GET`
- **Path:** `/api/v1/reports`
- **Endpoint tag:** `api-v1-reports:get:listreportscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-238

- **Method:** `GET`
- **Path:** `/api/v1/dashboards`
- **Endpoint tag:** `api-v1-dashboards:get:listdashboardscontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-245

- **Method:** `POST`
- **Path:** `/api/v1/work-definitions`
- **Endpoint tag:** `api-v1-work-definitions-store:post:workdefinitioncontroller::store`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-246

- **Method:** `POST`
- **Path:** `/api/v1/work-definitions/{definitionId}/versions`
- **Endpoint tag:** `api-v1-work-definitions-definitionId-versions-versions:post:workdefinitioncontroller::versions`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-247

- **Method:** `POST`
- **Path:** `/api/v1/work-definition-versions/{versionId}/{versionAction}`
- **Endpoint tag:** `api-v1-work-definition-versions-versionId-versionAction-transition:post:workdefinitioncontroller::transition`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-248

- **Method:** `POST`
- **Path:** `/api/v1/workflow/definitions`
- **Endpoint tag:** `api-v1-workflow-definitions-definitions:post:workflowcontroller::definitions`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-249

- **Method:** `POST`
- **Path:** `/api/v1/workflow/definitions/{definitionId}/versions`
- **Endpoint tag:** `api-v1-workflow-definitions-definitionId-versions-versions:post:workflowcontroller::versions`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-250

- **Method:** `POST`
- **Path:** `/api/v1/workflow/versions/{versionId}/{workflowLifecycleAction}`
- **Endpoint tag:** `api-v1-workflow-versions-versionId-workflowLifecycleAction-publish:post:workflowcontroller::publish`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-251

- **Method:** `POST`
- **Path:** `/api/v1/workflow/instances`
- **Endpoint tag:** `api-v1-workflow-instances-instances:post:workflowcontroller::instances`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-252

- **Method:** `POST`
- **Path:** `/api/v1/tasks`
- **Endpoint tag:** `api-v1-tasks-store:post:taskcontroller::store`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-253

- **Method:** `POST`
- **Path:** `/api/v1/tasks/from-step/{stepId}`
- **Endpoint tag:** `api-v1-tasks-from-step-stepId-fromstep:post:taskcontroller::fromstep`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-254

- **Method:** `POST`
- **Path:** `/api/v1/tasks/{taskId}/participants`
- **Endpoint tag:** `api-v1-tasks-taskId-participants-addparticipant:post:taskengagementcontroller::addparticipant`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-255

- **Method:** `POST`
- **Path:** `/api/v1/tasks/{taskId}/comments`
- **Endpoint tag:** `api-v1-tasks-taskId-comments-addcomment:post:taskengagementcontroller::addcomment`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-256

- **Method:** `POST`
- **Path:** `/api/v1/tasks/{taskId}/{workflowTaskAction}`
- **Endpoint tag:** `api-v1-tasks-taskId-workflowTaskAction-transition:post:taskcontroller::transition`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-257

- **Method:** `POST`
- **Path:** `/api/v1/workflow/steps/{stepId}/decisions`
- **Endpoint tag:** `api-v1-workflow-steps-stepId-decisions-decidestep:post:workflowcontroller::decidestep`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-258

- **Method:** `POST`
- **Path:** `/api/v1/workflow/steps/{stepId}/{stepAction}`
- **Endpoint tag:** `api-v1-workflow-steps-stepId-stepAction-actonstep:post:workflowcontroller::actonstep`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-259

- **Method:** `POST`
- **Path:** `/api/v1/workflow/instances/{instanceId}/cancel`
- **Endpoint tag:** `api-v1-workflow-instances-instanceId-cancel-cancelinstance:post:workflowcontroller::cancelinstance`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-260

- **Method:** `POST`
- **Path:** `/api/v1/documents`
- **Endpoint tag:** `api-v1-documents:post:createdocumentcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-261

- **Method:** `PATCH`
- **Path:** `/api/v1/documents/{documentId}`
- **Endpoint tag:** `api-v1-documents-documentId:patch:updatedocumentcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-262

- **Method:** `POST`
- **Path:** `/api/v1/documents/{documentId}/versions`
- **Endpoint tag:** `api-v1-documents-documentId-versions:post:adddocumentversioncontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-263

- **Method:** `POST`
- **Path:** `/api/v1/documents/{documentId}/links`
- **Endpoint tag:** `api-v1-documents-documentId-links:post:documentlinkcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-264

- **Method:** `POST`
- **Path:** `/api/v1/documents/{documentId}/{documentAction}`
- **Endpoint tag:** `api-v1-documents-documentId-documentAction:post:transitiondocumentcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`

### row-265

- **Method:** `POST`
- **Path:** `/api/v1/documents/{documentId}/{documentGrantType}-grant`
- **Endpoint tag:** `api-v1-documents-documentId-documentGrantType-grant:post:createdocumentgrantcontroller`
- **Middleware:** `identity_session → require_identity_session_principal → identity_csrf`
- **Session:** `yes`
- **Principal:** `yes`
- **CSRF:** `yes`
- **Throttle:** `none`
