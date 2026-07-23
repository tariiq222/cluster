---
doc_id: API-INV-001
title: Backend Endpoint Inventory
type: engineering
status: accepted
version: 1.0.0
date: 2026-07-23
owner: مكتب هندسة البرمجيات
reviewers:
  - مكتب هندسة المنصة
  - مسؤول أمن المعلومات
classification: internal
review_cycle: مع كل تغيير routes
sources:
  - docs/contracts/api/openapi.yaml
  - docs/api/rbac-matrix.md
references:
  - docs/contracts/api/openapi.yaml
  - docs/api/rbac-matrix.md
---
# Backend Endpoint Inventory

> Generated from `apps/api/routes/web.php`; do not edit endpoint cards by hand.

## Overview

This inventory documents 119 live `Route::` declarations plus the bootstrap health route.
Laravel routes are the runtime source of truth. The canonical contract is `docs/contracts/api/openapi.yaml`.
Arabic summaries remain as inline placeholders for the dedicated translation slice.

## Module Sections

**Authorization**

### `GET /api/v1/authorization/access-decisions/{decisionId}/explanation`

- **Summary (EN / AR):** Retrieve authorization/access decisions/{decisionId}/explanation. `{{AR:get_api_v1_authorization_access_decisions_decisionId_explanation}}`
- **Operation key:** `get_api_v1_authorization_access_decisions_decisionId_explanation`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-access-decisions-decisionId-explanation:get:explainaccessdecisioncontroller`](rbac-matrix.md#row-196); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_authorization_access_decisions_decisionId_explanation_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_authorization_access_decisions_decisionId_explanation_response` (schema placeholder).
- **Status codes:** `400, 401, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Authorization\ExplainAccessDecisionController`.
- **Controller source:** `apps/api/app/Http/Controllers/Authorization/ExplainAccessDecisionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/access-decisions/{decisionId}/explanation.get`.
- **Route source:** `apps/api/routes/web.php:196`.

### `GET /api/v1/authorization/bootstrap`

