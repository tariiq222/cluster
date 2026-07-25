---
doc_id: API-INV-001
title: Backend Endpoint Inventory
type: engineering
status: accepted
version: 1.0.0
date: 2026-07-26
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

This inventory documents 143 live `Route::` declarations plus the bootstrap health route.
Laravel routes are the runtime source of truth. The canonical contract is `docs/contracts/api/openapi.yaml`.
Arabic summaries remain as inline placeholders for the dedicated translation slice.

## Module Sections

**Authorization**

### `GET /api/v1/authorization/access-decisions/{decisionId}/explanation`

- **Summary (EN / AR):** Retrieve authorization/access decisions/{decisionId}/explanation. `{{AR:get_api_v1_authorization_access_decisions_decisionId_explanation}}`
- **Operation key:** `get_api_v1_authorization_access_decisions_decisionId_explanation`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-access-decisions-decisionId-explanation:get:explainaccessdecisioncontroller`](rbac-matrix.md#row-218); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_authorization_access_decisions_decisionId_explanation_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_authorization_access_decisions_decisionId_explanation_response` (schema placeholder).
- **Status codes:** `400, 401, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Authorization\Features\ExplainAccessDecision\Http\ExplainAccessDecisionController`.
- **Controller source:** `apps/api/Modules/Authorization/Features/ExplainAccessDecision/Http/ExplainAccessDecisionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/access-decisions/{decisionId}/explanation.get`.
- **Route source:** `apps/api/routes/web.php:218`.

### `GET /api/v1/authorization/bootstrap`

- **Summary (EN / AR):** Retrieve authorization/bootstrap. `{{AR:get_api_v1_authorization_bootstrap}}`
- **Operation key:** `get_api_v1_authorization_bootstrap`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-bootstrap:get:getauthorizationbootstrapcontroller`](rbac-matrix.md#row-219); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_authorization_bootstrap_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_authorization_bootstrap_response` (schema placeholder).
- **Status codes:** `400, 401`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Authorization\Features\Bootstrap\Http\GetAuthorizationBootstrapController`.
- **Controller source:** `apps/api/Modules/Authorization/Features/Bootstrap/Http/GetAuthorizationBootstrapController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/bootstrap.get`.
- **Route source:** `apps/api/routes/web.php:219`.

### `GET /api/v1/authorization/{adminResource}`

- **Summary (EN / AR):** Retrieve authorization/{adminResource}. `{{AR:get_api_v1_authorization_adminResource}}`
- **Operation key:** `get_api_v1_authorization_adminResource`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-adminResource:get:authorizationadmincontroller`](rbac-matrix.md#row-220); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_authorization_adminResource_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_authorization_adminResource_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Authorization\Features\Administration\Http\AuthorizationAdminController`.
- **Controller source:** `apps/api/Modules/Authorization/Features/Administration/Http/AuthorizationAdminController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/{adminResource}.get`.
- **Route source:** `apps/api/routes/web.php:220`.

### `GET /api/v1/authorization/{adminResource}/{resourceId}`

- **Summary (EN / AR):** Retrieve authorization/{adminResource}/{resourceId}. `{{AR:get_api_v1_authorization_adminResource_resourceId}}`
- **Operation key:** `get_api_v1_authorization_adminResource_resourceId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-adminResource-resourceId:get:authorizationadmincontroller`](rbac-matrix.md#row-221); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_authorization_adminResource_resourceId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_authorization_adminResource_resourceId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Authorization\Features\Administration\Http\AuthorizationAdminController`.
- **Controller source:** `apps/api/Modules/Authorization/Features/Administration/Http/AuthorizationAdminController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/{adminResource}/{resourceId}.get`.
- **Route source:** `apps/api/routes/web.php:221`.

### `POST /api/v1/authorization/access-decisions`

- **Summary (EN / AR):** Create or execute authorization/access decisions. `{{AR:post_api_v1_authorization_access_decisions}}`
- **Operation key:** `post_api_v1_authorization_access_decisions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-access-decisions:post:decideaccesscontroller`](rbac-matrix.md#row-251); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_authorization_access_decisions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_authorization_access_decisions_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Authorization\Features\DecideAccess\Http\DecideAccessController`.
- **Controller source:** `apps/api/Modules/Authorization/Features/DecideAccess/Http/DecideAccessController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/access-decisions.post`.
- **Route source:** `apps/api/routes/web.php:251`.

### `POST /api/v1/authorization/bootstrap/complete`

- **Summary (EN / AR):** Create or execute authorization/bootstrap/complete. `{{AR:post_api_v1_authorization_bootstrap_complete}}`
- **Operation key:** `post_api_v1_authorization_bootstrap_complete`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-bootstrap-complete:post:completeauthorizationbootstrapcontroller`](rbac-matrix.md#row-252); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_authorization_bootstrap_complete_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_authorization_bootstrap_complete_response` (schema placeholder).
- **Status codes:** `200, 400, 401, 403, 409, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Authorization\Features\Bootstrap\Http\CompleteAuthorizationBootstrapController`.
- **Controller source:** `apps/api/Modules/Authorization/Features/Bootstrap/Http/CompleteAuthorizationBootstrapController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/bootstrap/complete.post`.
- **Route source:** `apps/api/routes/web.php:252`.

### `POST /api/v1/authorization/{adminResource}`

- **Summary (EN / AR):** Create or execute authorization/{adminResource}. `{{AR:post_api_v1_authorization_adminResource}}`
- **Operation key:** `post_api_v1_authorization_adminResource`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-adminResource:post:authorizationadmincontroller`](rbac-matrix.md#row-253); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_authorization_adminResource_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_authorization_adminResource_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Authorization\Features\Administration\Http\AuthorizationAdminController`.
- **Controller source:** `apps/api/Modules/Authorization/Features/Administration/Http/AuthorizationAdminController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/{adminResource}.post`.
- **Route source:** `apps/api/routes/web.php:253`.

### `PATCH /api/v1/authorization/{adminResource}/{resourceId}`

- **Summary (EN / AR):** Update authorization/{adminResource}/{resourceId}. `{{AR:patch_api_v1_authorization_adminResource_resourceId}}`
- **Operation key:** `patch_api_v1_authorization_adminResource_resourceId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-adminResource-resourceId:patch:authorizationadmincontroller`](rbac-matrix.md#row-254); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_authorization_adminResource_resourceId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_authorization_adminResource_resourceId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Authorization\Features\Administration\Http\AuthorizationAdminController`.
- **Controller source:** `apps/api/Modules/Authorization/Features/Administration/Http/AuthorizationAdminController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/{adminResource}/{resourceId}.patch`.
- **Route source:** `apps/api/routes/web.php:254`.

### `POST /api/v1/authorization/{adminResource}/{resourceId}/{authorizationAction}`

- **Summary (EN / AR):** Create or execute authorization/{adminResource}/{resourceId}/{authorizationAction}. `{{AR:post_api_v1_authorization_adminResource_resourceId_authorizationAction}}`
- **Operation key:** `post_api_v1_authorization_adminResource_resourceId_authorizationAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-authorization-adminResource-resourceId-authorizationAction:post:authorizationadmincontroller`](rbac-matrix.md#row-255); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_authorization_adminResource_resourceId_authorizationAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_authorization_adminResource_resourceId_authorizationAction_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Authorization\Features\Administration\Http\AuthorizationAdminController`.
- **Controller source:** `apps/api/Modules/Authorization/Features/Administration/Http/AuthorizationAdminController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/authorization/{adminResource}/{resourceId}/{authorizationAction}.post`.
- **Route source:** `apps/api/routes/web.php:255`.

**Documents**

### `GET /api/v1/documents/uploads/{uploadId}`

- **Summary (EN / AR):** Retrieve documents/uploads/{uploadId}. `{{AR:get_api_v1_documents_uploads_uploadId}}`
- **Operation key:** `get_api_v1_documents_uploads_uploadId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-uploads-uploadId:get:getdocumentuploadstatuscontroller`](rbac-matrix.md#row-134); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_documents_uploads_uploadId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_documents_uploads_uploadId_response` (schema placeholder).
- **Status codes:** `200, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Documents\Features\Upload\Http\GetDocumentUploadStatusController`.
- **Controller source:** `apps/api/Modules/Documents/Features/Upload/Http/GetDocumentUploadStatusController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/uploads/{uploadId}.get`.
- **Route source:** `apps/api/routes/web.php:134`.

### `GET /api/v1/documents/{documentId}/download`

- **Summary (EN / AR):** Retrieve documents/{documentId}/download. `{{AR:get_api_v1_documents_documentId_download}}`
- **Operation key:** `get_api_v1_documents_documentId_download`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-download:get:downloaddocumentcontroller`](rbac-matrix.md#row-135); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_download_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_download_response` (schema placeholder).
- **Status codes:** `400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Documents\Features\DocumentDownload\Http\DownloadDocumentController`.
- **Controller source:** `apps/api/Modules/Documents/Features/DocumentDownload/Http/DownloadDocumentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/download.get`.
- **Route source:** `apps/api/routes/web.php:135`.

### `POST /api/v1/documents/uploads`

- **Summary (EN / AR):** Create or execute documents/uploads. `{{AR:post_api_v1_documents_uploads}}`
- **Operation key:** `post_api_v1_documents_uploads`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-uploads:post:initiatedocumentuploadcontroller`](rbac-matrix.md#row-144); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_uploads_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_uploads_response` (schema placeholder).
- **Status codes:** `201, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Documents\Features\Upload\Http\InitiateDocumentUploadController`.
- **Controller source:** `apps/api/Modules/Documents/Features/Upload/Http/InitiateDocumentUploadController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/uploads.post`.
- **Route source:** `apps/api/routes/web.php:144`.

### `POST /api/v1/documents/uploads/{uploadId}/complete`

- **Summary (EN / AR):** Create or execute documents/uploads/{uploadId}/complete. `{{AR:post_api_v1_documents_uploads_uploadId_complete}}`
- **Operation key:** `post_api_v1_documents_uploads_uploadId_complete`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-uploads-uploadId-complete:post:completedocumentuploadcontroller`](rbac-matrix.md#row-145); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_uploads_uploadId_complete_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_uploads_uploadId_complete_response` (schema placeholder).
- **Status codes:** `202, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Documents\Features\Upload\Http\CompleteDocumentUploadController`.
- **Controller source:** `apps/api/Modules/Documents/Features/Upload/Http/CompleteDocumentUploadController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/uploads/{uploadId}/complete.post`.
- **Route source:** `apps/api/routes/web.php:145`.

### `GET /api/v1/documents`

- **Summary (EN / AR):** Retrieve documents. `{{AR:get_api_v1_documents}}`
- **Operation key:** `get_api_v1_documents`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents:get:listdocumentscontroller`](rbac-matrix.md#row-271); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_documents_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_documents_response` (schema placeholder).
- **Status codes:** `400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Documents\Features\DocumentLifecycle\Http\ListDocumentsController`.
- **Controller source:** `apps/api/Modules/Documents/Features/DocumentLifecycle/Http/ListDocumentsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents.get`.
- **Route source:** `apps/api/routes/web.php:271`.

### `GET /api/v1/documents/{documentId}`

- **Summary (EN / AR):** Retrieve documents/{documentId}. `{{AR:get_api_v1_documents_documentId}}`
- **Operation key:** `get_api_v1_documents_documentId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId:get:getdocumentcontroller`](rbac-matrix.md#row-272); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_response` (schema placeholder).
- **Status codes:** `400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Documents\Features\DocumentLifecycle\Http\GetDocumentController`.
- **Controller source:** `apps/api/Modules/Documents/Features/DocumentLifecycle/Http/GetDocumentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}.get`.
- **Route source:** `apps/api/routes/web.php:272`.

### `GET /api/v1/documents/{documentId}/versions`

- **Summary (EN / AR):** Retrieve documents/{documentId}/versions. `{{AR:get_api_v1_documents_documentId_versions}}`
- **Operation key:** `get_api_v1_documents_documentId_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-versions:get:listdocumentversionscontroller`](rbac-matrix.md#row-273); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_versions_response` (schema placeholder).
- **Status codes:** `400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Documents\Features\DocumentVersion\Http\ListDocumentVersionsController`.
- **Controller source:** `apps/api/Modules/Documents/Features/DocumentVersion/Http/ListDocumentVersionsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/versions.get`.
- **Route source:** `apps/api/routes/web.php:273`.

### `GET /api/v1/documents/{documentId}/links`

- **Summary (EN / AR):** Retrieve documents/{documentId}/links. `{{AR:get_api_v1_documents_documentId_links}}`
- **Operation key:** `get_api_v1_documents_documentId_links`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-links:get:listdocumentlinkscontroller`](rbac-matrix.md#row-274); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_links_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_documents_documentId_links_response` (schema placeholder).
- **Status codes:** `400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Documents\Features\DocumentLink\Http\ListDocumentLinksController`.
- **Controller source:** `apps/api/Modules/Documents/Features/DocumentLink/Http/ListDocumentLinksController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/links.get`.
- **Route source:** `apps/api/routes/web.php:274`.

### `POST /api/v1/documents`

- **Summary (EN / AR):** Create or execute documents. `{{AR:post_api_v1_documents}}`
- **Operation key:** `post_api_v1_documents`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents:post:createdocumentcontroller`](rbac-matrix.md#row-299); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_response` (schema placeholder).
- **Status codes:** `201, 400, 403, 409`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Documents\Features\DocumentLifecycle\Http\CreateDocumentController`.
- **Controller source:** `apps/api/Modules/Documents/Features/DocumentLifecycle/Http/CreateDocumentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents.post`.
- **Route source:** `apps/api/routes/web.php:299`.

### `PATCH /api/v1/documents/{documentId}`

- **Summary (EN / AR):** Update documents/{documentId}. `{{AR:patch_api_v1_documents_documentId}}`
- **Operation key:** `patch_api_v1_documents_documentId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId:patch:updatedocumentcontroller`](rbac-matrix.md#row-300); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_documents_documentId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_documents_documentId_response` (schema placeholder).
- **Status codes:** `400, 404, 409, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Documents\Features\DocumentLifecycle\Http\UpdateDocumentController`.
- **Controller source:** `apps/api/Modules/Documents/Features/DocumentLifecycle/Http/UpdateDocumentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}.patch`.
- **Route source:** `apps/api/routes/web.php:300`.

### `POST /api/v1/documents/{documentId}/versions`

- **Summary (EN / AR):** Create or execute documents/{documentId}/versions. `{{AR:post_api_v1_documents_documentId_versions}}`
- **Operation key:** `post_api_v1_documents_documentId_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-versions:post:adddocumentversioncontroller`](rbac-matrix.md#row-301); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_versions_response` (schema placeholder).
- **Status codes:** `201, 400, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Documents\Features\DocumentVersion\Http\AddDocumentVersionController`.
- **Controller source:** `apps/api/Modules/Documents/Features/DocumentVersion/Http/AddDocumentVersionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/versions.post`.
- **Route source:** `apps/api/routes/web.php:301`.

### `POST /api/v1/documents/{documentId}/links`

- **Summary (EN / AR):** Create or execute documents/{documentId}/links. `{{AR:post_api_v1_documents_documentId_links}}`
- **Operation key:** `post_api_v1_documents_documentId_links`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-links:post:documentlinkcontroller`](rbac-matrix.md#row-302); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_links_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_links_response` (schema placeholder).
- **Status codes:** `201`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `DocumentLinkController`.
- **Controller source:** `controller source unresolved`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/links.post`.
- **Route source:** `apps/api/routes/web.php:302`.

### `POST /api/v1/documents/{documentId}/{documentAction}`

- **Summary (EN / AR):** Create or execute documents/{documentId}/{documentAction}. `{{AR:post_api_v1_documents_documentId_documentAction}}`
- **Operation key:** `post_api_v1_documents_documentId_documentAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-documentAction:post:transitiondocumentcontroller`](rbac-matrix.md#row-303); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_documentAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_documentAction_response` (schema placeholder).
- **Status codes:** `400, 404, 409, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Documents\Features\DocumentLifecycle\Http\TransitionDocumentController`.
- **Controller source:** `apps/api/Modules/Documents/Features/DocumentLifecycle/Http/TransitionDocumentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/{documentAction}.post`.
- **Route source:** `apps/api/routes/web.php:303`.

### `POST /api/v1/documents/{documentId}/{documentGrantType}-grant`

- **Summary (EN / AR):** Create or execute documents/{documentId}/{documentGrantType} grant. `{{AR:post_api_v1_documents_documentId_documentGrantType_grant}}`
- **Operation key:** `post_api_v1_documents_documentId_documentGrantType_grant`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-documents-documentId-documentGrantType-grant:post:createdocumentgrantcontroller`](rbac-matrix.md#row-304); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_documentGrantType_grant_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_documents_documentId_documentGrantType_grant_response` (schema placeholder).
- **Status codes:** `201, 400, 404, 409, 503, 512`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Documents\Features\DocumentGrant\Http\CreateDocumentGrantController`.
- **Controller source:** `apps/api/Modules/Documents/Features/DocumentGrant/Http/CreateDocumentGrantController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/documents/{documentId}/{documentGrantType}-grant.post`.
- **Route source:** `apps/api/routes/web.php:304`.

**Identity**

### `POST /api/v1/auth/login`

- **Summary (EN / AR):** Create or execute auth/login. `{{AR:post_api_v1_auth_login}}`
- **Operation key:** `post_api_v1_auth_login`
- **Middleware chain:** `web`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-auth-login:post:developmentfixturelogincontroller`](rbac-matrix.md#row-114); principal required: `no`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_auth_login_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_auth_login_response` (schema placeholder).
- **Status codes:** `400, 401`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\DevelopmentFixtureLogin\Http\DevelopmentFixtureLoginController`.
- **Controller source:** `apps/api/Modules/Identity/Features/DevelopmentFixtureLogin/Http/DevelopmentFixtureLoginController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/auth/login.post`.
- **Route source:** `apps/api/routes/web.php:114`.

### `POST /api/v1/identity/login`

- **Summary (EN / AR):** Create or execute identity/login. `{{AR:post_api_v1_identity_login}}`
- **Operation key:** `post_api_v1_identity_login`
- **Middleware chain:** `none`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-identity-login:post:identitylogincontroller`](rbac-matrix.md#row-117); principal required: `no`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_login_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_login_response` (schema placeholder).
- **Status codes:** `400, 401, 429, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\Authentication\Http\IdentityLoginController`.
- **Controller source:** `apps/api/Modules/Identity/Features/Authentication/Http/IdentityLoginController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/login.post`.
- **Route source:** `apps/api/routes/web.php:117`.

### `POST /api/v1/identity/activation`

- **Summary (EN / AR):** Create or execute identity/activation. `{{AR:post_api_v1_identity_activation}}`
- **Operation key:** `post_api_v1_identity_activation`
- **Middleware chain:** `throttle:6,1`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-identity-activation:post:consumeactivationcontroller`](rbac-matrix.md#row-118); principal required: `no`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_activation_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_activation_response` (schema placeholder).
- **Status codes:** `204, 400, 401, 422, 500`.
- **Throttle:** `6,1`.
- **Controller FQCN:** `Modules\Identity\Features\Activation\Http\ConsumeActivationController`.
- **Controller source:** `apps/api/Modules/Identity/Features/Activation/Http/ConsumeActivationController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/activation.post`.
- **Route source:** `apps/api/routes/web.php:118`.

### `GET /api/v1/identity/me`

- **Summary (EN / AR):** Retrieve identity/me. `{{AR:get_api_v1_identity_me}}`
- **Operation key:** `get_api_v1_identity_me`
- **Middleware chain:** `identity_session → require_identity_session_principal`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-identity-me:get:getcurrentidentitycontroller`](rbac-matrix.md#row-119); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_identity_me_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_identity_me_response` (schema placeholder).
- **Status codes:** `400, 401`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\Sessions\Http\GetCurrentIdentityController`.
- **Controller source:** `apps/api/Modules/Identity/Features/Sessions/Http/GetCurrentIdentityController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/me.get`.
- **Route source:** `apps/api/routes/web.php:119`.

### `POST /api/v1/identity/csrf`

- **Summary (EN / AR):** Create or execute identity/csrf. `{{AR:post_api_v1_identity_csrf}}`
- **Operation key:** `post_api_v1_identity_csrf`
- **Middleware chain:** `identity_session → require_identity_session_principal`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-identity-csrf:post:refreshidentitycsrfcontroller`](rbac-matrix.md#row-120); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_csrf_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_csrf_response` (schema placeholder).
- **Status codes:** `400, 401`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\Sessions\Http\RefreshIdentityCsrfController`.
- **Controller source:** `apps/api/Modules/Identity/Features/Sessions/Http/RefreshIdentityCsrfController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/csrf.post`.
- **Route source:** `apps/api/routes/web.php:120`.

### `GET /api/v1/me`

- **Summary (EN / AR):** Retrieve me. `{{AR:get_api_v1_me}}`
- **Operation key:** `get_api_v1_me`
- **Middleware chain:** `identity_session → require_identity_session_principal`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-me:get:getcurrentprincipalcontroller`](rbac-matrix.md#row-121); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_me_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_me_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\Sessions\Http\GetCurrentPrincipalController`.
- **Controller source:** `apps/api/Modules/Identity/Features/Sessions/Http/GetCurrentPrincipalController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/me.get`.
- **Route source:** `apps/api/routes/web.php:121`.

### `GET /api/v1/me/scopes`

- **Summary (EN / AR):** Retrieve me/scopes. `{{AR:get_api_v1_me_scopes}}`
- **Operation key:** `get_api_v1_me_scopes`
- **Middleware chain:** `identity_session → require_identity_session_principal`
- **CSRF required:** `no`
- **RBAC row:** [`api-v1-me-scopes:get:listmyscopescontroller`](rbac-matrix.md#row-122); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_me_scopes_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_me_scopes_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\Sessions\Http\ListMyScopesController`.
- **Controller source:** `apps/api/Modules/Identity/Features/Sessions/Http/ListMyScopesController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/me/scopes.get`.
- **Route source:** `apps/api/routes/web.php:122`.

### `PUT /api/v1/me/scope`

- **Summary (EN / AR):** Replace me/scope. `{{AR:put_api_v1_me_scope}}`
- **Operation key:** `put_api_v1_me_scope`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-me-scope:put:selectmyscopecontroller`](rbac-matrix.md#row-123); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/put_api_v1_me_scope_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/put_api_v1_me_scope_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\Sessions\Http\SelectMyScopeController`.
- **Controller source:** `apps/api/Modules/Identity/Features/Sessions/Http/SelectMyScopeController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/me/scope.put`.
- **Route source:** `apps/api/routes/web.php:123`.

### `POST /api/v1/identity/logout`

- **Summary (EN / AR):** Create or execute identity/logout. `{{AR:post_api_v1_identity_logout}}`
- **Operation key:** `post_api_v1_identity_logout`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-logout:post:identitylogoutcontroller`](rbac-matrix.md#row-129); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_logout_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_logout_response` (schema placeholder).
- **Status codes:** `400, 401, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\Authentication\Http\IdentityLogoutController`.
- **Controller source:** `apps/api/Modules/Identity/Features/Authentication/Http/IdentityLogoutController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/logout.post`.
- **Route source:** `apps/api/routes/web.php:129`.

### `POST /api/v1/identity/password`

- **Summary (EN / AR):** Create or execute identity/password. `{{AR:post_api_v1_identity_password}}`
- **Operation key:** `post_api_v1_identity_password`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-password:post:changepasswordcontroller`](rbac-matrix.md#row-130); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_password_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_password_response` (schema placeholder).
- **Status codes:** `204, 400, 401, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\Credentials\Http\ChangePasswordController`.
- **Controller source:** `apps/api/Modules/Identity/Features/Credentials/Http/ChangePasswordController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/password.post`.
- **Route source:** `apps/api/routes/web.php:130`.

### `POST /api/v1/identity/accounts/{accountId}/activation`

- **Summary (EN / AR):** Create or execute identity/accounts/{accountId}/activation. `{{AR:post_api_v1_identity_accounts_accountId_activation}}`
- **Operation key:** `post_api_v1_identity_accounts_accountId_activation`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-accounts-accountId-activation:post:issueactivationcontroller`](rbac-matrix.md#row-131); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_accounts_accountId_activation_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_accounts_accountId_activation_response` (schema placeholder).
- **Status codes:** `202, 400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\Activation\Http\IssueActivationController`.
- **Controller source:** `apps/api/Modules/Identity/Features/Activation/Http/IssueActivationController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/accounts/{accountId}/activation.post`.
- **Route source:** `apps/api/routes/web.php:131`.

### `GET /api/v1/identity/accounts`

- **Summary (EN / AR):** Retrieve identity/accounts. `{{AR:get_api_v1_identity_accounts}}`
- **Operation key:** `get_api_v1_identity_accounts`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-accounts:get:listuseraccountscontroller`](rbac-matrix.md#row-201); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_identity_accounts_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_identity_accounts_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\UserAccount\Http\ListUserAccountsController`.
- **Controller source:** `apps/api/Modules/Identity/Features/UserAccount/Http/ListUserAccountsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/accounts.get`.
- **Route source:** `apps/api/routes/web.php:201`.

### `POST /api/v1/identity/accounts`

- **Summary (EN / AR):** Create or execute identity/accounts. `{{AR:post_api_v1_identity_accounts}}`
- **Operation key:** `post_api_v1_identity_accounts`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-accounts:post:createuseraccountcontroller`](rbac-matrix.md#row-202); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_accounts_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_accounts_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\UserAccount\Http\CreateUserAccountController`.
- **Controller source:** `apps/api/Modules/Identity/Features/UserAccount/Http/CreateUserAccountController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/accounts.post`.
- **Route source:** `apps/api/routes/web.php:202`.

### `GET /api/v1/identity/accounts/{accountId}`

- **Summary (EN / AR):** Retrieve identity/accounts/{accountId}. `{{AR:get_api_v1_identity_accounts_accountId}}`
- **Operation key:** `get_api_v1_identity_accounts_accountId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-accounts-accountId:get:getuseraccountcontroller`](rbac-matrix.md#row-203); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_identity_accounts_accountId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_identity_accounts_accountId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\UserAccount\Http\GetUserAccountController`.
- **Controller source:** `apps/api/Modules/Identity/Features/UserAccount/Http/GetUserAccountController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/accounts/{accountId}.get`.
- **Route source:** `apps/api/routes/web.php:203`.

### `POST /api/v1/identity/accounts/{accountId}/{accountAction}`

- **Summary (EN / AR):** Create or execute identity/accounts/{accountId}/{accountAction}. `{{AR:post_api_v1_identity_accounts_accountId_accountAction}}`
- **Operation key:** `post_api_v1_identity_accounts_accountId_accountAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-identity-accounts-accountId-accountAction:post:transitionuseraccountcontroller`](rbac-matrix.md#row-204); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_identity_accounts_accountId_accountAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_identity_accounts_accountId_accountAction_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Identity\Features\UserAccount\Http\TransitionUserAccountController`.
- **Controller source:** `apps/api/Modules/Identity/Features/UserAccount/Http/TransitionUserAccountController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/identity/accounts/{accountId}/{accountAction}.post`.
- **Route source:** `apps/api/routes/web.php:204`.

**Internal**

### `POST /api/v1/internal/documents/versions/{versionId}/scan`

- **Summary (EN / AR):** Create or execute internal/documents/versions/{versionId}/scan. `{{AR:post_api_v1_internal_documents_versions_versionId_scan}}`
- **Operation key:** `post_api_v1_internal_documents_versions_versionId_scan`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf → throttle:60,1`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-internal-documents-versions-versionId-scan:post:scandocumentversioncontroller`](rbac-matrix.md#row-149); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_internal_documents_versions_versionId_scan_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_internal_documents_versions_versionId_scan_response` (schema placeholder).
- **Status codes:** `202, 400`.
- **Throttle:** `60,1`.
- **Controller FQCN:** `Modules\Documents\Features\DocumentVersion\Http\ScanDocumentVersionController`.
- **Controller source:** `apps/api/Modules/Documents/Features/DocumentVersion/Http/ScanDocumentVersionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/internal/documents/versions/{versionId}/scan.post`.
- **Route source:** `apps/api/routes/web.php:149`.

### `POST /api/v1/internal/documents/versions/{versionId}/reconcile-promotion`

- **Summary (EN / AR):** Create or execute internal/documents/versions/{versionId}/reconcile promotion. `{{AR:post_api_v1_internal_documents_versions_versionId_reconcile_promotion}}`
- **Operation key:** `post_api_v1_internal_documents_versions_versionId_reconcile_promotion`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf → throttle:60,1`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-internal-documents-versions-versionId-reconcile-promotion:post:reconciledocumentpromotioncontroller`](rbac-matrix.md#row-150); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_internal_documents_versions_versionId_reconcile_promotion_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_internal_documents_versions_versionId_reconcile_promotion_response` (schema placeholder).
- **Status codes:** `200, 400`.
- **Throttle:** `60,1`.
- **Controller FQCN:** `Modules\Documents\Features\DocumentVersion\Http\ReconcileDocumentPromotionController`.
- **Controller source:** `apps/api/Modules/Documents/Features/DocumentVersion/Http/ReconcileDocumentPromotionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/internal/documents/versions/{versionId}/reconcile-promotion.post`.
- **Route source:** `apps/api/routes/web.php:150`.

**Notifications**

### `GET /api/v1/notifications`

- **Summary (EN / AR):** Retrieve notifications. `{{AR:get_api_v1_notifications}}`
- **Operation key:** `get_api_v1_notifications`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-notifications:get:listmynotificationscontroller`](rbac-matrix.md#row-152); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_notifications_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_notifications_response` (schema placeholder).
- **Status codes:** `400, 401`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Notifications\Features\ListMyNotifications\Http\ListMyNotificationsController`.
- **Controller source:** `apps/api/Modules/Notifications/Features/ListMyNotifications/Http/ListMyNotificationsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/notifications.get`.
- **Route source:** `apps/api/routes/web.php:152`.

### `POST /api/v1/notifications/{notificationId}/read`

- **Summary (EN / AR):** Create or execute notifications/{notificationId}/read. `{{AR:post_api_v1_notifications_notificationId_read}}`
- **Operation key:** `post_api_v1_notifications_notificationId_read`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-notifications-notificationId-read:post:marknotificationreadcontroller`](rbac-matrix.md#row-153); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_notifications_notificationId_read_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_notifications_notificationId_read_response` (schema placeholder).
- **Status codes:** `400, 401, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Notifications\Features\ListMyNotifications\Http\MarkNotificationReadController`.
- **Controller source:** `apps/api/Modules/Notifications/Features/ListMyNotifications/Http/MarkNotificationReadController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/notifications/{notificationId}/read.post`.
- **Route source:** `apps/api/routes/web.php:153`.

**Organization**

### `GET /api/v1/organization/temporary-assignments`

- **Summary (EN / AR):** Retrieve organization/temporary assignments. `{{AR:get_api_v1_organization_temporary_assignments}}`
- **Operation key:** `get_api_v1_organization_temporary_assignments`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-temporary-assignments:get:listtemporaryassignmentscontroller`](rbac-matrix.md#row-136); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_temporary_assignments_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_temporary_assignments_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\TemporaryAssignment\Http\ListTemporaryAssignmentsController`.
- **Controller source:** `apps/api/Modules/Organization/Features/TemporaryAssignment/Http/ListTemporaryAssignmentsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/temporary-assignments.get`.
- **Route source:** `apps/api/routes/web.php:136`.

### `GET /api/v1/organization/temporary-assignments/{temporaryAssignmentId}`

- **Summary (EN / AR):** Retrieve organization/temporary assignments/{temporaryAssignmentId}. `{{AR:get_api_v1_organization_temporary_assignments_temporaryAssignmentId}}`
- **Operation key:** `get_api_v1_organization_temporary_assignments_temporaryAssignmentId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-temporary-assignments-temporaryAssignmentId:get:gettemporaryassignmentcontroller`](rbac-matrix.md#row-137); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_temporary_assignments_temporaryAssignmentId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_temporary_assignments_temporaryAssignmentId_response` (schema placeholder).
- **Status codes:** `400, 401, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\TemporaryAssignment\Http\GetTemporaryAssignmentController`.
- **Controller source:** `apps/api/Modules/Organization/Features/TemporaryAssignment/Http/GetTemporaryAssignmentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/temporary-assignments/{temporaryAssignmentId}.get`.
- **Route source:** `apps/api/routes/web.php:137`.

### `POST /api/v1/organization/temporary-assignments`

- **Summary (EN / AR):** Create or execute organization/temporary assignments. `{{AR:post_api_v1_organization_temporary_assignments}}`
- **Operation key:** `post_api_v1_organization_temporary_assignments`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-temporary-assignments:post:createtemporaryassignmentcontroller`](rbac-matrix.md#row-146); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_temporary_assignments_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_temporary_assignments_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 500, 503`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\TemporaryAssignment\Http\CreateTemporaryAssignmentController`.
- **Controller source:** `apps/api/Modules/Organization/Features/TemporaryAssignment/Http/CreateTemporaryAssignmentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/temporary-assignments.post`.
- **Route source:** `apps/api/routes/web.php:146`.

### `POST /api/v1/organization/temporary-assignments/{temporaryAssignmentId}/revoke`

- **Summary (EN / AR):** Create or execute organization/temporary assignments/{temporaryAssignmentId}/revoke. `{{AR:post_api_v1_organization_temporary_assignments_temporaryAssignmentId_revoke}}`
- **Operation key:** `post_api_v1_organization_temporary_assignments_temporaryAssignmentId_revoke`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-temporary-assignments-temporaryAssignmentId-revoke:post:revoketemporaryassignmentcontroller`](rbac-matrix.md#row-147); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_temporary_assignments_temporaryAssignmentId_revoke_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_temporary_assignments_temporaryAssignmentId_revoke_response` (schema placeholder).
- **Status codes:** `400, 401, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\TemporaryAssignment\Http\RevokeTemporaryAssignmentController`.
- **Controller source:** `apps/api/Modules/Organization/Features/TemporaryAssignment/Http/RevokeTemporaryAssignmentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/temporary-assignments/{temporaryAssignmentId}/revoke.post`.
- **Route source:** `apps/api/routes/web.php:147`.

### `GET /api/v1/organization/cluster`

- **Summary (EN / AR):** Retrieve organization/cluster. `{{AR:get_api_v1_organization_cluster}}`
- **Operation key:** `get_api_v1_organization_cluster`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-cluster:get:getclustercontroller`](rbac-matrix.md#row-169); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_cluster_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_cluster_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\CreateCluster\Http\GetClusterController`.
- **Controller source:** `apps/api/Modules/Organization/Features/CreateCluster/Http/GetClusterController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/cluster.get`.
- **Route source:** `apps/api/routes/web.php:169`.

### `POST /api/v1/organization/cluster`

- **Summary (EN / AR):** Create or execute organization/cluster. `{{AR:post_api_v1_organization_cluster}}`
- **Operation key:** `post_api_v1_organization_cluster`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-cluster:post:createclustercontroller`](rbac-matrix.md#row-170); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_cluster_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_cluster_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\CreateCluster\Http\CreateClusterController`.
- **Controller source:** `apps/api/Modules/Organization/Features/CreateCluster/Http/CreateClusterController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/cluster.post`.
- **Route source:** `apps/api/routes/web.php:170`.

### `PATCH /api/v1/organization/cluster`

- **Summary (EN / AR):** Update organization/cluster. `{{AR:patch_api_v1_organization_cluster}}`
- **Operation key:** `patch_api_v1_organization_cluster`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-cluster:patch:updateclustercontroller`](rbac-matrix.md#row-171); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_organization_cluster_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_organization_cluster_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\UpdateCluster\Http\UpdateClusterController`.
- **Controller source:** `apps/api/Modules/Organization/Features/UpdateCluster/Http/UpdateClusterController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/cluster.patch`.
- **Route source:** `apps/api/routes/web.php:171`.

### `GET /api/v1/organization/facilities`

- **Summary (EN / AR):** Retrieve organization/facilities. `{{AR:get_api_v1_organization_facilities}}`
- **Operation key:** `get_api_v1_organization_facilities`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-facilities:get:listfacilitiescontroller`](rbac-matrix.md#row-172); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_facilities_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_facilities_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\CreateFacility\Http\ListFacilitiesController`.
- **Controller source:** `apps/api/Modules/Organization/Features/CreateFacility/Http/ListFacilitiesController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/facilities.get`.
- **Route source:** `apps/api/routes/web.php:172`.

### `POST /api/v1/organization/facilities`

- **Summary (EN / AR):** Create or execute organization/facilities. `{{AR:post_api_v1_organization_facilities}}`
- **Operation key:** `post_api_v1_organization_facilities`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-facilities:post:createfacilitycontroller`](rbac-matrix.md#row-173); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_facilities_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_facilities_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\CreateFacility\Http\CreateFacilityController`.
- **Controller source:** `apps/api/Modules/Organization/Features/CreateFacility/Http/CreateFacilityController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/facilities.post`.
- **Route source:** `apps/api/routes/web.php:173`.

### `GET /api/v1/organization/facilities/{facilityId}`

- **Summary (EN / AR):** Retrieve organization/facilities/{facilityId}. `{{AR:get_api_v1_organization_facilities_facilityId}}`
- **Operation key:** `get_api_v1_organization_facilities_facilityId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-facilities-facilityId:get:getfacilitycontroller`](rbac-matrix.md#row-174); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_facilities_facilityId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_facilities_facilityId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\UpdateFacility\Http\GetFacilityController`.
- **Controller source:** `apps/api/Modules/Organization/Features/UpdateFacility/Http/GetFacilityController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/facilities/{facilityId}.get`.
- **Route source:** `apps/api/routes/web.php:174`.

### `PATCH /api/v1/organization/facilities/{facilityId}`

- **Summary (EN / AR):** Update organization/facilities/{facilityId}. `{{AR:patch_api_v1_organization_facilities_facilityId}}`
- **Operation key:** `patch_api_v1_organization_facilities_facilityId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-facilities-facilityId:patch:updatefacilitycontroller`](rbac-matrix.md#row-175); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_organization_facilities_facilityId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_organization_facilities_facilityId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\UpdateFacility\Http\UpdateFacilityController`.
- **Controller source:** `apps/api/Modules/Organization/Features/UpdateFacility/Http/UpdateFacilityController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/facilities/{facilityId}.patch`.
- **Route source:** `apps/api/routes/web.php:175`.

### `GET /api/v1/organization/units`

- **Summary (EN / AR):** Retrieve organization/units. `{{AR:get_api_v1_organization_units}}`
- **Operation key:** `get_api_v1_organization_units`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-units:get:listorganizationunitscontroller`](rbac-matrix.md#row-176); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_units_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_units_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\OrganizationUnit\Http\ListOrganizationUnitsController`.
- **Controller source:** `apps/api/Modules/Organization/Features/OrganizationUnit/Http/ListOrganizationUnitsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/units.get`.
- **Route source:** `apps/api/routes/web.php:176`.

### `POST /api/v1/organization/units`

- **Summary (EN / AR):** Create or execute organization/units. `{{AR:post_api_v1_organization_units}}`
- **Operation key:** `post_api_v1_organization_units`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-units:post:createorganizationunitcontroller`](rbac-matrix.md#row-177); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_units_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_units_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\OrganizationUnit\Http\CreateOrganizationUnitController`.
- **Controller source:** `apps/api/Modules/Organization/Features/OrganizationUnit/Http/CreateOrganizationUnitController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/units.post`.
- **Route source:** `apps/api/routes/web.php:177`.

### `POST /api/v1/organization/units/reorder`

- **Summary (EN / AR):** Create or execute organization/units/reorder. `{{AR:post_api_v1_organization_units_reorder}}`
- **Operation key:** `post_api_v1_organization_units_reorder`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-units-reorder:post:reorderorganizationunitscontroller`](rbac-matrix.md#row-178); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_units_reorder_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_units_reorder_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\OrganizationUnit\Http\ReorderOrganizationUnitsController`.
- **Controller source:** `apps/api/Modules/Organization/Features/OrganizationUnit/Http/ReorderOrganizationUnitsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/units/reorder.post`.
- **Route source:** `apps/api/routes/web.php:178`.

### `GET /api/v1/organization/units/{unitId}`

- **Summary (EN / AR):** Retrieve organization/units/{unitId}. `{{AR:get_api_v1_organization_units_unitId}}`
- **Operation key:** `get_api_v1_organization_units_unitId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-units-unitId:get:getorganizationunitcontroller`](rbac-matrix.md#row-179); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_units_unitId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_units_unitId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\OrganizationUnit\Http\GetOrganizationUnitController`.
- **Controller source:** `apps/api/Modules/Organization/Features/OrganizationUnit/Http/GetOrganizationUnitController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/units/{unitId}.get`.
- **Route source:** `apps/api/routes/web.php:179`.

### `PATCH /api/v1/organization/units/{unitId}`

- **Summary (EN / AR):** Update organization/units/{unitId}. `{{AR:patch_api_v1_organization_units_unitId}}`
- **Operation key:** `patch_api_v1_organization_units_unitId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-units-unitId:patch:updateorganizationunitcontroller`](rbac-matrix.md#row-180); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_organization_units_unitId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_organization_units_unitId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\OrganizationUnit\Http\UpdateOrganizationUnitController`.
- **Controller source:** `apps/api/Modules/Organization/Features/OrganizationUnit/Http/UpdateOrganizationUnitController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/units/{unitId}.patch`.
- **Route source:** `apps/api/routes/web.php:180`.

### `GET /api/v1/organization/job-titles`

- **Summary (EN / AR):** Retrieve organization/job titles. `{{AR:get_api_v1_organization_job_titles}}`
- **Operation key:** `get_api_v1_organization_job_titles`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-job-titles:get:listjobtitlescontroller`](rbac-matrix.md#row-181); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_job_titles_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_job_titles_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\JobTitle\Http\ListJobTitlesController`.
- **Controller source:** `apps/api/Modules/Organization/Features/JobTitle/Http/ListJobTitlesController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/job-titles.get`.
- **Route source:** `apps/api/routes/web.php:181`.

### `POST /api/v1/organization/job-titles`

- **Summary (EN / AR):** Create or execute organization/job titles. `{{AR:post_api_v1_organization_job_titles}}`
- **Operation key:** `post_api_v1_organization_job_titles`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-job-titles:post:createjobtitlecontroller`](rbac-matrix.md#row-182); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_job_titles_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_job_titles_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\JobTitle\Http\CreateJobTitleController`.
- **Controller source:** `apps/api/Modules/Organization/Features/JobTitle/Http/CreateJobTitleController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/job-titles.post`.
- **Route source:** `apps/api/routes/web.php:182`.

### `GET /api/v1/organization/positions`

- **Summary (EN / AR):** Retrieve organization/positions. `{{AR:get_api_v1_organization_positions}}`
- **Operation key:** `get_api_v1_organization_positions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-positions:get:listpositionscontroller`](rbac-matrix.md#row-183); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_positions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_positions_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Position\Http\ListPositionsController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Position/Http/ListPositionsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/positions.get`.
- **Route source:** `apps/api/routes/web.php:183`.

### `POST /api/v1/organization/positions`

- **Summary (EN / AR):** Create or execute organization/positions. `{{AR:post_api_v1_organization_positions}}`
- **Operation key:** `post_api_v1_organization_positions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-positions:post:createpositioncontroller`](rbac-matrix.md#row-184); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_positions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_positions_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Position\Http\CreatePositionController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Position/Http/CreatePositionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/positions.post`.
- **Route source:** `apps/api/routes/web.php:184`.

### `GET /api/v1/organization/positions/{positionId}`

- **Summary (EN / AR):** Retrieve organization/positions/{positionId}. `{{AR:get_api_v1_organization_positions_positionId}}`
- **Operation key:** `get_api_v1_organization_positions_positionId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-positions-positionId:get:getpositioncontroller`](rbac-matrix.md#row-185); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_positions_positionId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_positions_positionId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Position\Http\GetPositionController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Position/Http/GetPositionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/positions/{positionId}.get`.
- **Route source:** `apps/api/routes/web.php:185`.

### `PATCH /api/v1/organization/positions/{positionId}`

- **Summary (EN / AR):** Update organization/positions/{positionId}. `{{AR:patch_api_v1_organization_positions_positionId}}`
- **Operation key:** `patch_api_v1_organization_positions_positionId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-positions-positionId:patch:updatepositioncontroller`](rbac-matrix.md#row-186); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_organization_positions_positionId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_organization_positions_positionId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Position\Http\UpdatePositionController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Position/Http/UpdatePositionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/positions/{positionId}.patch`.
- **Route source:** `apps/api/routes/web.php:186`.

### `GET /api/v1/organization/people`

- **Summary (EN / AR):** Retrieve organization/people. `{{AR:get_api_v1_organization_people}}`
- **Operation key:** `get_api_v1_organization_people`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-people:get:listpeoplecontroller`](rbac-matrix.md#row-187); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_people_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_people_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Person\Http\ListPeopleController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Person/Http/ListPeopleController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/people.get`.
- **Route source:** `apps/api/routes/web.php:187`.

### `POST /api/v1/organization/people`

- **Summary (EN / AR):** Create or execute organization/people. `{{AR:post_api_v1_organization_people}}`
- **Operation key:** `post_api_v1_organization_people`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-people:post:createpersoncontroller`](rbac-matrix.md#row-188); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_people_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_people_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Person\Http\CreatePersonController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Person/Http/CreatePersonController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/people.post`.
- **Route source:** `apps/api/routes/web.php:188`.

### `GET /api/v1/organization/people/{personId}/reference`

- **Summary (EN / AR):** Retrieve organization/people/{personId}/reference. `{{AR:get_api_v1_organization_people_personId_reference}}`
- **Operation key:** `get_api_v1_organization_people_personId_reference`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-people-personId-reference:get:getpersonreferencecontroller`](rbac-matrix.md#row-189); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_people_personId_reference_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_people_personId_reference_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Person\Http\GetPersonReferenceController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Person/Http/GetPersonReferenceController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/people/{personId}/reference.get`.
- **Route source:** `apps/api/routes/web.php:189`.

### `GET /api/v1/organization/people/{personId}`

- **Summary (EN / AR):** Retrieve organization/people/{personId}. `{{AR:get_api_v1_organization_people_personId}}`
- **Operation key:** `get_api_v1_organization_people_personId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-people-personId:get:getpersoncontroller`](rbac-matrix.md#row-190); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_people_personId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_people_personId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Person\Http\GetPersonController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Person/Http/GetPersonController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/people/{personId}.get`.
- **Route source:** `apps/api/routes/web.php:190`.

### `PATCH /api/v1/organization/people/{personId}`

- **Summary (EN / AR):** Update organization/people/{personId}. `{{AR:patch_api_v1_organization_people_personId}}`
- **Operation key:** `patch_api_v1_organization_people_personId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-people-personId:patch:updatepersoncontroller`](rbac-matrix.md#row-191); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_organization_people_personId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_organization_people_personId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Person\Http\UpdatePersonController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Person/Http/UpdatePersonController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/people/{personId}.patch`.
- **Route source:** `apps/api/routes/web.php:191`.

### `GET /api/v1/organization/assignments`

- **Summary (EN / AR):** Retrieve organization/assignments. `{{AR:get_api_v1_organization_assignments}}`
- **Operation key:** `get_api_v1_organization_assignments`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-assignments:get:listassignmentscontroller`](rbac-matrix.md#row-192); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_assignments_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_assignments_response` (schema placeholder).
- **Status codes:** `400, 401, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Assignment\Http\ListAssignmentsController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Assignment/Http/ListAssignmentsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/assignments.get`.
- **Route source:** `apps/api/routes/web.php:192`.

### `POST /api/v1/organization/assignments`

- **Summary (EN / AR):** Create or execute organization/assignments. `{{AR:post_api_v1_organization_assignments}}`
- **Operation key:** `post_api_v1_organization_assignments`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-assignments:post:createassignmentcontroller`](rbac-matrix.md#row-193); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_assignments_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_assignments_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Assignment\Http\CreateAssignmentController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Assignment/Http/CreateAssignmentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/assignments.post`.
- **Route source:** `apps/api/routes/web.php:193`.

### `POST /api/v1/organization/assignments/{assignmentId}/end`

- **Summary (EN / AR):** Create or execute organization/assignments/{assignmentId}/end. `{{AR:post_api_v1_organization_assignments_assignmentId_end}}`
- **Operation key:** `post_api_v1_organization_assignments_assignmentId_end`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-assignments-assignmentId-end:post:endassignmentcontroller`](rbac-matrix.md#row-194); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_assignments_assignmentId_end_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_assignments_assignmentId_end_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Assignment\Http\EndAssignmentController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Assignment/Http/EndAssignmentController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/assignments/{assignmentId}/end.post`.
- **Route source:** `apps/api/routes/web.php:194`.

### `GET /api/v1/organization/supervisory-relationships`

- **Summary (EN / AR):** Retrieve organization/supervisory relationships. `{{AR:get_api_v1_organization_supervisory_relationships}}`
- **Operation key:** `get_api_v1_organization_supervisory_relationships`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-supervisory-relationships:get:supervisoryrelationshipcontroller`](rbac-matrix.md#row-195); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_supervisory_relationships_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_supervisory_relationships_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Assignment\Http\SupervisoryRelationshipController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Assignment/Http/SupervisoryRelationshipController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/supervisory-relationships.get`.
- **Route source:** `apps/api/routes/web.php:195`.

### `POST /api/v1/organization/supervisory-relationships`

- **Summary (EN / AR):** Create or execute organization/supervisory relationships. `{{AR:post_api_v1_organization_supervisory_relationships}}`
- **Operation key:** `post_api_v1_organization_supervisory_relationships`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-supervisory-relationships:post:supervisoryrelationshipcontroller`](rbac-matrix.md#row-196); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_supervisory_relationships_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_supervisory_relationships_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\Assignment\Http\SupervisoryRelationshipController`.
- **Controller source:** `apps/api/Modules/Organization/Features/Assignment/Http/SupervisoryRelationshipController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/supervisory-relationships.post`.
- **Route source:** `apps/api/routes/web.php:196`.

### `POST /api/v1/organization/import-jobs`

- **Summary (EN / AR):** Create or execute organization/import jobs. `{{AR:post_api_v1_organization_import_jobs}}`
- **Operation key:** `post_api_v1_organization_import_jobs`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-import-jobs:post:submitimportjobcontroller`](rbac-matrix.md#row-197); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_import_jobs_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_import_jobs_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 409, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\ImportJob\Http\SubmitImportJobController`.
- **Controller source:** `apps/api/Modules/Organization/Features/ImportJob/Http/SubmitImportJobController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/import-jobs.post`.
- **Route source:** `apps/api/routes/web.php:197`.

### `GET /api/v1/organization/import-jobs/{jobId}`

- **Summary (EN / AR):** Retrieve organization/import jobs/{jobId}. `{{AR:get_api_v1_organization_import_jobs_jobId}}`
- **Operation key:** `get_api_v1_organization_import_jobs_jobId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-import-jobs-jobId:get:getimportjobcontroller`](rbac-matrix.md#row-198); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_import_jobs_jobId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_import_jobs_jobId_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\ImportJob\Http\GetImportJobController`.
- **Controller source:** `apps/api/Modules/Organization/Features/ImportJob/Http/GetImportJobController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/import-jobs/{jobId}.get`.
- **Route source:** `apps/api/routes/web.php:198`.

### `GET /api/v1/organization/import-jobs/{jobId}/rows`

- **Summary (EN / AR):** Retrieve organization/import jobs/{jobId}/rows. `{{AR:get_api_v1_organization_import_jobs_jobId_rows}}`
- **Operation key:** `get_api_v1_organization_import_jobs_jobId_rows`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-import-jobs-jobId-rows:get:listimportjobrowscontroller`](rbac-matrix.md#row-199); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_organization_import_jobs_jobId_rows_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_organization_import_jobs_jobId_rows_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\ImportJob\Http\ListImportJobRowsController`.
- **Controller source:** `apps/api/Modules/Organization/Features/ImportJob/Http/ListImportJobRowsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/import-jobs/{jobId}/rows.get`.
- **Route source:** `apps/api/routes/web.php:199`.

### `POST /api/v1/organization/import-jobs/{jobId}/{jobAction}`

- **Summary (EN / AR):** Create or execute organization/import jobs/{jobId}/{jobAction}. `{{AR:post_api_v1_organization_import_jobs_jobId_jobAction}}`
- **Operation key:** `post_api_v1_organization_import_jobs_jobId_jobAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-organization-import-jobs-jobId-jobAction:post:transitionimportjobcontroller`](rbac-matrix.md#row-200); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_organization_import_jobs_jobId_jobAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_organization_import_jobs_jobId_jobAction_response` (schema placeholder).
- **Status codes:** `400, 401, 403, 404, 409, 412, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Organization\Features\ImportJob\Http\TransitionImportJobController`.
- **Controller source:** `apps/api/Modules/Organization/Features/ImportJob/Http/TransitionImportJobController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/organization/import-jobs/{jobId}/{jobAction}.post`.
- **Route source:** `apps/api/routes/web.php:200`.

**Platform Operations**

### `GET /api/v1/platform-operations/maintenance-windows`

- **Summary (EN / AR):** Retrieve platform operations/maintenance windows. `{{AR:get_api_v1_platform_operations_maintenance_windows}}`
- **Operation key:** `get_api_v1_platform_operations_maintenance_windows`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-operations-maintenance-windows-index:get:maintenancewindowscontroller::index`](rbac-matrix.md#row-208); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_platform_operations_maintenance_windows_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_platform_operations_maintenance_windows_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 404, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Maintenance\Http\MaintenanceWindowsController::index`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Maintenance/Http/MaintenanceWindowsController.php::index`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-operations/maintenance-windows.get`.
- **Route source:** `apps/api/routes/web.php:208`.

### `GET /api/v1/platform-operations/alert-policies`

- **Summary (EN / AR):** Retrieve platform operations/alert policies. `{{AR:get_api_v1_platform_operations_alert_policies}}`
- **Operation key:** `get_api_v1_platform_operations_alert_policies`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-operations-alert-policies-index:get:alertpoliciescontroller::index`](rbac-matrix.md#row-209); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_platform_operations_alert_policies_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_platform_operations_alert_policies_response` (schema placeholder).
- **Status codes:** `200, 404, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Alerts\Http\AlertPoliciesController::index`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Alerts/Http/AlertPoliciesController.php::index`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-operations/alert-policies.get`.
- **Route source:** `apps/api/routes/web.php:209`.

### `GET /api/v1/platform-operations/technical-logs`

- **Summary (EN / AR):** Retrieve platform operations/technical logs. `{{AR:get_api_v1_platform_operations_technical_logs}}`
- **Operation key:** `get_api_v1_platform_operations_technical_logs`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-operations-technical-logs-index:get:technicallogscontroller::index`](rbac-matrix.md#row-210); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_platform_operations_technical_logs_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_platform_operations_technical_logs_response` (schema placeholder).
- **Status codes:** `200, 202, 400, 503`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Logs\Http\TechnicalLogsController::index`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Logs/Http/TechnicalLogsController.php::index`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-operations/technical-logs.get`.
- **Route source:** `apps/api/routes/web.php:210`.

### `GET /api/v1/platform-operations/overview`

- **Summary (EN / AR):** Retrieve platform operations/overview. `{{AR:get_api_v1_platform_operations_overview}}`
- **Operation key:** `get_api_v1_platform_operations_overview`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-operations-overview:get:getplatformoverviewcontroller`](rbac-matrix.md#row-213); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_platform_operations_overview_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_platform_operations_overview_response` (schema placeholder).
- **Status codes:** `200`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Operations\Http\GetPlatformOverviewController`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Operations/Http/GetPlatformOverviewController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-operations/overview.get`.
- **Route source:** `apps/api/routes/web.php:213`.

### `GET /api/v1/platform-operations/health`

- **Summary (EN / AR):** Retrieve platform operations/health. `{{AR:get_api_v1_platform_operations_health}}`
- **Operation key:** `get_api_v1_platform_operations_health`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-operations-health-health:get:platformoperationscontroller::health`](rbac-matrix.md#row-214); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_platform_operations_health_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_platform_operations_health_response` (schema placeholder).
- **Status codes:** `200, 202, 400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Operations\Http\PlatformOperationsController::health`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Operations/Http/PlatformOperationsController.php::health`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-operations/health.get`.
- **Route source:** `apps/api/routes/web.php:214`.

### `GET /api/v1/platform-operations/backups`

- **Summary (EN / AR):** Retrieve platform operations/backups. `{{AR:get_api_v1_platform_operations_backups}}`
- **Operation key:** `get_api_v1_platform_operations_backups`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-operations-backups-backups:get:platformoperationscontroller::backups`](rbac-matrix.md#row-215); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_platform_operations_backups_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_platform_operations_backups_response` (schema placeholder).
- **Status codes:** `200, 202, 400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Operations\Http\PlatformOperationsController::backups`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Operations/Http/PlatformOperationsController.php::backups`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-operations/backups.get`.
- **Route source:** `apps/api/routes/web.php:215`.

### `POST /api/v1/platform-operations/backups`

- **Summary (EN / AR):** Create or execute platform operations/backups. `{{AR:post_api_v1_platform_operations_backups}}`
- **Operation key:** `post_api_v1_platform_operations_backups`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-operations-backups:post:dispatchbackupcontroller`](rbac-matrix.md#row-236); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_platform_operations_backups_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_platform_operations_backups_response` (schema placeholder).
- **Status codes:** `202, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Operations\Http\DispatchBackupController`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Operations/Http/DispatchBackupController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-operations/backups.post`.
- **Route source:** `apps/api/routes/web.php:236`.

### `POST /api/v1/platform-operations/restore-requests`

- **Summary (EN / AR):** Create or execute platform operations/restore requests. `{{AR:post_api_v1_platform_operations_restore_requests}}`
- **Operation key:** `post_api_v1_platform_operations_restore_requests`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-operations-restore-requests-requestrestore:post:platformoperationscontroller::requestrestore`](rbac-matrix.md#row-237); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_platform_operations_restore_requests_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_platform_operations_restore_requests_response` (schema placeholder).
- **Status codes:** `200, 202, 400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Operations\Http\PlatformOperationsController::requestRestore`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Operations/Http/PlatformOperationsController.php::requestRestore`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-operations/restore-requests.post`.
- **Route source:** `apps/api/routes/web.php:237`.

### `POST /api/v1/platform-operations/restore-requests/{requestId}/confirm`

- **Summary (EN / AR):** Create or execute platform operations/restore requests/{requestId}/confirm. `{{AR:post_api_v1_platform_operations_restore_requests_requestId_confirm}}`
- **Operation key:** `post_api_v1_platform_operations_restore_requests_requestId_confirm`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-operations-restore-requests-requestId-confirm-confirmrestore:post:platformoperationscontroller::confirmrestore`](rbac-matrix.md#row-238); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_platform_operations_restore_requests_requestId_confirm_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_platform_operations_restore_requests_requestId_confirm_response` (schema placeholder).
- **Status codes:** `200, 202, 400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Operations\Http\PlatformOperationsController::confirmRestore`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Operations/Http/PlatformOperationsController.php::confirmRestore`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-operations/restore-requests/{requestId}/confirm.post`.
- **Route source:** `apps/api/routes/web.php:238`.

### `POST /api/v1/platform-operations/maintenance-windows`

- **Summary (EN / AR):** Create or execute platform operations/maintenance windows. `{{AR:post_api_v1_platform_operations_maintenance_windows}}`
- **Operation key:** `post_api_v1_platform_operations_maintenance_windows`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-operations-maintenance-windows-store:post:maintenancewindowscontroller::store`](rbac-matrix.md#row-239); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_platform_operations_maintenance_windows_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_platform_operations_maintenance_windows_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 404, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Maintenance\Http\MaintenanceWindowsController::store`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Maintenance/Http/MaintenanceWindowsController.php::store`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-operations/maintenance-windows.post`.
- **Route source:** `apps/api/routes/web.php:239`.

### `POST /api/v1/platform-operations/maintenance-windows/{windowId}/cancel`

- **Summary (EN / AR):** Create or execute platform operations/maintenance windows/{windowId}/cancel. `{{AR:post_api_v1_platform_operations_maintenance_windows_windowId_cancel}}`
- **Operation key:** `post_api_v1_platform_operations_maintenance_windows_windowId_cancel`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-operations-maintenance-windows-windowId-cancel-cancel:post:maintenancewindowscontroller::cancel`](rbac-matrix.md#row-240); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_platform_operations_maintenance_windows_windowId_cancel_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_platform_operations_maintenance_windows_windowId_cancel_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 404, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Maintenance\Http\MaintenanceWindowsController::cancel`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Maintenance/Http/MaintenanceWindowsController.php::cancel`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-operations/maintenance-windows/{windowId}/cancel.post`.
- **Route source:** `apps/api/routes/web.php:240`.

### `PATCH /api/v1/platform-operations/alert-policies/{policyId}`

- **Summary (EN / AR):** Update platform operations/alert policies/{policyId}. `{{AR:patch_api_v1_platform_operations_alert_policies_policyId}}`
- **Operation key:** `patch_api_v1_platform_operations_alert_policies_policyId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-operations-alert-policies-policyId-update:patch:alertpoliciescontroller::update`](rbac-matrix.md#row-241); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_platform_operations_alert_policies_policyId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_platform_operations_alert_policies_policyId_response` (schema placeholder).
- **Status codes:** `200, 404, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Alerts\Http\AlertPoliciesController::update`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Alerts/Http/AlertPoliciesController.php::update`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-operations/alert-policies/{policyId}.patch`.
- **Route source:** `apps/api/routes/web.php:241`.

### `POST /api/v1/platform-operations/technical-logs/restore`

- **Summary (EN / AR):** Create or execute platform operations/technical logs/restore. `{{AR:post_api_v1_platform_operations_technical_logs_restore}}`
- **Operation key:** `post_api_v1_platform_operations_technical_logs_restore`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-operations-technical-logs-restore-restore:post:technicallogscontroller::restore`](rbac-matrix.md#row-242); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_platform_operations_technical_logs_restore_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_platform_operations_technical_logs_restore_response` (schema placeholder).
- **Status codes:** `200, 202, 400, 503`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Logs\Http\TechnicalLogsController::restore`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Logs/Http/TechnicalLogsController.php::restore`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-operations/technical-logs/restore.post`.
- **Route source:** `apps/api/routes/web.php:242`.

**Platform Settings**

### `GET /api/v1/platform-settings/current`

- **Summary (EN / AR):** Retrieve platform settings/current. `{{AR:get_api_v1_platform_settings_current}}`
- **Operation key:** `get_api_v1_platform_settings_current`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-settings-current:get:getcurrentplatformsettingscontroller`](rbac-matrix.md#row-207); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_platform_settings_current_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_platform_settings_current_response` (schema placeholder).
- **Status codes:** `200`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Settings\Http\GetCurrentPlatformSettingsController`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Settings/Http/GetCurrentPlatformSettingsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-settings/current.get`.
- **Route source:** `apps/api/routes/web.php:207`.

### `GET /api/v1/platform-settings/versions`

- **Summary (EN / AR):** Retrieve platform settings/versions. `{{AR:get_api_v1_platform_settings_versions}}`
- **Operation key:** `get_api_v1_platform_settings_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-settings-versions:get:listsettingsversionscontroller`](rbac-matrix.md#row-211); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_platform_settings_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_platform_settings_versions_response` (schema placeholder).
- **Status codes:** `200`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Settings\Http\ListSettingsVersionsController`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Settings/Http/ListSettingsVersionsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-settings/versions.get`.
- **Route source:** `apps/api/routes/web.php:211`.

### `GET /api/v1/platform-settings/calendars`

- **Summary (EN / AR):** Retrieve platform settings/calendars. `{{AR:get_api_v1_platform_settings_calendars}}`
- **Operation key:** `get_api_v1_platform_settings_calendars`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-settings-calendars-index:get:businesscalendarcontroller::index`](rbac-matrix.md#row-212); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_platform_settings_calendars_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_platform_settings_calendars_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 404, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Calendars\Http\BusinessCalendarController::index`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Calendars/Http/BusinessCalendarController.php::index`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-settings/calendars.get`.
- **Route source:** `apps/api/routes/web.php:212`.

### `POST /api/v1/platform-settings/versions`

- **Summary (EN / AR):** Create or execute platform settings/versions. `{{AR:post_api_v1_platform_settings_versions}}`
- **Operation key:** `post_api_v1_platform_settings_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-settings-versions:post:createsettingsversioncontroller`](rbac-matrix.md#row-228); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_platform_settings_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_platform_settings_versions_response` (schema placeholder).
- **Status codes:** `201, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Settings\Http\CreateSettingsVersionController`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Settings/Http/CreateSettingsVersionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-settings/versions.post`.
- **Route source:** `apps/api/routes/web.php:228`.

### `PUT /api/v1/platform-settings/versions/{versionId}/settings/{settingKey}`

- **Summary (EN / AR):** Replace platform settings/versions/{versionId}/settings/{settingKey}. `{{AR:put_api_v1_platform_settings_versions_versionId_settings_settingKey}}`
- **Operation key:** `put_api_v1_platform_settings_versions_versionId_settings_settingKey`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-settings-versions-versionId-settings-settingKey:put:updatesettingsvaluecontroller`](rbac-matrix.md#row-229); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/put_api_v1_platform_settings_versions_versionId_settings_settingKey_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/put_api_v1_platform_settings_versions_versionId_settings_settingKey_response` (schema placeholder).
- **Status codes:** `200, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Settings\Http\UpdateSettingsValueController`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Settings/Http/UpdateSettingsValueController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-settings/versions/{versionId}/settings/{settingKey}.put`.
- **Route source:** `apps/api/routes/web.php:229`.

### `POST /api/v1/platform-settings/versions/{versionId}/validate`

- **Summary (EN / AR):** Create or execute platform settings/versions/{versionId}/validate. `{{AR:post_api_v1_platform_settings_versions_versionId_validate}}`
- **Operation key:** `post_api_v1_platform_settings_versions_versionId_validate`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-settings-versions-versionId-validate:post:validatesettingsversioncontroller`](rbac-matrix.md#row-230); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_platform_settings_versions_versionId_validate_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_platform_settings_versions_versionId_validate_response` (schema placeholder).
- **Status codes:** `200, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Settings\Http\ValidateSettingsVersionController`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Settings/Http/ValidateSettingsVersionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-settings/versions/{versionId}/validate.post`.
- **Route source:** `apps/api/routes/web.php:230`.

### `POST /api/v1/platform-settings/versions/{versionId}/publish`

- **Summary (EN / AR):** Create or execute platform settings/versions/{versionId}/publish. `{{AR:post_api_v1_platform_settings_versions_versionId_publish}}`
- **Operation key:** `post_api_v1_platform_settings_versions_versionId_publish`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-settings-versions-versionId-publish:post:publishsettingsversioncontroller`](rbac-matrix.md#row-231); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_platform_settings_versions_versionId_publish_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_platform_settings_versions_versionId_publish_response` (schema placeholder).
- **Status codes:** `200, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Settings\Http\PublishSettingsVersionController`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Settings/Http/PublishSettingsVersionController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-settings/versions/{versionId}/publish.post`.
- **Route source:** `apps/api/routes/web.php:231`.

### `POST /api/v1/platform-settings/calendars`

- **Summary (EN / AR):** Create or execute platform settings/calendars. `{{AR:post_api_v1_platform_settings_calendars}}`
- **Operation key:** `post_api_v1_platform_settings_calendars`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-settings-calendars-store:post:businesscalendarcontroller::store`](rbac-matrix.md#row-232); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_platform_settings_calendars_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_platform_settings_calendars_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 404, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Calendars\Http\BusinessCalendarController::store`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Calendars/Http/BusinessCalendarController.php::store`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-settings/calendars.post`.
- **Route source:** `apps/api/routes/web.php:232`.

### `PUT /api/v1/platform-settings/calendars/{calendarId}/weekdays/{weekday}`

- **Summary (EN / AR):** Replace platform settings/calendars/{calendarId}/weekdays/{weekday}. `{{AR:put_api_v1_platform_settings_calendars_calendarId_weekdays_weekday}}`
- **Operation key:** `put_api_v1_platform_settings_calendars_calendarId_weekdays_weekday`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-settings-calendars-calendarId-weekdays-weekday-setweekday:put:businesscalendarcontroller::setweekday`](rbac-matrix.md#row-233); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/put_api_v1_platform_settings_calendars_calendarId_weekdays_weekday_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/put_api_v1_platform_settings_calendars_calendarId_weekdays_weekday_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 404, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Calendars\Http\BusinessCalendarController::setWeekday`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Calendars/Http/BusinessCalendarController.php::setWeekday`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-settings/calendars/{calendarId}/weekdays/{weekday}.put`.
- **Route source:** `apps/api/routes/web.php:233`.

### `PUT /api/v1/platform-settings/calendars/{calendarId}/exceptions/{date}`

- **Summary (EN / AR):** Replace platform settings/calendars/{calendarId}/exceptions/{date}. `{{AR:put_api_v1_platform_settings_calendars_calendarId_exceptions_date}}`
- **Operation key:** `put_api_v1_platform_settings_calendars_calendarId_exceptions_date`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-settings-calendars-calendarId-exceptions-date-setexception:put:businesscalendarcontroller::setexception`](rbac-matrix.md#row-234); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/put_api_v1_platform_settings_calendars_calendarId_exceptions_date_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/put_api_v1_platform_settings_calendars_calendarId_exceptions_date_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 404, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Calendars\Http\BusinessCalendarController::setException`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Calendars/Http/BusinessCalendarController.php::setException`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-settings/calendars/{calendarId}/exceptions/{date}.put`.
- **Route source:** `apps/api/routes/web.php:234`.

### `POST /api/v1/platform-settings/calendars/{calendarId}/publish`

- **Summary (EN / AR):** Create or execute platform settings/calendars/{calendarId}/publish. `{{AR:post_api_v1_platform_settings_calendars_calendarId_publish}}`
- **Operation key:** `post_api_v1_platform_settings_calendars_calendarId_publish`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-platform-settings-calendars-calendarId-publish-publish:post:businesscalendarcontroller::publish`](rbac-matrix.md#row-235); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_platform_settings_calendars_calendarId_publish_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_platform_settings_calendars_calendarId_publish_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 404, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\PlatformSettings\Features\Calendars\Http\BusinessCalendarController::publish`.
- **Controller source:** `apps/api/Modules/PlatformSettings/Features/Calendars/Http/BusinessCalendarController.php::publish`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/platform-settings/calendars/{calendarId}/publish.post`.
- **Route source:** `apps/api/routes/web.php:235`.

**Reporting**

### `GET /api/v1/reports/{reportId}`

- **Summary (EN / AR):** Retrieve reports/{reportId}. `{{AR:get_api_v1_reports_reportId}}`
- **Operation key:** `get_api_v1_reports_reportId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-reports-reportId:get:getreportcontroller`](rbac-matrix.md#row-157); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_reports_reportId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_reports_reportId_response` (schema placeholder).
- **Status codes:** `200, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Reporting\Http\GetReportController`.
- **Controller source:** `apps/api/Modules/Reporting/Http/GetReportController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/reports/{reportId}.get`.
- **Route source:** `apps/api/routes/web.php:157`.

### `GET /api/v1/exports/{exportId}`

- **Summary (EN / AR):** Retrieve exports/{exportId}. `{{AR:get_api_v1_exports_exportId}}`
- **Operation key:** `get_api_v1_exports_exportId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-exports-exportId:get:downloadexportcontroller`](rbac-matrix.md#row-158); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_exports_exportId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_exports_exportId_response` (schema placeholder).
- **Status codes:** `200, 400, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Reporting\Http\DownloadExportController`.
- **Controller source:** `apps/api/Modules/Reporting/Http/DownloadExportController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/exports/{exportId}.get`.
- **Route source:** `apps/api/routes/web.php:158`.

### `GET /api/v1/dashboards/{dashboardId}`

- **Summary (EN / AR):** Retrieve dashboards/{dashboardId}. `{{AR:get_api_v1_dashboards_dashboardId}}`
- **Operation key:** `get_api_v1_dashboards_dashboardId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-dashboards-dashboardId:get:getdashboardcontroller`](rbac-matrix.md#row-159); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_dashboards_dashboardId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_dashboards_dashboardId_response` (schema placeholder).
- **Status codes:** `200, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Reporting\Http\GetDashboardController`.
- **Controller source:** `apps/api/Modules/Reporting/Http/GetDashboardController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/dashboards/{dashboardId}.get`.
- **Route source:** `apps/api/routes/web.php:159`.

### `POST /api/v1/reports/{reportId}/exports`

- **Summary (EN / AR):** Create or execute reports/{reportId}/exports. `{{AR:post_api_v1_reports_reportId_exports}}`
- **Operation key:** `post_api_v1_reports_reportId_exports`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-reports-reportId-exports:post:createreportexportcontroller`](rbac-matrix.md#row-166); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_reports_reportId_exports_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_reports_reportId_exports_response` (schema placeholder).
- **Status codes:** `202, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Reporting\Http\CreateReportExportController`.
- **Controller source:** `apps/api/Modules/Reporting/Http/CreateReportExportController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/reports/{reportId}/exports.post`.
- **Route source:** `apps/api/routes/web.php:166`.

### `GET /api/v1/reports`

- **Summary (EN / AR):** Retrieve reports. `{{AR:get_api_v1_reports}}`
- **Operation key:** `get_api_v1_reports`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-reports:get:listreportscontroller`](rbac-matrix.md#row-275); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_reports_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_reports_response` (schema placeholder).
- **Status codes:** `200, 400, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Reporting\Features\ListReports\Http\ListReportsController`.
- **Controller source:** `apps/api/Modules/Reporting/Features/ListReports/Http/ListReportsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/reports.get`.
- **Route source:** `apps/api/routes/web.php:275`.

### `GET /api/v1/dashboards`

- **Summary (EN / AR):** Retrieve dashboards. `{{AR:get_api_v1_dashboards}}`
- **Operation key:** `get_api_v1_dashboards`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-dashboards:get:listdashboardscontroller`](rbac-matrix.md#row-276); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_dashboards_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_dashboards_response` (schema placeholder).
- **Status codes:** `200, 400, 403`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Reporting\Features\ListDashboards\Http\ListDashboardsController`.
- **Controller source:** `apps/api/Modules/Reporting/Features/ListDashboards/Http/ListDashboardsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/dashboards.get`.
- **Route source:** `apps/api/routes/web.php:276`.

**Search**

### `GET /api/v1/search`

- **Summary (EN / AR):** Retrieve search. `{{AR:get_api_v1_search}}`
- **Operation key:** `get_api_v1_search`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-search:get:searchcontroller`](rbac-matrix.md#row-154); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_search_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_search_response` (schema placeholder).
- **Status codes:** `200, 400`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Search\Http\SearchController`.
- **Controller source:** `apps/api/Modules/Search/Http/SearchController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/search.get`.
- **Route source:** `apps/api/routes/web.php:154`.

**Tasks**

### `GET /api/v1/tasks`

- **Summary (EN / AR):** Retrieve tasks. `{{AR:get_api_v1_tasks}}`
- **Operation key:** `get_api_v1_tasks`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-index:get:taskcontroller::index`](rbac-matrix.md#row-268); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_tasks_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_tasks_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Tasks\Features\Http\TaskController::index`.
- **Controller source:** `apps/api/Modules/Tasks/Features/Http/TaskController.php::index`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks.get`.
- **Route source:** `apps/api/routes/web.php:268`.

### `GET /api/v1/tasks/{taskId}/comments`

- **Summary (EN / AR):** Retrieve tasks/{taskId}/comments. `{{AR:get_api_v1_tasks_taskId_comments}}`
- **Operation key:** `get_api_v1_tasks_taskId_comments`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-taskId-comments-listcomments:get:taskengagementcontroller::listcomments`](rbac-matrix.md#row-269); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_tasks_taskId_comments_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_tasks_taskId_comments_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Tasks\Features\Http\TaskEngagementController::listComments`.
- **Controller source:** `apps/api/Modules/Tasks/Features/Http/TaskEngagementController.php::listComments`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/{taskId}/comments.get`.
- **Route source:** `apps/api/routes/web.php:269`.

### `GET /api/v1/tasks/{taskId}`

- **Summary (EN / AR):** Retrieve tasks/{taskId}. `{{AR:get_api_v1_tasks_taskId}}`
- **Operation key:** `get_api_v1_tasks_taskId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-taskId-show:get:taskcontroller::show`](rbac-matrix.md#row-270); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_tasks_taskId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_tasks_taskId_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Tasks\Features\Http\TaskController::show`.
- **Controller source:** `apps/api/Modules/Tasks/Features/Http/TaskController.php::show`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/{taskId}.get`.
- **Route source:** `apps/api/routes/web.php:270`.

### `POST /api/v1/tasks`

- **Summary (EN / AR):** Create or execute tasks. `{{AR:post_api_v1_tasks}}`
- **Operation key:** `post_api_v1_tasks`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-store:post:taskcontroller::store`](rbac-matrix.md#row-290); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_tasks_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_tasks_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Tasks\Features\Http\TaskController::store`.
- **Controller source:** `apps/api/Modules/Tasks/Features/Http/TaskController.php::store`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks.post`.
- **Route source:** `apps/api/routes/web.php:290`.

### `PATCH /api/v1/tasks/{taskId}`

- **Summary (EN / AR):** Update tasks/{taskId}. `{{AR:patch_api_v1_tasks_taskId}}`
- **Operation key:** `patch_api_v1_tasks_taskId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-taskId-update:patch:taskcontroller::update`](rbac-matrix.md#row-291); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/patch_api_v1_tasks_taskId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/patch_api_v1_tasks_taskId_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Tasks\Features\Http\TaskController::update`.
- **Controller source:** `apps/api/Modules/Tasks/Features/Http/TaskController.php::update`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/{taskId}.patch`.
- **Route source:** `apps/api/routes/web.php:291`.

### `POST /api/v1/tasks/from-step/{stepId}`

- **Summary (EN / AR):** Create or execute tasks/from step/{stepId}. `{{AR:post_api_v1_tasks_from_step_stepId}}`
- **Operation key:** `post_api_v1_tasks_from_step_stepId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-from-step-stepId-fromstep:post:taskcontroller::fromstep`](rbac-matrix.md#row-292); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_tasks_from_step_stepId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_tasks_from_step_stepId_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Tasks\Features\Http\TaskController::fromStep`.
- **Controller source:** `apps/api/Modules/Tasks/Features/Http/TaskController.php::fromStep`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/from-step/{stepId}.post`.
- **Route source:** `apps/api/routes/web.php:292`.

### `POST /api/v1/tasks/{taskId}/participants`

- **Summary (EN / AR):** Create or execute tasks/{taskId}/participants. `{{AR:post_api_v1_tasks_taskId_participants}}`
- **Operation key:** `post_api_v1_tasks_taskId_participants`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-taskId-participants-addparticipant:post:taskengagementcontroller::addparticipant`](rbac-matrix.md#row-293); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_tasks_taskId_participants_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_tasks_taskId_participants_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Tasks\Features\Http\TaskEngagementController::addParticipant`.
- **Controller source:** `apps/api/Modules/Tasks/Features/Http/TaskEngagementController.php::addParticipant`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/{taskId}/participants.post`.
- **Route source:** `apps/api/routes/web.php:293`.

### `POST /api/v1/tasks/{taskId}/comments`

- **Summary (EN / AR):** Create or execute tasks/{taskId}/comments. `{{AR:post_api_v1_tasks_taskId_comments}}`
- **Operation key:** `post_api_v1_tasks_taskId_comments`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-taskId-comments-addcomment:post:taskengagementcontroller::addcomment`](rbac-matrix.md#row-294); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_tasks_taskId_comments_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_tasks_taskId_comments_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Tasks\Features\Http\TaskEngagementController::addComment`.
- **Controller source:** `apps/api/Modules/Tasks/Features/Http/TaskEngagementController.php::addComment`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/{taskId}/comments.post`.
- **Route source:** `apps/api/routes/web.php:294`.

### `POST /api/v1/tasks/{taskId}/{workflowTaskAction}`

- **Summary (EN / AR):** Create or execute tasks/{taskId}/{workflowTaskAction}. `{{AR:post_api_v1_tasks_taskId_workflowTaskAction}}`
- **Operation key:** `post_api_v1_tasks_taskId_workflowTaskAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-tasks-taskId-workflowTaskAction-transition:post:taskcontroller::transition`](rbac-matrix.md#row-295); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_tasks_taskId_workflowTaskAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_tasks_taskId_workflowTaskAction_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Tasks\Features\Http\TaskController::transition`.
- **Controller source:** `apps/api/Modules/Tasks/Features/Http/TaskController.php::transition`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/tasks/{taskId}/{workflowTaskAction}.post`.
- **Route source:** `apps/api/routes/web.php:295`.

**Work Definition Versions**

### `GET /api/v1/work-definition-versions/{versionId}`

- **Summary (EN / AR):** Retrieve work definition versions/{versionId}. `{{AR:get_api_v1_work_definition_versions_versionId}}`
- **Operation key:** `get_api_v1_work_definition_versions_versionId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definition-versions-versionId-showversionroute:get:workdefinitioncontroller::showversionroute`](rbac-matrix.md#row-261); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_work_definition_versions_versionId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_work_definition_versions_versionId_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkDefinitions\Features\Definition\Http\WorkDefinitionController::showVersionRoute`.
- **Controller source:** `apps/api/Modules/WorkDefinitions/Features/Definition/Http/WorkDefinitionController.php::showVersionRoute`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definition-versions/{versionId}.get`.
- **Route source:** `apps/api/routes/web.php:261`.

### `POST /api/v1/work-definition-versions/{versionId}/{versionAction}`

- **Summary (EN / AR):** Create or execute work definition versions/{versionId}/{versionAction}. `{{AR:post_api_v1_work_definition_versions_versionId_versionAction}}`
- **Operation key:** `post_api_v1_work_definition_versions_versionId_versionAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definition-versions-versionId-versionAction-transition:post:workdefinitioncontroller::transition`](rbac-matrix.md#row-285); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_work_definition_versions_versionId_versionAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_work_definition_versions_versionId_versionAction_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkDefinitions\Features\Definition\Http\WorkDefinitionController::transition`.
- **Controller source:** `apps/api/Modules/WorkDefinitions/Features/Definition/Http/WorkDefinitionController.php::transition`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definition-versions/{versionId}/{versionAction}.post`.
- **Route source:** `apps/api/routes/web.php:285`.

**Work Definitions**

### `GET /api/v1/work-definitions`

- **Summary (EN / AR):** Retrieve work definitions. `{{AR:get_api_v1_work_definitions}}`
- **Operation key:** `get_api_v1_work_definitions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definitions-index:get:workdefinitioncontroller::index`](rbac-matrix.md#row-258); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_work_definitions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_work_definitions_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkDefinitions\Features\Definition\Http\WorkDefinitionController::index`.
- **Controller source:** `apps/api/Modules/WorkDefinitions/Features/Definition/Http/WorkDefinitionController.php::index`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definitions.get`.
- **Route source:** `apps/api/routes/web.php:258`.

### `GET /api/v1/work-definitions/{definitionId}`

- **Summary (EN / AR):** Retrieve work definitions/{definitionId}. `{{AR:get_api_v1_work_definitions_definitionId}}`
- **Operation key:** `get_api_v1_work_definitions_definitionId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definitions-definitionId-show:get:workdefinitioncontroller::show`](rbac-matrix.md#row-259); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_work_definitions_definitionId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_work_definitions_definitionId_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkDefinitions\Features\Definition\Http\WorkDefinitionController::show`.
- **Controller source:** `apps/api/Modules/WorkDefinitions/Features/Definition/Http/WorkDefinitionController.php::show`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definitions/{definitionId}.get`.
- **Route source:** `apps/api/routes/web.php:259`.

### `GET /api/v1/work-definitions/{definitionId}/versions`

- **Summary (EN / AR):** Retrieve work definitions/{definitionId}/versions. `{{AR:get_api_v1_work_definitions_definitionId_versions}}`
- **Operation key:** `get_api_v1_work_definitions_definitionId_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definitions-definitionId-versions-versions:get:workdefinitioncontroller::versions`](rbac-matrix.md#row-260); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_work_definitions_definitionId_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_work_definitions_definitionId_versions_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkDefinitions\Features\Definition\Http\WorkDefinitionController::versions`.
- **Controller source:** `apps/api/Modules/WorkDefinitions/Features/Definition/Http/WorkDefinitionController.php::versions`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definitions/{definitionId}/versions.get`.
- **Route source:** `apps/api/routes/web.php:260`.

### `POST /api/v1/work-definitions`

- **Summary (EN / AR):** Create or execute work definitions. `{{AR:post_api_v1_work_definitions}}`
- **Operation key:** `post_api_v1_work_definitions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definitions-store:post:workdefinitioncontroller::store`](rbac-matrix.md#row-283); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_work_definitions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_work_definitions_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkDefinitions\Features\Definition\Http\WorkDefinitionController::store`.
- **Controller source:** `apps/api/Modules/WorkDefinitions/Features/Definition/Http/WorkDefinitionController.php::store`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definitions.post`.
- **Route source:** `apps/api/routes/web.php:283`.

### `POST /api/v1/work-definitions/{definitionId}/versions`

- **Summary (EN / AR):** Create or execute work definitions/{definitionId}/versions. `{{AR:post_api_v1_work_definitions_definitionId_versions}}`
- **Operation key:** `post_api_v1_work_definitions_definitionId_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-definitions-definitionId-versions-versions:post:workdefinitioncontroller::versions`](rbac-matrix.md#row-284); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_work_definitions_definitionId_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_work_definitions_definitionId_versions_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkDefinitions\Features\Definition\Http\WorkDefinitionController::versions`.
- **Controller source:** `apps/api/Modules/WorkDefinitions/Features/Definition/Http/WorkDefinitionController.php::versions`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-definitions/{definitionId}/versions.post`.
- **Route source:** `apps/api/routes/web.php:284`.

**Work Records**

### `GET /api/v1/work-records`

- **Summary (EN / AR):** Retrieve work records. `{{AR:get_api_v1_work_records}}`
- **Operation key:** `get_api_v1_work_records`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-records:get:listauthorizedworkrecordscontroller`](rbac-matrix.md#row-216); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_work_records_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_work_records_response` (schema placeholder).
- **Status codes:** `400, 401`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkRecords\Features\ListAuthorizedWorkRecords\Http\ListAuthorizedWorkRecordsController`.
- **Controller source:** `apps/api/Modules/WorkRecords/Features/ListAuthorizedWorkRecords/Http/ListAuthorizedWorkRecordsController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-records.get`.
- **Route source:** `apps/api/routes/web.php:216`.

### `GET /api/v1/work-records/{recordId}`

- **Summary (EN / AR):** Retrieve work records/{recordId}. `{{AR:get_api_v1_work_records_recordId}}`
- **Operation key:** `get_api_v1_work_records_recordId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-records-recordId:get:getauthorizedworkrecordcontroller`](rbac-matrix.md#row-217); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_work_records_recordId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_work_records_recordId_response` (schema placeholder).
- **Status codes:** `400, 401, 404`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkRecords\Features\GetAuthorizedWorkRecord\Http\GetAuthorizedWorkRecordController`.
- **Controller source:** `apps/api/Modules/WorkRecords/Features/GetAuthorizedWorkRecord/Http/GetAuthorizedWorkRecordController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-records/{recordId}.get`.
- **Route source:** `apps/api/routes/web.php:217`.

### `POST /api/v1/work-records`

- **Summary (EN / AR):** Create or execute work records. `{{AR:post_api_v1_work_records}}`
- **Operation key:** `post_api_v1_work_records`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-records:post:submitworkrecordcontroller`](rbac-matrix.md#row-243); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_work_records_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_work_records_response` (schema placeholder).
- **Status codes:** `201, 400, 401, 403, 404, 409, 422, 500`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkRecords\Features\SubmitWorkRecord\Http\SubmitWorkRecordController`.
- **Controller source:** `apps/api/Modules/WorkRecords/Features/SubmitWorkRecord/Http/SubmitWorkRecordController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-records.post`.
- **Route source:** `apps/api/routes/web.php:243`.

### `POST /api/v1/work-records/{recordId}/{recordAction}`

- **Summary (EN / AR):** Create or execute work records/{recordId}/{recordAction}. `{{AR:post_api_v1_work_records_recordId_recordAction}}`
- **Operation key:** `post_api_v1_work_records_recordId_recordAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf → project_work_record_read_models`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-records-recordId-recordAction-transition:post:workrecordlifecyclecontroller::transition`](rbac-matrix.md#row-247); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_work_records_recordId_recordAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_work_records_recordId_recordAction_response` (schema placeholder).
- **Status codes:** `400, 401, 412`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkRecords\Features\Lifecycle\Http\WorkRecordLifecycleController::transition`.
- **Controller source:** `apps/api/Modules/WorkRecords/Features/Lifecycle/Http/WorkRecordLifecycleController.php::transition`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-records/{recordId}/{recordAction}.post`.
- **Route source:** `apps/api/routes/web.php:247`.

### `POST /api/v1/work-records/{recordId}/documents`

- **Summary (EN / AR):** Create or execute work records/{recordId}/documents. `{{AR:post_api_v1_work_records_recordId_documents}}`
- **Operation key:** `post_api_v1_work_records_recordId_documents`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-work-records-recordId-documents:post:workrecorddocumentlinkcontroller`](rbac-matrix.md#row-250); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_work_records_recordId_documents_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_work_records_recordId_documents_response` (schema placeholder).
- **Status codes:** `201, 401, 404, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\WorkRecords\Features\DocumentLink\Http\WorkRecordDocumentLinkController`.
- **Controller source:** `apps/api/Modules/WorkRecords/Features/DocumentLink/Http/WorkRecordDocumentLinkController.php`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/work-records/{recordId}/documents.post`.
- **Route source:** `apps/api/routes/web.php:250`.

**Workflow**

### `GET /api/v1/workflow/definitions`

- **Summary (EN / AR):** Retrieve workflow/definitions. `{{AR:get_api_v1_workflow_definitions}}`
- **Operation key:** `get_api_v1_workflow_definitions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-definitions-definitions:get:workflowcontroller::definitions`](rbac-matrix.md#row-262); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_workflow_definitions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_workflow_definitions_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController::definitions`.
- **Controller source:** `apps/api/Modules/Workflow/Features/WorkflowLifecycle/Http/WorkflowController.php::definitions`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/definitions.get`.
- **Route source:** `apps/api/routes/web.php:262`.

### `GET /api/v1/workflow/definitions/{definitionId}/versions`

- **Summary (EN / AR):** Retrieve workflow/definitions/{definitionId}/versions. `{{AR:get_api_v1_workflow_definitions_definitionId_versions}}`
- **Operation key:** `get_api_v1_workflow_definitions_definitionId_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-definitions-definitionId-versions-versions:get:workflowcontroller::versions`](rbac-matrix.md#row-263); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_workflow_definitions_definitionId_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_workflow_definitions_definitionId_versions_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController::versions`.
- **Controller source:** `apps/api/Modules/Workflow/Features/WorkflowLifecycle/Http/WorkflowController.php::versions`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/definitions/{definitionId}/versions.get`.
- **Route source:** `apps/api/routes/web.php:263`.

### `GET /api/v1/workflow/instances`

- **Summary (EN / AR):** Retrieve workflow/instances. `{{AR:get_api_v1_workflow_instances}}`
- **Operation key:** `get_api_v1_workflow_instances`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-instances-instances:get:workflowcontroller::instances`](rbac-matrix.md#row-264); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_workflow_instances_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_workflow_instances_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController::instances`.
- **Controller source:** `apps/api/Modules/Workflow/Features/WorkflowLifecycle/Http/WorkflowController.php::instances`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/instances.get`.
- **Route source:** `apps/api/routes/web.php:264`.

### `GET /api/v1/workflow/instances/{instanceId}`

- **Summary (EN / AR):** Retrieve workflow/instances/{instanceId}. `{{AR:get_api_v1_workflow_instances_instanceId}}`
- **Operation key:** `get_api_v1_workflow_instances_instanceId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-instances-instanceId-showinstance:get:workflowcontroller::showinstance`](rbac-matrix.md#row-265); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_workflow_instances_instanceId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_workflow_instances_instanceId_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController::showInstance`.
- **Controller source:** `apps/api/Modules/Workflow/Features/WorkflowLifecycle/Http/WorkflowController.php::showInstance`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/instances/{instanceId}.get`.
- **Route source:** `apps/api/routes/web.php:265`.

### `GET /api/v1/workflow/steps`

- **Summary (EN / AR):** Retrieve workflow/steps. `{{AR:get_api_v1_workflow_steps}}`
- **Operation key:** `get_api_v1_workflow_steps`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-steps-listinbox:get:workflowcontroller::listinbox`](rbac-matrix.md#row-266); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_workflow_steps_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_workflow_steps_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController::listInbox`.
- **Controller source:** `apps/api/Modules/Workflow/Features/WorkflowLifecycle/Http/WorkflowController.php::listInbox`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/steps.get`.
- **Route source:** `apps/api/routes/web.php:266`.

### `GET /api/v1/workflow/steps/{stepId}`

- **Summary (EN / AR):** Retrieve workflow/steps/{stepId}. `{{AR:get_api_v1_workflow_steps_stepId}}`
- **Operation key:** `get_api_v1_workflow_steps_stepId`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-steps-stepId-showstep:get:workflowcontroller::showstep`](rbac-matrix.md#row-267); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/get_api_v1_workflow_steps_stepId_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/get_api_v1_workflow_steps_stepId_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController::showStep`.
- **Controller source:** `apps/api/Modules/Workflow/Features/WorkflowLifecycle/Http/WorkflowController.php::showStep`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/steps/{stepId}.get`.
- **Route source:** `apps/api/routes/web.php:267`.

### `POST /api/v1/workflow/definitions`

- **Summary (EN / AR):** Create or execute workflow/definitions. `{{AR:post_api_v1_workflow_definitions}}`
- **Operation key:** `post_api_v1_workflow_definitions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-definitions-definitions:post:workflowcontroller::definitions`](rbac-matrix.md#row-286); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_definitions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_definitions_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController::definitions`.
- **Controller source:** `apps/api/Modules/Workflow/Features/WorkflowLifecycle/Http/WorkflowController.php::definitions`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/definitions.post`.
- **Route source:** `apps/api/routes/web.php:286`.

### `POST /api/v1/workflow/definitions/{definitionId}/versions`

- **Summary (EN / AR):** Create or execute workflow/definitions/{definitionId}/versions. `{{AR:post_api_v1_workflow_definitions_definitionId_versions}}`
- **Operation key:** `post_api_v1_workflow_definitions_definitionId_versions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-definitions-definitionId-versions-versions:post:workflowcontroller::versions`](rbac-matrix.md#row-287); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_definitions_definitionId_versions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_definitions_definitionId_versions_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController::versions`.
- **Controller source:** `apps/api/Modules/Workflow/Features/WorkflowLifecycle/Http/WorkflowController.php::versions`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/definitions/{definitionId}/versions.post`.
- **Route source:** `apps/api/routes/web.php:287`.

### `POST /api/v1/workflow/versions/{versionId}/{workflowLifecycleAction}`

- **Summary (EN / AR):** Create or execute workflow/versions/{versionId}/{workflowLifecycleAction}. `{{AR:post_api_v1_workflow_versions_versionId_workflowLifecycleAction}}`
- **Operation key:** `post_api_v1_workflow_versions_versionId_workflowLifecycleAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-versions-versionId-workflowLifecycleAction-publish:post:workflowcontroller::publish`](rbac-matrix.md#row-288); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_versions_versionId_workflowLifecycleAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_versions_versionId_workflowLifecycleAction_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController::publish`.
- **Controller source:** `apps/api/Modules/Workflow/Features/WorkflowLifecycle/Http/WorkflowController.php::publish`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/versions/{versionId}/{workflowLifecycleAction}.post`.
- **Route source:** `apps/api/routes/web.php:288`.

### `POST /api/v1/workflow/instances`

- **Summary (EN / AR):** Create or execute workflow/instances. `{{AR:post_api_v1_workflow_instances}}`
- **Operation key:** `post_api_v1_workflow_instances`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-instances-instances:post:workflowcontroller::instances`](rbac-matrix.md#row-289); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_instances_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_instances_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController::instances`.
- **Controller source:** `apps/api/Modules/Workflow/Features/WorkflowLifecycle/Http/WorkflowController.php::instances`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/instances.post`.
- **Route source:** `apps/api/routes/web.php:289`.

### `POST /api/v1/workflow/steps/{stepId}/decisions`

- **Summary (EN / AR):** Create or execute workflow/steps/{stepId}/decisions. `{{AR:post_api_v1_workflow_steps_stepId_decisions}}`
- **Operation key:** `post_api_v1_workflow_steps_stepId_decisions`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-steps-stepId-decisions-decidestep:post:workflowcontroller::decidestep`](rbac-matrix.md#row-296); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_steps_stepId_decisions_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_steps_stepId_decisions_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController::decideStep`.
- **Controller source:** `apps/api/Modules/Workflow/Features/WorkflowLifecycle/Http/WorkflowController.php::decideStep`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/steps/{stepId}/decisions.post`.
- **Route source:** `apps/api/routes/web.php:296`.

### `POST /api/v1/workflow/steps/{stepId}/{stepAction}`

- **Summary (EN / AR):** Create or execute workflow/steps/{stepId}/{stepAction}. `{{AR:post_api_v1_workflow_steps_stepId_stepAction}}`
- **Operation key:** `post_api_v1_workflow_steps_stepId_stepAction`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-steps-stepId-stepAction-actonstep:post:workflowcontroller::actonstep`](rbac-matrix.md#row-297); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_steps_stepId_stepAction_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_steps_stepId_stepAction_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController::actOnStep`.
- **Controller source:** `apps/api/Modules/Workflow/Features/WorkflowLifecycle/Http/WorkflowController.php::actOnStep`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/steps/{stepId}/{stepAction}.post`.
- **Route source:** `apps/api/routes/web.php:297`.

### `POST /api/v1/workflow/instances/{instanceId}/cancel`

- **Summary (EN / AR):** Create or execute workflow/instances/{instanceId}/cancel. `{{AR:post_api_v1_workflow_instances_instanceId_cancel}}`
- **Operation key:** `post_api_v1_workflow_instances_instanceId_cancel`
- **Middleware chain:** `identity_session → require_identity_session_principal → identity_csrf`
- **CSRF required:** `yes`
- **RBAC row:** [`api-v1-workflow-instances-instanceId-cancel-cancelinstance:post:workflowcontroller::cancelinstance`](rbac-matrix.md#row-298); principal required: `yes`.
- **Request `$ref`:** `#/components/schemas/post_api_v1_workflow_instances_instanceId_cancel_request` (schema placeholder).
- **Response `$ref`:** `#/components/schemas/post_api_v1_workflow_instances_instanceId_cancel_response` (schema placeholder).
- **Status codes:** `200, 201, 400, 401, 403, 404, 409, 412, 422`.
- **Throttle:** `default / none declared`.
- **Controller FQCN:** `Modules\Workflow\Features\WorkflowLifecycle\Http\WorkflowController::cancelInstance`.
- **Controller source:** `apps/api/Modules/Workflow/Features/WorkflowLifecycle/Http/WorkflowController.php::cancelInstance`.
- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths./api/v1/workflow/instances/{instanceId}/cancel.post`.
- **Route source:** `apps/api/routes/web.php:298`.

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
| GET | `/api/v1/platform-settings/current` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/platform-operations/maintenance-windows` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/platform-operations/alert-policies` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/platform-operations/technical-logs` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/platform-settings/versions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/platform-settings/calendars` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/platform-operations/overview` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/platform-operations/health` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/platform-operations/backups` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/work-records` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/work-records/{recordId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/authorization/access-decisions/{decisionId}/explanation` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/authorization/bootstrap` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/authorization/{adminResource}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| GET | `/api/v1/authorization/{adminResource}/{resourceId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/platform-settings/versions` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| PUT | `/api/v1/platform-settings/versions/{versionId}/settings/{settingKey}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/platform-settings/versions/{versionId}/validate` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/platform-settings/versions/{versionId}/publish` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/platform-settings/calendars` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| PUT | `/api/v1/platform-settings/calendars/{calendarId}/weekdays/{weekday}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| PUT | `/api/v1/platform-settings/calendars/{calendarId}/exceptions/{date}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/platform-settings/calendars/{calendarId}/publish` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/platform-operations/backups` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/platform-operations/restore-requests` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/platform-operations/restore-requests/{requestId}/confirm` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/platform-operations/maintenance-windows` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/platform-operations/maintenance-windows/{windowId}/cancel` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| PATCH | `/api/v1/platform-operations/alert-policies/{policyId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
| POST | `/api/v1/platform-operations/technical-logs/restore` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
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
| PATCH | `/api/v1/tasks/{taskId}` | `identity_session → require_identity_session_principal → identity_csrf` | yes | yes | yes | `none` |
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

- Live route declarations represented by cards: 143 / 143.
- Bootstrap-only health route represented in the dedicated operational section: `/up`.
- Arabic summary placeholders intentionally remain for S6.

### Contract Diff

Placeholder for S4. The contract-sync slice will add `git diff --stat` output and per-path drift bullets.