- **Summary (EN / AR):** Retrieve authorization/bootstrap. `{{AR:get_api_v1_authorization_bootstrap}}`
- **Operation key:** `get_api_v1_authorization_bootstrap`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-bootstrap:get:getauthorizationbootstrapcontroller`](rbac-matrix.md#row-197); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_authorization_bootstrap_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_authorization_bootstrap_response` (schema placeholder).
- **Status codes:** `400, 401`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Authorization\GetAuthorizationBootstrapController`.
- **Controller source:** `apps/api/app/Http/Controllers/Authorization/GetAuthorizationBootstrapController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/bootstrap.get`.
- **Route source:** `apps/api/routes/web.php:197`.

### `GET /api/v1/authorization/{adminResource}`

- **Summary (EN / AR):** Retrieve authorization/{adminResource}. `{{AR:get_api_v1_authorization_adminResource}}`
- **Operation key:** `get_api_v1_authorization_adminResource`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-adminResource:get:authorizationadmincontroller`](rbac-matrix.md#row-198); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_authorization_adminResource_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_authorization_adminResource_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Authorization\AuthorizationAdminController`.
- **Controller source:** `apps/api/app/Http/Controllers/Authorization/AuthorizationAdminController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/{adminResource}.get`.
- **Route source:** `apps/api/routes/web.php:198`.

### `GET /api/v1/authorization/{adminResource}/{resourceId}`

- **Summary (EN / AR):** Retrieve authorization/{adminResource}/{resourceId}. `{{AR:get_api_v1_authorization_adminResource_resourceId}}`
- **Operation key:** `get_api_v1_authorization_adminResource_resourceId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-adminResource-resourceId:get:authorizationadmincontroller`](rbac-matrix.md#row-199); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_authorization_adminResource_resourceId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_authorization_adminResource_resourceId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Authorization\AuthorizationAdminController`.
- **Controller source:** `apps/api/app/Http/Controllers/Authorization/AuthorizationAdminController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/{adminResource}/{resourceId}.get`.
- **Route source:** `apps/api/routes/web.php:199`.

### `POST /api/v1/authorization/access-decisions`

- **Summary (EN / AR):** Create or execute authorization/access decisions. `{{AR:post_api_v1_authorization_access_decisions}}`
- **Operation key:** `post_api_v1_authorization_access_decisions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-access-decisions:post:decideaccesscontroller`](rbac-matrix.md#row-214); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_authorization_access_decisions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_authorization_access_decisions_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Authorization\DecideAccessController`.
- **Controller source:** `apps/api/app/Http/Controllers/Authorization/DecideAccessController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/access-decisions.post`.
- **Route source:** `apps/api/routes/web.php:214`.

### `POST /api/v1/authorization/bootstrap/complete`

- **Summary (EN / AR):** Create or execute authorization/bootstrap/complete. `{{AR:post_api_v1_authorization_bootstrap_complete}}`
- **Operation key:** `post_api_v1_authorization_bootstrap_complete`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-bootstrap-complete:post:completeauthorizationbootstrapcontroller`](rbac-matrix.md#row-215); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_authorization_bootstrap_complete_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_authorization_bootstrap_complete_response` (schema placeholder).
- **Status codes:** `200, 400, 401, 403, 409, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Authorization\CompleteAuthorizationBootstrapController`.
- **Controller source:** `apps/api/app/Http/Controllers/Authorization/CompleteAuthorizationBootstrapController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/bootstrap/complete.post`.
- **Route source:** `apps/api/routes/web.php:215`.

### `POST /api/v1/authorization/{adminResource}`

- **Summary (EN / AR):** Create or execute authorization/{adminResource}. `{{AR:post_api_v1_authorization_adminResource}}`
- **Operation key:** `post_api_v1_authorization_adminResource`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-adminResource:post:authorizationadmincontroller`](rbac-matrix.md#row-216); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_authorization_adminResource_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_authorization_adminResource_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Authorization\AuthorizationAdminController`.
- **Controller source:** `apps/api/app/Http/Controllers/Authorization/AuthorizationAdminController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/{adminResource}.post`.
- **Route source:** `apps/api/routes/web.php:216`.

### `PATCH /api/v1/authorization/{adminResource}/{resourceId}`

- **Summary (EN / AR):** Update authorization/{adminResource}/{resourceId}. `{{AR:patch_api_v1_authorization_adminResource_resourceId}}`
- **Operation key:** `patch_api_v1_authorization_adminResource_resourceId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-adminResource-resourceId:patch:authorizationadmincontroller`](rbac-matrix.md#row-217); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_authorization_adminResource_resourceId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_authorization_adminResource_resourceId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Authorization\AuthorizationAdminController`.
- **Controller source:** `apps/api/app/Http/Controllers/Authorization/AuthorizationAdminController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/{adminResource}/{resourceId}.patch`.
- **Route source:** `apps/api/routes/web.php:217`.

### `POST /api/v1/authorization/{adminResource}/{resourceId}/{authorizationAction}`

- **Summary (EN / AR):** Create or execute authorization/{adminResource}/{resourceId}/{authorizationAction}. `{{AR:post_api_v1_authorization_adminResource_resourceId_authorizationAction}}`
- **Operation key:** `post_api_v1_authorization_adminResource_resourceId_authorizationAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-adminResource-resourceId-authorizationAction:post:authorizationadmincontroller`](rbac-matrix.md#row-218); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_authorization_adminResource_resourceId_authorizationAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_authorization_adminResource_resourceId_authorizationAction_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Authorization\AuthorizationAdminController`.
- **Controller source:** `apps/api/app/Http/Controllers/Authorization/AuthorizationAdminController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/{adminResource}/{resourceId}/{authorizationAction}.post`.
- **Route source:** `apps/api/routes/web.php:218`.

**Documents**

### `GET /api/v1/documents/uploads/{uploadId}`

- **Summary (EN / AR):** Retrieve documents/uploads/{uploadId}. `{{AR:get_api_v1_documents_uploads_uploadId}}`
- **Operation key:** `get_api_v1_documents_uploads_uploadId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-uploads-uploadId:get:getdocumentuploadstatuscontroller`](rbac-matrix.md#row-121); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_documents_uploads_uploadId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_documents_uploads_uploadId_response` (schema placeholder).
- **Status codes:** `200, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Documents\GetDocumentUploadStatusController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/GetDocumentUploadStatusController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/uploads/{uploadId}.get`.
- **Route source:** `apps/api/routes/web.php:121`.

### `GET /api/v1/documents/{documentId}/download`

- **Summary (EN / AR):** Retrieve documents/{documentId}/download. `{{AR:get_api_v1_documents_documentId_download}}`
- **Operation key:** `get_api_v1_documents_documentId_download`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-download:get:downloaddocumentcontroller`](rbac-matrix.md#row-122); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_download_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_download_response` (schema placeholder).
- **Status codes:** `400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Documents\DownloadDocumentController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/DownloadDocumentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/download.get`.
- **Route source:** `apps/api/routes/web.php:122`.

### `POST /api/v1/documents/uploads`

- **Summary (EN / AR):** Create or execute documents/uploads. `{{AR:post_api_v1_documents_uploads}}`
- **Operation key:** `post_api_v1_documents_uploads`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-uploads:post:initiatedocumentuploadcontroller`](rbac-matrix.md#row-131); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_uploads_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_uploads_response` (schema placeholder).
- **Status codes:** `201, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Documents\InitiateDocumentUploadController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/InitiateDocumentUploadController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/uploads.post`.
- **Route source:** `apps/api/routes/web.php:131`.

### `POST /api/v1/documents/uploads/{uploadId}/complete`

- **Summary (EN / AR):** Create or execute documents/uploads/{uploadId}/complete. `{{AR:post_api_v1_documents_uploads_uploadId_complete}}`
- **Operation key:** `post_api_v1_documents_uploads_uploadId_complete`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-uploads-uploadId-complete:post:completedocumentuploadcontroller`](rbac-matrix.md#row-132); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_uploads_uploadId_complete_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_uploads_uploadId_complete_response` (schema placeholder).
- **Status codes:** `202, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Documents\CompleteDocumentUploadController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/CompleteDocumentUploadController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/uploads/{uploadId}/complete.post`.
- **Route source:** `apps/api/routes/web.php:132`.

### `GET /api/v1/documents`

- **Summary (EN / AR):** Retrieve documents. `{{AR:get_api_v1_documents}}`
- **Operation key:** `get_api_v1_documents`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents:get:listdocumentscontroller`](rbac-matrix.md#row-235); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_documents_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_documents_response` (schema placeholder).
- **Status codes:** `400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Documents\ListDocumentsController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/ListDocumentsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents.get`.
- **Route source:** `apps/api/routes/web.php:235`.

### `GET /api/v1/documents/{documentId}`

- **Summary (EN / AR):** Retrieve documents/{documentId}. `{{AR:get_api_v1_documents_documentId}}`
- **Operation key:** `get_api_v1_documents_documentId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId:get:getdocumentcontroller`](rbac-matrix.md#row-236); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_response` (schema placeholder).
- **Status codes:** `400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Documents\GetDocumentController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/GetDocumentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}.get`.
- **Route source:** `apps/api/routes/web.php:236`.

### `GET /api/v1/documents/{documentId}/versions`

- **Summary (EN / AR):** Retrieve documents/{documentId}/versions. `{{AR:get_api_v1_documents_documentId_versions}}`
- **Operation key:** `get_api_v1_documents_documentId_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-versions:get:listdocumentversionscontroller`](rbac-matrix.md#row-237); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_versions_response` (schema placeholder).
- **Status codes:** `400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Documents\ListDocumentVersionsController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/ListDocumentVersionsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/versions.get`.
- **Route source:** `apps/api/routes/web.php:237`.

### `GET /api/v1/documents/{documentId}/links`

- **Summary (EN / AR):** Retrieve documents/{documentId}/links. `{{AR:get_api_v1_documents_documentId_links}}`
- **Operation key:** `get_api_v1_documents_documentId_links`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-links:get:listdocumentlinkscontroller`](rbac-matrix.md#row-238); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_links_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_links_response` (schema placeholder).
- **Status codes:** `400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Documents\ListDocumentLinksController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/ListDocumentLinksController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/links.get`.
- **Route source:** `apps/api/routes/web.php:238`.

### `POST /api/v1/documents`

- **Summary (EN / AR):** Create or execute documents. `{{AR:post_api_v1_documents}}`
- **Operation key:** `post_api_v1_documents`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents:post:createdocumentcontroller`](rbac-matrix.md#row-262); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_response` (schema placeholder).
- **Status codes:** `201, 400, 403, 409`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Documents\CreateDocumentController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/CreateDocumentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents.post`.
- **Route source:** `apps/api/routes/web.php:262`.

### `PATCH /api/v1/documents/{documentId}`

- **Summary (EN / AR):** Update documents/{documentId}. `{{AR:patch_api_v1_documents_documentId}}`
- **Operation key:** `patch_api_v1_documents_documentId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId:patch:updatedocumentcontroller`](rbac-matrix.md#row-263); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_documents_documentId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_documents_documentId_response` (schema placeholder).
- **Status codes:** `400, 404, 409, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Documents\UpdateDocumentController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/UpdateDocumentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}.patch`.
- **Route source:** `apps/api/routes/web.php:263`.

### `POST /api/v1/documents/{documentId}/versions`

- **Summary (EN / AR):** Create or execute documents/{documentId}/versions. `{{AR:post_api_v1_documents_documentId_versions}}`
- **Operation key:** `post_api_v1_documents_documentId_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-versions:post:adddocumentversioncontroller`](rbac-matrix.md#row-264); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_versions_response` (schema placeholder).
- **Status codes:** `201, 400, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Documents\AddDocumentVersionController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/AddDocumentVersionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/versions.post`.
- **Route source:** `apps/api/routes/web.php:264`.

### `POST /api/v1/documents/{documentId}/links`

- **Summary (EN / AR):** Create or execute documents/{documentId}/links. `{{AR:post_api_v1_documents_documentId_links}}`
- **Operation key:** `post_api_v1_documents_documentId_links`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-links:post:documentlinkcontroller`](rbac-matrix.md#row-265); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_links_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_links_response` (schema placeholder).
- **Status codes:** `201`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `DocumentLinkController`.
- **Controller source:** `controller source unresolved`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/links.post`.
- **Route source:** `apps/api/routes/web.php:265`.

### `POST /api/v1/documents/{documentId}/{documentAction}`

- **Summary (EN / AR):** Create or execute documents/{documentId}/{documentAction}. `{{AR:post_api_v1_documents_documentId_documentAction}}`
- **Operation key:** `post_api_v1_documents_documentId_documentAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-documentAction:post:transitiondocumentcontroller`](rbac-matrix.md#row-266); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_documentAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_documentAction_response` (schema placeholder).
- **Status codes:** `400, 404, 409, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Documents\TransitionDocumentController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/TransitionDocumentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/{documentAction}.post`.
- **Route source:** `apps/api/routes/web.php:266`.

### `POST /api/v1/documents/{documentId}/{documentGrantType}-grant`

- **Summary (EN / AR):** Create or execute documents/{documentId}/{documentGrantType} grant. `{{AR:post_api_v1_documents_documentId_documentGrantType_grant}}`
- **Operation key:** `post_api_v1_documents_documentId_documentGrantType_grant`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-documentGrantType-grant:post:createdocumentgrantcontroller`](rbac-matrix.md#row-267); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_documentGrantType_grant_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_documentGrantType_grant_response` (schema placeholder).
- **Status codes:** `201, 400, 404, 409, 503, 512`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Documents\CreateDocumentGrantController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/CreateDocumentGrantController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/{documentGrantType}-grant.post`.
- **Route source:** `apps/api/routes/web.php:267`.

**Identity**

### `POST /api/v1/auth/login`

- **Summary (EN / AR):** Create or execute auth/login. `{{AR:post_api_v1_auth_login}}`
- **Operation key:** `post_api_v1_auth_login`
- **Middleware chain:** `web`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-auth-login:post:developmentfixturelogincontroller`](rbac-matrix.md#row-101); principal required: `no`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_auth_login_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_auth_login_response` (schema placeholder).
- **Status codes:** `400, 401`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\DevelopmentFixtureLogin\Http\DevelopmentFixtureLoginController`.
- **Controller source:** `apps/api/Modules/Identity/Features/DevelopmentFixtureLogin/Http/DevelopmentFixtureLoginController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/auth/login.post`.
- **Route source:** `apps/api/routes/web.php:101`.

### `POST /api/v1/identity/login`

- **Summary (EN / AR):** Create or execute identity/login. `{{AR:post_api_v1_identity_login}}`
- **Operation key:** `post_api_v1_identity_login`
- **Middleware chain:** `none`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-identity-login:post:identitylogincontroller`](rbac-matrix.md#row-104); principal required: `no`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_login_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_login_response` (schema placeholder).
- **Status codes:** `400, 401, 429, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Identity\IdentityLoginController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/IdentityLoginController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/login.post`.
- **Route source:** `apps/api/routes/web.php:104`.

### `POST /api/v1/identity/activation`

- **Summary (EN / AR):** Create or execute identity/activation. `{{AR:post_api_v1_identity_activation}}`
- **Operation key:** `post_api_v1_identity_activation`
- **Middleware chain:** `throttle:6,1`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-identity-activation:post:consumeactivationcontroller`](rbac-matrix.md#row-105); principal required: `no`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_activation_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_activation_response` (schema placeholder).
- **Status codes:** `204, 400, 401, 422, 500`.
- **Throttle:** `6,1`.
- **Controller FQCN:** `App\Http\Controllers\Identity\ConsumeActivationController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/ConsumeActivationController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/activation.post`.
- **Route source:** `apps/api/routes/web.php:105`.

### `GET /api/v1/identity/me`

- **Summary (EN / AR):** Retrieve identity/me. `{{AR:get_api_v1_identity_me}}`
- **Operation key:** `get_api_v1_identity_me`
- **Middleware chain:** `identity_session → require_identity_session_principal`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-identity-me:get:getcurrentidentitycontroller`](rbac-matrix.md#row-106); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_identity_me_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_identity_me_response` (schema placeholder).
- **Status codes:** `400, 401`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Identity\GetCurrentIdentityController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/GetCurrentIdentityController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/me.get`.
- **Route source:** `apps/api/routes/web.php:106`.

### `POST /api/v1/identity/csrf`

- **Summary (EN / AR):** Create or execute identity/csrf. `{{AR:post_api_v1_identity_csrf}}`
- **Operation key:** `post_api_v1_identity_csrf`
- **Middleware chain:** `identity_session → require_identity_session_principal`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-identity-csrf:post:refreshidentitycsrfcontroller`](rbac-matrix.md#row-107); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_csrf_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_csrf_response` (schema placeholder).
- **Status codes:** `400, 401`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Identity\RefreshIdentityCsrfController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/RefreshIdentityCsrfController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/csrf.post`.
- **Route source:** `apps/api/routes/web.php:107`.

### `GET /api/v1/me`

- **Summary (EN / AR):** Retrieve me. `{{AR:get_api_v1_me}}`
- **Operation key:** `get_api_v1_me`
- **Middleware chain:** `identity_session → require_identity_session_principal`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-me:get:getcurrentprincipalcontroller`](rbac-matrix.md#row-108); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_me_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_me_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Identity\GetCurrentPrincipalController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/GetCurrentPrincipalController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/me.get`.
- **Route source:** `apps/api/routes/web.php:108`.

### `GET /api/v1/me/scopes`

- **Summary (EN / AR):** Retrieve me/scopes. `{{AR:get_api_v1_me_scopes}}`
- **Operation key:** `get_api_v1_me_scopes`
- **Middleware chain:** `identity_session → require_identity_session_principal`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-me-scopes:get:listmyscopescontroller`](rbac-matrix.md#row-109); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_me_scopes_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_me_scopes_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Identity\ListMyScopesController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/ListMyScopesController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/me/scopes.get`.
- **Route source:** `apps/api/routes/web.php:109`.

### `PUT /api/v1/me/scope`

- **Summary (EN / AR):** Replace me/scope. `{{AR:put_api_v1_me_scope}}`
- **Operation key:** `put_api_v1_me_scope`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-me-scope:put:selectmyscopecontroller`](rbac-matrix.md#row-110); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/put_api_v1_me_scope_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/put_api_v1_me_scope_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Identity\SelectMyScopeController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/SelectMyScopeController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/me/scope.put`.
- **Route source:** `apps/api/routes/web.php:110`.

### `POST /api/v1/identity/logout`

- **Summary (EN / AR):** Create or execute identity/logout. `{{AR:post_api_v1_identity_logout}}`
- **Operation key:** `post_api_v1_identity_logout`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-logout:post:identitylogoutcontroller`](rbac-matrix.md#row-116); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_logout_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_logout_response` (schema placeholder).
- **Status codes:** `400, 401, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Identity\IdentityLogoutController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/IdentityLogoutController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/logout.post`.
- **Route source:** `apps/api/routes/web.php:116`.

### `POST /api/v1/identity/password`

- **Summary (EN / AR):** Create or execute identity/password. `{{AR:post_api_v1_identity_password}}`
- **Operation key:** `post_api_v1_identity_password`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-password:post:changepasswordcontroller`](rbac-matrix.md#row-117); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_password_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_password_response` (schema placeholder).
- **Status codes:** `204, 400, 401, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Identity\ChangePasswordController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/ChangePasswordController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/password.post`.
- **Route source:** `apps/api/routes/web.php:117`.

### `POST /api/v1/identity/accounts/{accountId}/activation`

- **Summary (EN / AR):** Create or execute identity/accounts/{accountId}/activation. `{{AR:post_api_v1_identity_accounts_accountId_activation}}`
- **Operation key:** `post_api_v1_identity_accounts_accountId_activation`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-accounts-accountId-activation:post:issueactivationcontroller`](rbac-matrix.md#row-118); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_accounts_accountId_activation_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_accounts_accountId_activation_response` (schema placeholder).
- **Status codes:** `202, 400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Identity\IssueActivationController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/IssueActivationController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/accounts/{accountId}/activation.post`.
- **Route source:** `apps/api/routes/web.php:118`.

### `GET /api/v1/identity/accounts`

- **Summary (EN / AR):** Retrieve identity/accounts. `{{AR:get_api_v1_identity_accounts}}`
- **Operation key:** `get_api_v1_identity_accounts`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-accounts:get:listuseraccountscontroller`](rbac-matrix.md#row-188); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_identity_accounts_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_identity_accounts_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Identity\ListUserAccountsController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/ListUserAccountsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/accounts.get`.
- **Route source:** `apps/api/routes/web.php:188`.

### `POST /api/v1/identity/accounts`

- **Summary (EN / AR):** Create or execute identity/accounts. `{{AR:post_api_v1_identity_accounts}}`
- **Operation key:** `post_api_v1_identity_accounts`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-accounts:post:createuseraccountcontroller`](rbac-matrix.md#row-189); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_accounts_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_accounts_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Identity\CreateUserAccountController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/CreateUserAccountController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/accounts.post`.
- **Route source:** `apps/api/routes/web.php:189`.

### `GET /api/v1/identity/accounts/{accountId}`

- **Summary (EN / AR):** Retrieve identity/accounts/{accountId}. `{{AR:get_api_v1_identity_accounts_accountId}}`
- **Operation key:** `get_api_v1_identity_accounts_accountId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-accounts-accountId:get:getuseraccountcontroller`](rbac-matrix.md#row-190); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_identity_accounts_accountId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_identity_accounts_accountId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Identity\GetUserAccountController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/GetUserAccountController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/accounts/{accountId}.get`.
- **Route source:** `apps/api/routes/web.php:190`.

### `POST /api/v1/identity/accounts/{accountId}/{accountAction}`

- **Summary (EN / AR):** Create or execute identity/accounts/{accountId}/{accountAction}. `{{AR:post_api_v1_identity_accounts_accountId_accountAction}}`
- **Operation key:** `post_api_v1_identity_accounts_accountId_accountAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-accounts-accountId-accountAction:post:transitionuseraccountcontroller`](rbac-matrix.md#row-191); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_accounts_accountId_accountAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_accounts_accountId_accountAction_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Identity\TransitionUserAccountController`.
- **Controller source:** `apps/api/app/Http/Controllers/Identity/TransitionUserAccountController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/accounts/{accountId}/{accountAction}.post`.
- **Route source:** `apps/api/routes/web.php:191`.

**Internal**

### `POST /api/v1/internal/documents/versions/{versionId}/scan`

- **Summary (EN / AR):** Create or execute internal/documents/versions/{versionId}/scan. `{{AR:post_api_v1_internal_documents_versions_versionId_scan}}`
- **Operation key:** `post_api_v1_internal_documents_versions_versionId_scan`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf → throttle:60,1`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-internal-documents-versions-versionId-scan:post:scandocumentversioncontroller`](rbac-matrix.md#row-136); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_internal_documents_versions_versionId_scan_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_internal_documents_versions_versionId_scan_response` (schema placeholder).
- **Status codes:** `202, 400`.
- **Throttle:** `60,1`.
- **Controller FQCN:** `App\Http\Controllers\Documents\ScanDocumentVersionController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/ScanDocumentVersionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/internal/documents/versions/{versionId}/scan.post`.
- **Route source:** `apps/api/routes/web.php:136`.

### `POST /api/v1/internal/documents/versions/{versionId}/reconcile-promotion`

- **Summary (EN / AR):** Create or execute internal/documents/versions/{versionId}/reconcile promotion. `{{AR:post_api_v1_internal_documents_versions_versionId_reconcile_promotion}}`
- **Operation key:** `post_api_v1_internal_documents_versions_versionId_reconcile_promotion`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf → throttle:60,1`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-internal-documents-versions-versionId-reconcile-promotion:post:reconciledocumentpromotioncontroller`](rbac-matrix.md#row-137); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_internal_documents_versions_versionId_reconcile_promotion_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_internal_documents_versions_versionId_reconcile_promotion_response` (schema placeholder).
- **Status codes:** `200, 400`.
- **Throttle:** `60,1`.
- **Controller FQCN:** `App\Http\Controllers\Documents\ReconcileDocumentPromotionController`.
- **Controller source:** `apps/api/app/Http/Controllers/Documents/ReconcileDocumentPromotionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/internal/documents/versions/{versionId}/reconcile-promotion.post`.
- **Route source:** `apps/api/routes/web.php:137`.

**Notifications**

### `GET /api/v1/notifications`

- **Summary (EN / AR):** Retrieve notifications. `{{AR:get_api_v1_notifications}}`
- **Operation key:** `get_api_v1_notifications`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-notifications:get:listmynotificationscontroller`](rbac-matrix.md#row-139); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_notifications_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_notifications_response` (schema placeholder).
- **Status codes:** `400, 401`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Notifications\Features\ListMyNotifications\Http\ListMyNotificationsController`.
- **Controller source:** `apps/api/Modules/Notifications/Features/ListMyNotifications/Http/ListMyNotificationsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/notifications.get`.
- **Route source:** `apps/api/routes/web.php:139`.

### `POST /api/v1/notifications/{notificationId}/read`

- **Summary (EN / AR):** Create or execute notifications/{notificationId}/read. `{{AR:post_api_v1_notifications_notificationId_read}}`
- **Operation key:** `post_api_v1_notifications_notificationId_read`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-notifications-notificationId-read:post:marknotificationreadcontroller`](rbac-matrix.md#row-140); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_notifications_notificationId_read_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_notifications_notificationId_read_response` (schema placeholder).
- **Status codes:** `400, 401, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Notifications\Features\ListMyNotifications\Http\MarkNotificationReadController`.
- **Controller source:** `apps/api/Modules/Notifications/Features/ListMyNotifications/Http/MarkNotificationReadController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/notifications/{notificationId}/read.post`.
- **Route source:** `apps/api/routes/web.php:140`.

**Organization**

### `GET /api/v1/organization/temporary-assignments`

- **Summary (EN / AR):** Retrieve organization/temporary assignments. `{{AR:get_api_v1_organization_temporary_assignments}}`
- **Operation key:** `get_api_v1_organization_temporary_assignments`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-temporary-assignments:get:listtemporaryassignmentscontroller`](rbac-matrix.md#row-123); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_temporary_assignments_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_temporary_assignments_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\ListTemporaryAssignmentsController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/ListTemporaryAssignmentsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/temporary-assignments.get`.
- **Route source:** `apps/api/routes/web.php:123`.

### `GET /api/v1/organization/temporary-assignments/{temporaryAssignmentId}`

- **Summary (EN / AR):** Retrieve organization/temporary assignments/{temporaryAssignmentId}. `{{AR:get_api_v1_organization_temporary_assignments_temporaryAssignmentId}}`
- **Operation key:** `get_api_v1_organization_temporary_assignments_temporaryAssignmentId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-temporary-assignments-temporaryAssignmentId:get:gettemporaryassignmentcontroller`](rbac-matrix.md#row-124); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_temporary_assignments_temporaryAssignmentId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_temporary_assignments_temporaryAssignmentId_response` (schema placeholder).
- **Status codes:** `400, 401, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\GetTemporaryAssignmentController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/GetTemporaryAssignmentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/temporary-assignments/{temporaryAssignmentId}.get`.
- **Route source:** `apps/api/routes/web.php:124`.

### `POST /api/v1/organization/temporary-assignments`

- **Summary (EN / AR):** Create or execute organization/temporary assignments. `{{AR:post_api_v1_organization_temporary_assignments}}`
- **Operation key:** `post_api_v1_organization_temporary_assignments`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-temporary-assignments:post:createtemporaryassignmentcontroller`](rbac-matrix.md#row-133); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_temporary_assignments_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_temporary_assignments_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 500, 503`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\CreateTemporaryAssignmentController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/CreateTemporaryAssignmentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/temporary-assignments.post`.
- **Route source:** `apps/api/routes/web.php:133`.

### `POST /api/v1/organization/temporary-assignments/{temporaryAssignmentId}/revoke`

- **Summary (EN / AR):** Create or execute organization/temporary assignments/{temporaryAssignmentId}/revoke. `{{AR:post_api_v1_organization_temporary_assignments_temporaryAssignmentId_revoke}}`
- **Operation key:** `post_api_v1_organization_temporary_assignments_temporaryAssignmentId_revoke`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-temporary-assignments-temporaryAssignmentId-revoke:post:revoketemporaryassignmentcontroller`](rbac-matrix.md#row-134); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_temporary_assignments_temporaryAssignmentId_revoke_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_temporary_assignments_temporaryAssignmentId_revoke_response` (schema placeholder).
- **Status codes:** `400, 401, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\RevokeTemporaryAssignmentController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/RevokeTemporaryAssignmentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/temporary-assignments/{temporaryAssignmentId}/revoke.post`.
- **Route source:** `apps/api/routes/web.php:134`.

### `GET /api/v1/organization/cluster`

- **Summary (EN / AR):** Retrieve organization/cluster. `{{AR:get_api_v1_organization_cluster}}`
- **Operation key:** `get_api_v1_organization_cluster`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-cluster:get:getclustercontroller`](rbac-matrix.md#row-156); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_cluster_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_cluster_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\GetClusterController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/GetClusterController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/cluster.get`.
- **Route source:** `apps/api/routes/web.php:156`.

### `POST /api/v1/organization/cluster`

- **Summary (EN / AR):** Create or execute organization/cluster. `{{AR:post_api_v1_organization_cluster}}`
- **Operation key:** `post_api_v1_organization_cluster`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-cluster:post:createclustercontroller`](rbac-matrix.md#row-157); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_cluster_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_cluster_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\CreateClusterController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/CreateClusterController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/cluster.post`.
- **Route source:** `apps/api/routes/web.php:157`.

### `PATCH /api/v1/organization/cluster`

- **Summary (EN / AR):** Update organization/cluster. `{{AR:patch_api_v1_organization_cluster}}`
- **Operation key:** `patch_api_v1_organization_cluster`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-cluster:patch:updateclustercontroller`](rbac-matrix.md#row-158); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_organization_cluster_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_organization_cluster_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\UpdateClusterController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/UpdateClusterController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/cluster.patch`.
- **Route source:** `apps/api/routes/web.php:158`.

### `GET /api/v1/organization/facilities`

- **Summary (EN / AR):** Retrieve organization/facilities. `{{AR:get_api_v1_organization_facilities}}`
- **Operation key:** `get_api_v1_organization_facilities`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-facilities:get:listfacilitiescontroller`](rbac-matrix.md#row-159); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_facilities_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_facilities_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\ListFacilitiesController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/ListFacilitiesController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/facilities.get`.
- **Route source:** `apps/api/routes/web.php:159`.

### `POST /api/v1/organization/facilities`

- **Summary (EN / AR):** Create or execute organization/facilities. `{{AR:post_api_v1_organization_facilities}}`
- **Operation key:** `post_api_v1_organization_facilities`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-facilities:post:createfacilitycontroller`](rbac-matrix.md#row-160); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_facilities_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_facilities_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\CreateFacilityController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/CreateFacilityController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/facilities.post`.
- **Route source:** `apps/api/routes/web.php:160`.

### `GET /api/v1/organization/facilities/{facilityId}`

- **Summary (EN / AR):** Retrieve organization/facilities/{facilityId}. `{{AR:get_api_v1_organization_facilities_facilityId}}`
- **Operation key:** `get_api_v1_organization_facilities_facilityId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-facilities-facilityId:get:getfacilitycontroller`](rbac-matrix.md#row-161); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_facilities_facilityId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_facilities_facilityId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\GetFacilityController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/GetFacilityController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/facilities/{facilityId}.get`.
- **Route source:** `apps/api/routes/web.php:161`.

### `PATCH /api/v1/organization/facilities/{facilityId}`

- **Summary (EN / AR):** Update organization/facilities/{facilityId}. `{{AR:patch_api_v1_organization_facilities_facilityId}}`
- **Operation key:** `patch_api_v1_organization_facilities_facilityId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-facilities-facilityId:patch:updatefacilitycontroller`](rbac-matrix.md#row-162); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_organization_facilities_facilityId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_organization_facilities_facilityId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\UpdateFacilityController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/UpdateFacilityController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/facilities/{facilityId}.patch`.
- **Route source:** `apps/api/routes/web.php:162`.

### `GET /api/v1/organization/units`

- **Summary (EN / AR):** Retrieve organization/units. `{{AR:get_api_v1_organization_units}}`
- **Operation key:** `get_api_v1_organization_units`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-units:get:listorganizationunitscontroller`](rbac-matrix.md#row-163); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_units_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_units_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\ListOrganizationUnitsController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/ListOrganizationUnitsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/units.get`.
- **Route source:** `apps/api/routes/web.php:163`.

### `POST /api/v1/organization/units`

- **Summary (EN / AR):** Create or execute organization/units. `{{AR:post_api_v1_organization_units}}`
- **Operation key:** `post_api_v1_organization_units`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-units:post:createorganizationunitcontroller`](rbac-matrix.md#row-164); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_units_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_units_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\CreateOrganizationUnitController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/CreateOrganizationUnitController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/units.post`.
- **Route source:** `apps/api/routes/web.php:164`.

### `POST /api/v1/organization/units/reorder`

- **Summary (EN / AR):** Create or execute organization/units/reorder. `{{AR:post_api_v1_organization_units_reorder}}`
- **Operation key:** `post_api_v1_organization_units_reorder`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-units-reorder:post:reorderorganizationunitscontroller`](rbac-matrix.md#row-165); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_units_reorder_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_units_reorder_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\ReorderOrganizationUnitsController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/ReorderOrganizationUnitsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/units/reorder.post`.
- **Route source:** `apps/api/routes/web.php:165`.

### `GET /api/v1/organization/units/{unitId}`

- **Summary (EN / AR):** Retrieve organization/units/{unitId}. `{{AR:get_api_v1_organization_units_unitId}}`
- **Operation key:** `get_api_v1_organization_units_unitId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-units-unitId:get:getorganizationunitcontroller`](rbac-matrix.md#row-166); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_units_unitId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_units_unitId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\GetOrganizationUnitController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/GetOrganizationUnitController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/units/{unitId}.get`.
- **Route source:** `apps/api/routes/web.php:166`.

### `PATCH /api/v1/organization/units/{unitId}`

- **Summary (EN / AR):** Update organization/units/{unitId}. `{{AR:patch_api_v1_organization_units_unitId}}`
- **Operation key:** `patch_api_v1_organization_units_unitId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-units-unitId:patch:updateorganizationunitcontroller`](rbac-matrix.md#row-167); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_organization_units_unitId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_organization_units_unitId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\UpdateOrganizationUnitController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/UpdateOrganizationUnitController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/units/{unitId}.patch`.
- **Route source:** `apps/api/routes/web.php:167`.

### `GET /api/v1/organization/job-titles`

- **Summary (EN / AR):** Retrieve organization/job titles. `{{AR:get_api_v1_organization_job_titles}}`
- **Operation key:** `get_api_v1_organization_job_titles`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-job-titles:get:listjobtitlescontroller`](rbac-matrix.md#row-168); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_job_titles_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_job_titles_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\ListJobTitlesController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/ListJobTitlesController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/job-titles.get`.
- **Route source:** `apps/api/routes/web.php:168`.

### `POST /api/v1/organization/job-titles`

- **Summary (EN / AR):** Create or execute organization/job titles. `{{AR:post_api_v1_organization_job_titles}}`
- **Operation key:** `post_api_v1_organization_job_titles`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-job-titles:post:createjobtitlecontroller`](rbac-matrix.md#row-169); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_job_titles_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_job_titles_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\CreateJobTitleController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/CreateJobTitleController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/job-titles.post`.
- **Route source:** `apps/api/routes/web.php:169`.

### `GET /api/v1/organization/positions`

- **Summary (EN / AR):** Retrieve organization/positions. `{{AR:get_api_v1_organization_positions}}`
- **Operation key:** `get_api_v1_organization_positions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-positions:get:listpositionscontroller`](rbac-matrix.md#row-170); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_positions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_positions_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\ListPositionsController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/ListPositionsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/positions.get`.
- **Route source:** `apps/api/routes/web.php:170`.

### `POST /api/v1/organization/positions`

- **Summary (EN / AR):** Create or execute organization/positions. `{{AR:post_api_v1_organization_positions}}`
- **Operation key:** `post_api_v1_organization_positions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-positions:post:createpositioncontroller`](rbac-matrix.md#row-171); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_positions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_positions_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\CreatePositionController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/CreatePositionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/positions.post`.
- **Route source:** `apps/api/routes/web.php:171`.

### `GET /api/v1/organization/positions/{positionId}`

- **Summary (EN / AR):** Retrieve organization/positions/{positionId}. `{{AR:get_api_v1_organization_positions_positionId}}`
- **Operation key:** `get_api_v1_organization_positions_positionId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-positions-positionId:get:getpositioncontroller`](rbac-matrix.md#row-172); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_positions_positionId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_positions_positionId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\GetPositionController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/GetPositionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/positions/{positionId}.get`.
- **Route source:** `apps/api/routes/web.php:172`.

### `PATCH /api/v1/organization/positions/{positionId}`

- **Summary (EN / AR):** Update organization/positions/{positionId}. `{{AR:patch_api_v1_organization_positions_positionId}}`
- **Operation key:** `patch_api_v1_organization_positions_positionId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-positions-positionId:patch:updatepositioncontroller`](rbac-matrix.md#row-173); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_organization_positions_positionId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_organization_positions_positionId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\UpdatePositionController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/UpdatePositionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/positions/{positionId}.patch`.
- **Route source:** `apps/api/routes/web.php:173`.

### `GET /api/v1/organization/people`

- **Summary (EN / AR):** Retrieve organization/people. `{{AR:get_api_v1_organization_people}}`
- **Operation key:** `get_api_v1_organization_people`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-people:get:listpeoplecontroller`](rbac-matrix.md#row-174); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_people_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_people_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\ListPeopleController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/ListPeopleController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/people.get`.
- **Route source:** `apps/api/routes/web.php:174`.

### `POST /api/v1/organization/people`

- **Summary (EN / AR):** Create or execute organization/people. `{{AR:post_api_v1_organization_people}}`
- **Operation key:** `post_api_v1_organization_people`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-people:post:createpersoncontroller`](rbac-matrix.md#row-175); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_people_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_people_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\CreatePersonController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/CreatePersonController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/people.post`.
- **Route source:** `apps/api/routes/web.php:175`.

### `GET /api/v1/organization/people/{personId}/reference`

- **Summary (EN / AR):** Retrieve organization/people/{personId}/reference. `{{AR:get_api_v1_organization_people_personId_reference}}`
- **Operation key:** `get_api_v1_organization_people_personId_reference`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-people-personId-reference:get:getpersonreferencecontroller`](rbac-matrix.md#row-176); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_people_personId_reference_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_people_personId_reference_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\GetPersonReferenceController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/GetPersonReferenceController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/people/{personId}/reference.get`.
- **Route source:** `apps/api/routes/web.php:176`.

### `GET /api/v1/organization/people/{personId}`

- **Summary (EN / AR):** Retrieve organization/people/{personId}. `{{AR:get_api_v1_organization_people_personId}}`
- **Operation key:** `get_api_v1_organization_people_personId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-people-personId:get:getpersoncontroller`](rbac-matrix.md#row-177); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_people_personId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_people_personId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\GetPersonController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/GetPersonController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/people/{personId}.get`.
- **Route source:** `apps/api/routes/web.php:177`.

### `PATCH /api/v1/organization/people/{personId}`

- **Summary (EN / AR):** Update organization/people/{personId}. `{{AR:patch_api_v1_organization_people_personId}}`
- **Operation key:** `patch_api_v1_organization_people_personId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-people-personId:patch:updatepersoncontroller`](rbac-matrix.md#row-178); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_organization_people_personId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_organization_people_personId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\UpdatePersonController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/UpdatePersonController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/people/{personId}.patch`.
- **Route source:** `apps/api/routes/web.php:178`.

### `GET /api/v1/organization/assignments`

- **Summary (EN / AR):** Retrieve organization/assignments. `{{AR:get_api_v1_organization_assignments}}`
- **Operation key:** `get_api_v1_organization_assignments`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-assignments:get:listassignmentscontroller`](rbac-matrix.md#row-179); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_assignments_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_assignments_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\ListAssignmentsController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/ListAssignmentsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/assignments.get`.
- **Route source:** `apps/api/routes/web.php:179`.

### `POST /api/v1/organization/assignments`

- **Summary (EN / AR):** Create or execute organization/assignments. `{{AR:post_api_v1_organization_assignments}}`
- **Operation key:** `post_api_v1_organization_assignments`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-assignments:post:createassignmentcontroller`](rbac-matrix.md#row-180); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_assignments_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_assignments_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\CreateAssignmentController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/CreateAssignmentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/assignments.post`.
- **Route source:** `apps/api/routes/web.php:180`.

### `POST /api/v1/organization/assignments/{assignmentId}/end`

- **Summary (EN / AR):** Create or execute organization/assignments/{assignmentId}/end. `{{AR:post_api_v1_organization_assignments_assignmentId_end}}`
- **Operation key:** `post_api_v1_organization_assignments_assignmentId_end`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-assignments-assignmentId-end:post:endassignmentcontroller`](rbac-matrix.md#row-181); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_assignments_assignmentId_end_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_assignments_assignmentId_end_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\EndAssignmentController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/EndAssignmentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/assignments/{assignmentId}/end.post`.
- **Route source:** `apps/api/routes/web.php:181`.

### `GET /api/v1/organization/supervisory-relationships`

- **Summary (EN / AR):** Retrieve organization/supervisory relationships. `{{AR:get_api_v1_organization_supervisory_relationships}}`
- **Operation key:** `get_api_v1_organization_supervisory_relationships`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-supervisory-relationships:get:supervisoryrelationshipcontroller`](rbac-matrix.md#row-182); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_supervisory_relationships_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_supervisory_relationships_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\SupervisoryRelationshipController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/SupervisoryRelationshipController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/supervisory-relationships.get`.
- **Route source:** `apps/api/routes/web.php:182`.

### `POST /api/v1/organization/supervisory-relationships`

- **Summary (EN / AR):** Create or execute organization/supervisory relationships. `{{AR:post_api_v1_organization_supervisory_relationships}}`
- **Operation key:** `post_api_v1_organization_supervisory_relationships`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-supervisory-relationships:post:supervisoryrelationshipcontroller`](rbac-matrix.md#row-183); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_supervisory_relationships_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_supervisory_relationships_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\SupervisoryRelationshipController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/SupervisoryRelationshipController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/supervisory-relationships.post`.
- **Route source:** `apps/api/routes/web.php:183`.

### `POST /api/v1/organization/import-jobs`

- **Summary (EN / AR):** Create or execute organization/import jobs. `{{AR:post_api_v1_organization_import_jobs}}`
- **Operation key:** `post_api_v1_organization_import_jobs`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-import-jobs:post:submitimportjobcontroller`](rbac-matrix.md#row-184); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_import_jobs_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_import_jobs_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\SubmitImportJobController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/SubmitImportJobController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/import-jobs.post`.
- **Route source:** `apps/api/routes/web.php:184`.

### `GET /api/v1/organization/import-jobs/{jobId}`

- **Summary (EN / AR):** Retrieve organization/import jobs/{jobId}. `{{AR:get_api_v1_organization_import_jobs_jobId}}`
- **Operation key:** `get_api_v1_organization_import_jobs_jobId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-import-jobs-jobId:get:getimportjobcontroller`](rbac-matrix.md#row-185); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_import_jobs_jobId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_import_jobs_jobId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\GetImportJobController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/GetImportJobController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/import-jobs/{jobId}.get`.
- **Route source:** `apps/api/routes/web.php:185`.

### `GET /api/v1/organization/import-jobs/{jobId}/rows`

- **Summary (EN / AR):** Retrieve organization/import jobs/{jobId}/rows. `{{AR:get_api_v1_organization_import_jobs_jobId_rows}}`
- **Operation key:** `get_api_v1_organization_import_jobs_jobId_rows`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-import-jobs-jobId-rows:get:listimportjobrowscontroller`](rbac-matrix.md#row-186); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_import_jobs_jobId_rows_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_import_jobs_jobId_rows_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\ListImportJobRowsController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/ListImportJobRowsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/import-jobs/{jobId}/rows.get`.
- **Route source:** `apps/api/routes/web.php:186`.

### `POST /api/v1/organization/import-jobs/{jobId}/{jobAction}`

- **Summary (EN / AR):** Create or execute organization/import jobs/{jobId}/{jobAction}. `{{AR:post_api_v1_organization_import_jobs_jobId_jobAction}}`
- **Operation key:** `post_api_v1_organization_import_jobs_jobId_jobAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-import-jobs-jobId-jobAction:post:transitionimportjobcontroller`](rbac-matrix.md#row-187); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_import_jobs_jobId_jobAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_import_jobs_jobId_jobAction_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Organization\TransitionImportJobController`.
- **Controller source:** `apps/api/app/Http/Controllers/Organization/TransitionImportJobController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/import-jobs/{jobId}/{jobAction}.post`.
- **Route source:** `apps/api/routes/web.php:187`.

**Reporting**

### `GET /api/v1/reports/{reportId}`

- **Summary (EN / AR):** Retrieve reports/{reportId}. `{{AR:get_api_v1_reports_reportId}}`
- **Operation key:** `get_api_v1_reports_reportId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-reports-reportId:get:getreportcontroller`](rbac-matrix.md#row-144); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_reports_reportId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_reports_reportId_response` (schema placeholder).
- **Status codes:** `200, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Reporting\Http\GetReportController`.
- **Controller source:** `apps/api/Modules/Reporting/Http/GetReportController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/reports/{reportId}.get`.
- **Route source:** `apps/api/routes/web.php:144`.

### `GET /api/v1/exports/{exportId}`

- **Summary (EN / AR):** Retrieve exports/{exportId}. `{{AR:get_api_v1_exports_exportId}}`
- **Operation key:** `get_api_v1_exports_exportId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-exports-exportId:get:downloadexportcontroller`](rbac-matrix.md#row-145); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_exports_exportId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_exports_exportId_response` (schema placeholder).
- **Status codes:** `200, 400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Reporting\Http\DownloadExportController`.
- **Controller source:** `apps/api/Modules/Reporting/Http/DownloadExportController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/exports/{exportId}.get`.
- **Route source:** `apps/api/routes/web.php:145`.

### `GET /api/v1/dashboards/{dashboardId}`

- **Summary (EN / AR):** Retrieve dashboards/{dashboardId}. `{{AR:get_api_v1_dashboards_dashboardId}}`
- **Operation key:** `get_api_v1_dashboards_dashboardId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-dashboards-dashboardId:get:getdashboardcontroller`](rbac-matrix.md#row-146); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_dashboards_dashboardId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_dashboards_dashboardId_response` (schema placeholder).
- **Status codes:** `200, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Reporting\Http\GetDashboardController`.
- **Controller source:** `apps/api/Modules/Reporting/Http/GetDashboardController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/dashboards/{dashboardId}.get`.
- **Route source:** `apps/api/routes/web.php:146`.

### `POST /api/v1/reports/{reportId}/exports`

- **Summary (EN / AR):** Create or execute reports/{reportId}/exports. `{{AR:post_api_v1_reports_reportId_exports}}`
- **Operation key:** `post_api_v1_reports_reportId_exports`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-reports-reportId-exports:post:createreportexportcontroller`](rbac-matrix.md#row-153); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_reports_reportId_exports_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_reports_reportId_exports_response` (schema placeholder).
- **Status codes:** `202, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Reporting\Http\CreateReportExportController`.
- **Controller source:** `apps/api/Modules/Reporting/Http/CreateReportExportController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/reports/{reportId}/exports.post`.
- **Route source:** `apps/api/routes/web.php:153`.

### `GET /api/v1/reports`

- **Summary (EN / AR):** Retrieve reports. `{{AR:get_api_v1_reports}}`
- **Operation key:** `get_api_v1_reports`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-reports:get:listreportscontroller`](rbac-matrix.md#row-239); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_reports_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_reports_response` (schema placeholder).
- **Status codes:** `200, 400, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Reporting\Http\ListReportsController`.
- **Controller source:** `apps/api/Modules/Reporting/Http/ListReportsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/reports.get`.
- **Route source:** `apps/api/routes/web.php:239`.

### `GET /api/v1/dashboards`

- **Summary (EN / AR):** Retrieve dashboards. `{{AR:get_api_v1_dashboards}}`
- **Operation key:** `get_api_v1_dashboards`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-dashboards:get:listdashboardscontroller`](rbac-matrix.md#row-240); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_dashboards_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_dashboards_response` (schema placeholder).
- **Status codes:** `200, 400, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Reporting\Http\ListDashboardsController`.
- **Controller source:** `apps/api/Modules/Reporting/Http/ListDashboardsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/dashboards.get`.
- **Route source:** `apps/api/routes/web.php:240`.

**Search**

### `GET /api/v1/search`

- **Summary (EN / AR):** Retrieve search. `{{AR:get_api_v1_search}}`
- **Operation key:** `get_api_v1_search`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-search:get:searchcontroller`](rbac-matrix.md#row-141); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_search_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_search_response` (schema placeholder).
- **Status codes:** `200, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Search\Http\SearchController`.
- **Controller source:** `apps/api/Modules/Search/Http/SearchController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/search.get`.
- **Route source:** `apps/api/routes/web.php:141`.

**Tasks**

### `GET /api/v1/tasks`

- **Summary (EN / AR):** Retrieve tasks. `{{AR:get_api_v1_tasks}}`
- **Operation key:** `get_api_v1_tasks`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-index:get:taskcontroller::index`](rbac-matrix.md#row-231); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_tasks_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_tasks_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\TaskController::index`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/TaskController.php::index`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks.get`.
- **Route source:** `apps/api/routes/web.php:231`.

### `GET /api/v1/tasks/{taskId}/comments`

- **Summary (EN / AR):** Retrieve tasks/{taskId}/comments. `{{AR:get_api_v1_tasks_taskId_comments}}`
- **Operation key:** `get_api_v1_tasks_taskId_comments`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-taskId-comments-listcomments:get:taskengagementcontroller::listcomments`](rbac-matrix.md#row-232); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_tasks_taskId_comments_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_tasks_taskId_comments_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\TaskEngagementController::listComments`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/TaskEngagementController.php::listComments`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/{taskId}/comments.get`.
- **Route source:** `apps/api/routes/web.php:232`.

### `GET /api/v1/tasks/{taskId}`

- **Summary (EN / AR):** Retrieve tasks/{taskId}. `{{AR:get_api_v1_tasks_taskId}}`
- **Operation key:** `get_api_v1_tasks_taskId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-taskId-show:get:taskcontroller::show`](rbac-matrix.md#row-233); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_tasks_taskId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_tasks_taskId_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\TaskController::show`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/TaskController.php::show`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/{taskId}.get`.
- **Route source:** `apps/api/routes/web.php:233`.

### `PATCH /api/v1/tasks/{taskId}`

- **Summary (EN / AR):** Update tasks/{taskId}. `{{AR:patch_api_v1_tasks_taskId}}`
- **Operation key:** `patch_api_v1_tasks_taskId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-taskId-update:patch:taskcontroller::update`](rbac-matrix.md#row-234); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_tasks_taskId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_tasks_taskId_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\TaskController::update`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/TaskController.php::update`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/{taskId}.patch`.
- **Route source:** `apps/api/routes/web.php:234`.

### `POST /api/v1/tasks`

- **Summary (EN / AR):** Create or execute tasks. `{{AR:post_api_v1_tasks}}`
- **Operation key:** `post_api_v1_tasks`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-store:post:taskcontroller::store`](rbac-matrix.md#row-254); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_tasks_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_tasks_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\TaskController::store`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/TaskController.php::store`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks.post`.
- **Route source:** `apps/api/routes/web.php:254`.

### `POST /api/v1/tasks/from-step/{stepId}`

- **Summary (EN / AR):** Create or execute tasks/from step/{stepId}. `{{AR:post_api_v1_tasks_from_step_stepId}}`
- **Operation key:** `post_api_v1_tasks_from_step_stepId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-from-step-stepId-fromstep:post:taskcontroller::fromstep`](rbac-matrix.md#row-255); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_tasks_from_step_stepId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_tasks_from_step_stepId_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\TaskController::fromStep`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/TaskController.php::fromStep`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/from-step/{stepId}.post`.
- **Route source:** `apps/api/routes/web.php:255`.

### `POST /api/v1/tasks/{taskId}/participants`

- **Summary (EN / AR):** Create or execute tasks/{taskId}/participants. `{{AR:post_api_v1_tasks_taskId_participants}}`
- **Operation key:** `post_api_v1_tasks_taskId_participants`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-taskId-participants-addparticipant:post:taskengagementcontroller::addparticipant`](rbac-matrix.md#row-256); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_tasks_taskId_participants_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_tasks_taskId_participants_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\TaskEngagementController::addParticipant`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/TaskEngagementController.php::addParticipant`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/{taskId}/participants.post`.
- **Route source:** `apps/api/routes/web.php:256`.

### `POST /api/v1/tasks/{taskId}/comments`

- **Summary (EN / AR):** Create or execute tasks/{taskId}/comments. `{{AR:post_api_v1_tasks_taskId_comments}}`
- **Operation key:** `post_api_v1_tasks_taskId_comments`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-taskId-comments-addcomment:post:taskengagementcontroller::addcomment`](rbac-matrix.md#row-257); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_tasks_taskId_comments_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_tasks_taskId_comments_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\TaskEngagementController::addComment`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/TaskEngagementController.php::addComment`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/{taskId}/comments.post`.
- **Route source:** `apps/api/routes/web.php:257`.

### `POST /api/v1/tasks/{taskId}/{workflowTaskAction}`

- **Summary (EN / AR):** Create or execute tasks/{taskId}/{workflowTaskAction}. `{{AR:post_api_v1_tasks_taskId_workflowTaskAction}}`
- **Operation key:** `post_api_v1_tasks_taskId_workflowTaskAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-taskId-workflowTaskAction-transition:post:taskcontroller::transition`](rbac-matrix.md#row-258); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_tasks_taskId_workflowTaskAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_tasks_taskId_workflowTaskAction_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\TaskController::transition`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/TaskController.php::transition`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/{taskId}/{workflowTaskAction}.post`.
- **Route source:** `apps/api/routes/web.php:258`.

**Work Definition Versions**

### `GET /api/v1/work-definition-versions/{versionId}`

- **Summary (EN / AR):** Retrieve work definition versions/{versionId}. `{{AR:get_api_v1_work_definition_versions_versionId}}`
- **Operation key:** `get_api_v1_work_definition_versions_versionId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definition-versions-versionId-showversionroute:get:workdefinitioncontroller::showversionroute`](rbac-matrix.md#row-224); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_work_definition_versions_versionId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_work_definition_versions_versionId_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkDefinitionController::showVersionRoute`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkDefinitionController.php::showVersionRoute`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definition-versions/{versionId}.get`.
- **Route source:** `apps/api/routes/web.php:224`.

### `POST /api/v1/work-definition-versions/{versionId}/{versionAction}`

- **Summary (EN / AR):** Create or execute work definition versions/{versionId}/{versionAction}. `{{AR:post_api_v1_work_definition_versions_versionId_versionAction}}`
- **Operation key:** `post_api_v1_work_definition_versions_versionId_versionAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definition-versions-versionId-versionAction-transition:post:workdefinitioncontroller::transition`](rbac-matrix.md#row-249); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_work_definition_versions_versionId_versionAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_work_definition_versions_versionId_versionAction_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkDefinitionController::transition`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkDefinitionController.php::transition`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definition-versions/{versionId}/{versionAction}.post`.
- **Route source:** `apps/api/routes/web.php:249`.

**Work Definitions**

### `GET /api/v1/work-definitions`

- **Summary (EN / AR):** Retrieve work definitions. `{{AR:get_api_v1_work_definitions}}`
- **Operation key:** `get_api_v1_work_definitions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definitions-index:get:workdefinitioncontroller::index`](rbac-matrix.md#row-221); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_work_definitions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_work_definitions_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkDefinitionController::index`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkDefinitionController.php::index`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definitions.get`.
- **Route source:** `apps/api/routes/web.php:221`.

### `GET /api/v1/work-definitions/{definitionId}`

- **Summary (EN / AR):** Retrieve work definitions/{definitionId}. `{{AR:get_api_v1_work_definitions_definitionId}}`
- **Operation key:** `get_api_v1_work_definitions_definitionId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definitions-definitionId-show:get:workdefinitioncontroller::show`](rbac-matrix.md#row-222); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_work_definitions_definitionId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_work_definitions_definitionId_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkDefinitionController::show`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkDefinitionController.php::show`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definitions/{definitionId}.get`.
- **Route source:** `apps/api/routes/web.php:222`.

### `GET /api/v1/work-definitions/{definitionId}/versions`

- **Summary (EN / AR):** Retrieve work definitions/{definitionId}/versions. `{{AR:get_api_v1_work_definitions_definitionId_versions}}`
- **Operation key:** `get_api_v1_work_definitions_definitionId_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definitions-definitionId-versions-versions:get:workdefinitioncontroller::versions`](rbac-matrix.md#row-223); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_work_definitions_definitionId_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_work_definitions_definitionId_versions_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkDefinitionController::versions`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkDefinitionController.php::versions`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definitions/{definitionId}/versions.get`.
- **Route source:** `apps/api/routes/web.php:223`.

### `POST /api/v1/work-definitions`

- **Summary (EN / AR):** Create or execute work definitions. `{{AR:post_api_v1_work_definitions}}`
- **Operation key:** `post_api_v1_work_definitions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definitions-store:post:workdefinitioncontroller::store`](rbac-matrix.md#row-247); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_work_definitions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_work_definitions_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkDefinitionController::store`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkDefinitionController.php::store`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definitions.post`.
- **Route source:** `apps/api/routes/web.php:247`.

### `POST /api/v1/work-definitions/{definitionId}/versions`

- **Summary (EN / AR):** Create or execute work definitions/{definitionId}/versions. `{{AR:post_api_v1_work_definitions_definitionId_versions}}`
- **Operation key:** `post_api_v1_work_definitions_definitionId_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definitions-definitionId-versions-versions:post:workdefinitioncontroller::versions`](rbac-matrix.md#row-248); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_work_definitions_definitionId_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_work_definitions_definitionId_versions_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkDefinitionController::versions`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkDefinitionController.php::versions`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definitions/{definitionId}/versions.post`.
- **Route source:** `apps/api/routes/web.php:248`.

**Work Records**

### `GET /api/v1/work-records`

- **Summary (EN / AR):** Retrieve work records. `{{AR:get_api_v1_work_records}}`
- **Operation key:** `get_api_v1_work_records`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-records:get:listauthorizedworkrecordscontroller`](rbac-matrix.md#row-194); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_work_records_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_work_records_response` (schema placeholder).
- **Status codes:** `400, 401`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Http\ListAuthorizedWorkRecordsController`.
- **Controller source:** `apps/api/Modules/WorkRecords/Features/ListAuthorizedWorkRecords/Http/ListAuthorizedWorkRecordsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-records.get`.
- **Route source:** `apps/api/routes/web.php:194`.

### `GET /api/v1/work-records/{recordId}`

- **Summary (EN / AR):** Retrieve work records/{recordId}. `{{AR:get_api_v1_work_records_recordId}}`
- **Operation key:** `get_api_v1_work_records_recordId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-records-recordId:get:getauthorizedworkrecordcontroller`](rbac-matrix.md#row-195); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_work_records_recordId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_work_records_recordId_response` (schema placeholder).
- **Status codes:** `400, 401, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Http\GetAuthorizedWorkRecordController`.
- **Controller source:** `apps/api/Modules/WorkRecords/Features/GetAuthorizedWorkRecord/Http/GetAuthorizedWorkRecordController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-records/{recordId}.get`.
- **Route source:** `apps/api/routes/web.php:195`.

### `POST /api/v1/work-records`

- **Summary (EN / AR):** Create or execute work records. `{{AR:post_api_v1_work_records}}`
- **Operation key:** `post_api_v1_work_records`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-records:post:submitworkrecordcontroller`](rbac-matrix.md#row-206); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_work_records_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_work_records_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkRecords\Features\SubmitWorkRecord\Http\SubmitWorkRecordController`.
- **Controller source:** `apps/api/Modules/WorkRecords/Features/SubmitWorkRecord/Http/SubmitWorkRecordController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-records.post`.
- **Route source:** `apps/api/routes/web.php:206`.

### `POST /api/v1/work-records/{recordId}/{recordAction}`

- **Summary (EN / AR):** Create or execute work records/{recordId}/{recordAction}. `{{AR:post_api_v1_work_records_recordId_recordAction}}`
- **Operation key:** `post_api_v1_work_records_recordId_recordAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf → project_work_record_read_models`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-records-recordId-recordAction-transition:post:workrecordlifecyclecontroller::transition`](rbac-matrix.md#row-210); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_work_records_recordId_recordAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_work_records_recordId_recordAction_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkRecordLifecycleController::transition`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkRecordLifecycleController.php::transition`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-records/{recordId}/{recordAction}.post`.
- **Route source:** `apps/api/routes/web.php:210`.

### `POST /api/v1/work-records/{recordId}/documents`

- **Summary (EN / AR):** Create or execute work records/{recordId}/documents. `{{AR:post_api_v1_work_records_recordId_documents}}`
- **Operation key:** `post_api_v1_work_records_recordId_documents`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-records-recordId-documents:post:linkdocumentcontroller`](rbac-matrix.md#row-213); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_work_records_recordId_documents_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_work_records_recordId_documents_response` (schema placeholder).
- **Status codes:** `201, 401, 404, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\LinkDocumentController`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/LinkDocumentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-records/{recordId}/documents.post`.
- **Route source:** `apps/api/routes/web.php:213`.

**Workflow**

### `GET /api/v1/workflow/definitions`

- **Summary (EN / AR):** Retrieve workflow/definitions. `{{AR:get_api_v1_workflow_definitions}}`
- **Operation key:** `get_api_v1_workflow_definitions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-definitions-definitions:get:workflowcontroller::definitions`](rbac-matrix.md#row-225); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_workflow_definitions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_workflow_definitions_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkflowController::definitions`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkflowController.php::definitions`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/definitions.get`.
- **Route source:** `apps/api/routes/web.php:225`.

### `GET /api/v1/workflow/definitions/{definitionId}/versions`

- **Summary (EN / AR):** Retrieve workflow/definitions/{definitionId}/versions. `{{AR:get_api_v1_workflow_definitions_definitionId_versions}}`
- **Operation key:** `get_api_v1_workflow_definitions_definitionId_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-definitions-definitionId-versions-versions:get:workflowcontroller::versions`](rbac-matrix.md#row-226); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_workflow_definitions_definitionId_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_workflow_definitions_definitionId_versions_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkflowController::versions`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkflowController.php::versions`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/definitions/{definitionId}/versions.get`.
- **Route source:** `apps/api/routes/web.php:226`.

### `GET /api/v1/workflow/instances`

- **Summary (EN / AR):** Retrieve workflow/instances. `{{AR:get_api_v1_workflow_instances}}`
- **Operation key:** `get_api_v1_workflow_instances`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-instances-instances:get:workflowcontroller::instances`](rbac-matrix.md#row-227); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_workflow_instances_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_workflow_instances_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkflowController::instances`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkflowController.php::instances`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/instances.get`.
- **Route source:** `apps/api/routes/web.php:227`.

### `GET /api/v1/workflow/instances/{instanceId}`

- **Summary (EN / AR):** Retrieve workflow/instances/{instanceId}. `{{AR:get_api_v1_workflow_instances_instanceId}}`
- **Operation key:** `get_api_v1_workflow_instances_instanceId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-instances-instanceId-showinstance:get:workflowcontroller::showinstance`](rbac-matrix.md#row-228); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_workflow_instances_instanceId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_workflow_instances_instanceId_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkflowController::showInstance`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkflowController.php::showInstance`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/instances/{instanceId}.get`.
- **Route source:** `apps/api/routes/web.php:228`.

### `GET /api/v1/workflow/steps`

- **Summary (EN / AR):** Retrieve workflow/steps. `{{AR:get_api_v1_workflow_steps}}`
- **Operation key:** `get_api_v1_workflow_steps`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-steps-listinbox:get:workflowcontroller::listinbox`](rbac-matrix.md#row-229); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_workflow_steps_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_workflow_steps_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkflowController::listInbox`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkflowController.php::listInbox`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/steps.get`.
- **Route source:** `apps/api/routes/web.php:229`.

### `GET /api/v1/workflow/steps/{stepId}`

- **Summary (EN / AR):** Retrieve workflow/steps/{stepId}. `{{AR:get_api_v1_workflow_steps_stepId}}`
- **Operation key:** `get_api_v1_workflow_steps_stepId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-steps-stepId-showstep:get:workflowcontroller::showstep`](rbac-matrix.md#row-230); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_workflow_steps_stepId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_workflow_steps_stepId_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkflowController::showStep`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkflowController.php::showStep`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/steps/{stepId}.get`.
- **Route source:** `apps/api/routes/web.php:230`.

### `POST /api/v1/workflow/definitions`

- **Summary (EN / AR):** Create or execute workflow/definitions. `{{AR:post_api_v1_workflow_definitions}}`
- **Operation key:** `post_api_v1_workflow_definitions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-definitions-definitions:post:workflowcontroller::definitions`](rbac-matrix.md#row-250); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_definitions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_definitions_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkflowController::definitions`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkflowController.php::definitions`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/definitions.post`.
- **Route source:** `apps/api/routes/web.php:250`.

### `POST /api/v1/workflow/definitions/{definitionId}/versions`

- **Summary (EN / AR):** Create or execute workflow/definitions/{definitionId}/versions. `{{AR:post_api_v1_workflow_definitions_definitionId_versions}}`
- **Operation key:** `post_api_v1_workflow_definitions_definitionId_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-definitions-definitionId-versions-versions:post:workflowcontroller::versions`](rbac-matrix.md#row-251); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_definitions_definitionId_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_definitions_definitionId_versions_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkflowController::versions`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkflowController.php::versions`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/definitions/{definitionId}/versions.post`.
- **Route source:** `apps/api/routes/web.php:251`.

### `POST /api/v1/workflow/versions/{versionId}/{workflowLifecycleAction}`

- **Summary (EN / AR):** Create or execute workflow/versions/{versionId}/{workflowLifecycleAction}. `{{AR:post_api_v1_workflow_versions_versionId_workflowLifecycleAction}}`
- **Operation key:** `post_api_v1_workflow_versions_versionId_workflowLifecycleAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-versions-versionId-workflowLifecycleAction-publish:post:workflowcontroller::publish`](rbac-matrix.md#row-252); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_versions_versionId_workflowLifecycleAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_versions_versionId_workflowLifecycleAction_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkflowController::publish`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkflowController.php::publish`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/versions/{versionId}/{workflowLifecycleAction}.post`.
- **Route source:** `apps/api/routes/web.php:252`.

### `POST /api/v1/workflow/instances`

- **Summary (EN / AR):** Create or execute workflow/instances. `{{AR:post_api_v1_workflow_instances}}`
- **Operation key:** `post_api_v1_workflow_instances`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-instances-instances:post:workflowcontroller::instances`](rbac-matrix.md#row-253); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_instances_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_instances_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkflowController::instances`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkflowController.php::instances`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/instances.post`.
- **Route source:** `apps/api/routes/web.php:253`.

### `POST /api/v1/workflow/steps/{stepId}/decisions`

- **Summary (EN / AR):** Create or execute workflow/steps/{stepId}/decisions. `{{AR:post_api_v1_workflow_steps_stepId_decisions}}`
- **Operation key:** `post_api_v1_workflow_steps_stepId_decisions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-steps-stepId-decisions-decidestep:post:workflowcontroller::decidestep`](rbac-matrix.md#row-259); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_steps_stepId_decisions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_steps_stepId_decisions_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkflowController::decideStep`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkflowController.php::decideStep`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/steps/{stepId}/decisions.post`.
- **Route source:** `apps/api/routes/web.php:259`.

### `POST /api/v1/workflow/steps/{stepId}/{stepAction}`

- **Summary (EN / AR):** Create or execute workflow/steps/{stepId}/{stepAction}. `{{AR:post_api_v1_workflow_steps_stepId_stepAction}}`
- **Operation key:** `post_api_v1_workflow_steps_stepId_stepAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-steps-stepId-stepAction-actonstep:post:workflowcontroller::actonstep`](rbac-matrix.md#row-260); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_steps_stepId_stepAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_steps_stepId_stepAction_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkflowController::actOnStep`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkflowController.php::actOnStep`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/steps/{stepId}/{stepAction}.post`.
- **Route source:** `apps/api/routes/web.php:260`.

### `POST /api/v1/workflow/instances/{instanceId}/cancel`

- **Summary (EN / AR):** Create or execute workflow/instances/{instanceId}/cancel. `{{AR:post_api_v1_workflow_instances_instanceId_cancel}}`
- **Operation key:** `post_api_v1_workflow_instances_instanceId_cancel`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-instances-instanceId-cancel-cancelinstance:post:workflowcontroller::cancelinstance`](rbac-matrix.md#row-261); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_instances_instanceId_cancel_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_instances_instanceId_cancel_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `App\Http\Controllers\Api\WorkflowController::cancelInstance`.
- **Controller source:** `apps/api/app/Http/Controllers/Api/WorkflowController.php::cancelInstance`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/instances/{instanceId}/cancel.post`.
- **Route source:** `apps/api/routes/web.php:261`.

## RBAC Matrix

The detailed row pointer on each card corresponds to the generated `rbac-matrix.json` entry.

| Method | Path | Middleware | Session | Principal | CSRF | Throttle |
|---|---|---|---:|---:|---:|---|
| POST | `/api/v1/auth/login` | `web` | no | no | no | `none` |
| POST | `/api/v1/identity/login` | `none` | no | no | no | `none` |
| POST | `/api/v1/identity/activation` | `throttle:6,1` | no | no | no | `6,1` |
| GET | `/api/v1/identity/me` | `identity_session → require_identity_session_principal` | yes | yes | no | `none` |
| POST | `/api/v1/identity/csrf` | `identity_session → require_identity_session_principal` | yes | yes | no | `none` |
| GET | `/api/v1/me` | `identity_session → require_identity_session_principal` | yes | yes | no | `none` |
| GET | `/api/v1/me/scopes` | `identity_session → require_identity_session_principal` | yes | yes | no | `none` |
| PUT | `/api/v1/me/scope` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/identity/logout` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/identity/password` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/identity/accounts/{accountId}/activation` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/documents/uploads/{uploadId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/documents/{documentId}/download` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/temporary-assignments` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/temporary-assignments/{temporaryAssignmentId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/documents/uploads` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/documents/uploads/{uploadId}/complete` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/temporary-assignments` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/temporary-assignments/{temporaryAssignmentId}/revoke` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/internal/documents/versions/{versionId}/scan` | `identity_session → require_identity_session_principal → identity_csrf → throttle:60,1` | yes | yes | yes | `60,1` |
| POST | `/api/v1/internal/documents/versions/{versionId}/reconcile-promotion` | `identity_session → require_identity_session_principal → identity_csrf → throttle:60,1` | yes | yes | yes | `60,1` |
| GET | `/api/v1/notifications` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/notifications/{notificationId}/read` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/search` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/reports/{reportId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/exports/{exportId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/dashboards/{dashboardId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/reports/{reportId}/exports` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/cluster` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/cluster` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| PATCH | `/api/v1/organization/cluster` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/facilities` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/facilities` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/facilities/{facilityId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| PATCH | `/api/v1/organization/facilities/{facilityId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/units` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/units` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/units/reorder` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/units/{unitId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| PATCH | `/api/v1/organization/units/{unitId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/job-titles` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/job-titles` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/positions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/positions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/positions/{positionId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| PATCH | `/api/v1/organization/positions/{positionId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/people` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/people` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/people/{personId}/reference` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/people/{personId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| PATCH | `/api/v1/organization/people/{personId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/assignments` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/assignments` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/assignments/{assignmentId}/end` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/supervisory-relationships` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/supervisory-relationships` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/import-jobs` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/import-jobs/{jobId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/organization/import-jobs/{jobId}/rows` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/organization/import-jobs/{jobId}/{jobAction}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/identity/accounts` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/identity/accounts` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/identity/accounts/{accountId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/identity/accounts/{accountId}/{accountAction}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/work-records` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/work-records/{recordId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/authorization/access-decisions/{decisionId}/explanation` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/authorization/bootstrap` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/authorization/{adminResource}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/authorization/{adminResource}/{resourceId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/work-records` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/work-records/{recordId}/{recordAction}` | `identity_session → require_identity_session_principal → identity_csrf → project_work_record_read_models` | yes | yes | yes | `none` |
| POST | `/api/v1/work-records/{recordId}/documents` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/authorization/access-decisions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/authorization/bootstrap/complete` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/authorization/{adminResource}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| PATCH | `/api/v1/authorization/{adminResource}/{resourceId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/authorization/{adminResource}/{resourceId}/{authorizationAction}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/work-definitions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/work-definitions/{definitionId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/work-definitions/{definitionId}/versions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/work-definition-versions/{versionId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/workflow/definitions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/workflow/definitions/{definitionId}/versions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/workflow/instances` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/workflow/instances/{instanceId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/workflow/steps` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/workflow/steps/{stepId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/tasks` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/tasks/{taskId}/comments` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/tasks/{taskId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| PATCH | `/api/v1/tasks/{taskId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/documents` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/documents/{documentId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/documents/{documentId}/versions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/documents/{documentId}/links` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/reports` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/dashboards` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/work-definitions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/work-definitions/{definitionId}/versions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/work-definition-versions/{versionId}/{versionAction}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/workflow/definitions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/workflow/definitions/{definitionId}/versions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/workflow/versions/{versionId}/{workflowLifecycleAction}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/workflow/instances` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/tasks` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/tasks/from-step/{stepId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/tasks/{taskId}/participants` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/tasks/{taskId}/comments` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/tasks/{taskId}/{workflowTaskAction}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/workflow/steps/{stepId}/decisions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/workflow/steps/{stepId}/{stepAction}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/workflow/instances/{instanceId}/cancel` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/documents` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| PATCH | `/api/v1/documents/{documentId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/documents/{documentId}/versions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/documents/{documentId}/links` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/documents/{documentId}/{documentAction}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/documents/{documentId}/{documentGrantType}-grant` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |

## Error Catalog

- **HttpSupport::problem** — `apps/api/app/Http/Controllers/Api/HttpSupport.php:51-62`
  - RFC 7807-style `application/problem+json` envelope.
- **IdentityApi::problem** — `apps/api/Modules/Identity/Http/IdentityApi.php:52-66`
  - Identity problem envelope with correlation metadata.
- **OrganizationApi::problem** — `apps/api/Modules/Organization/Http/OrganizationApi.php:52-67`
  - Organization problem envelope with correlation metadata.
- **AuthorizationApi::problem** — `apps/api/Modules/Authorization/Http/AuthorizationApi.php`
  - Authorization problem envelope with correlation metadata.
- **ReportingApi::problem** — `apps/api/Modules/Reporting/Http/ReportingApi.php`
  - Reporting problem envelope with correlation metadata.
- **SearchApi::problem** — `apps/api/Modules/Search/Http/SearchApi.php`
  - Search problem envelope with correlation metadata.
- **LinkDocumentController about:blank outliers** — `apps/api/app/Http/Controllers/Api/LinkDocumentController.php:24,29,31`
  - Legacy `about:blank` responses for unauthorized, invalid link, and unavailable document outcomes.

## Exports / Internal / Health

These operational and reporting surfaces are called out separately from their endpoint cards.

- `/up` — Laravel bootstrap health endpoint; OpenAPI `security: []`.
- `/reports` (`/api/v1/reports`) — reporting/export surface; see its endpoint card and RBAC row.
- `/reports/{reportId}` (`/api/v1/reports/{reportId}`) — reporting/export surface; see its endpoint card and RBAC row.
- `/reports/{reportId}/exports` (`/api/v1/reports/{reportId}/exports`) — reporting/export surface; see its endpoint card and RBAC row.
- `/exports/{exportId}` (`/api/v1/exports/{exportId}`) — reporting/export surface; see its endpoint card and RBAC row.
- `/dashboards` (`/api/v1/dashboards`) — reporting/export surface; see its endpoint card and RBAC row.
- `/dashboards/{dashboardId}` (`/api/v1/dashboards/{dashboardId}`) — reporting/export surface; see its endpoint card and RBAC row.
- `/internal/documents/versions/{versionId}/scan` (`/api/v1/internal/documents/versions/{versionId}/scan`) — internal worker route; throttle `60,1` and no identity session middleware.
- `/internal/documents/versions/{versionId}/reconcile-promotion` (`/api/v1/internal/documents/versions/{versionId}/reconcile-promotion`) — internal worker route; throttle `60,1` and no identity session middleware.

## Regeneration & Orval & Coverage

### Regeneration

Run the inventory and contract checks from the repository root:

```shell
make api:inventory
python3 scripts/inventory-routes.py --mode md --json docs/api
python3 scripts/inventory-routes.py --check
npm --prefix apps/web run api:lint
npm --prefix apps/web run api:check
```

### Orval

`apps/web/orval.config.ts` is the frontend generator entry point. Its canonical bundles are
`docs/contracts/api/w1-1.openapi.yaml`, `docs/contracts/api/w1-2.openapi.yaml`, and
`docs/contracts/api/r1-screens.openapi.yaml`. The split files are frozen in this S3 skeleton
and will be refreshed from the canonical contract by the contract-sync slice.

### Coverage

- Live route declarations represented by cards: 119 / 119.
- Bootstrap-only health route represented in the dedicated operational section: `/up`.
- Arabic summary placeholders intentionally remain for S6.

### Contract Diff

Placeholder for S4. The contract-sync slice will add `git diff --stat` output and per-path drift bullets.
