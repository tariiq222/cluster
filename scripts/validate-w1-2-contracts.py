#!/usr/bin/env python3

import json
import sys
from pathlib import Path

try:
    import yaml
except ImportError:
    print("ERROR: PyYAML is required to validate W1.2 contracts.", file=sys.stderr)
    raise SystemExit(2)


ROOT = Path(__file__).resolve().parent.parent
SNAPSHOT = ROOT / "docs/contracts/api/w1-2.openapi.yaml"
SOURCE = ROOT / "docs/contracts/api/openapi.yaml"
EXPECTED_METHODS = {
    "/auth/login": {"post"},
    "/auth/logout": {"post"},
    "/identity/login": {"post"},
    "/identity/activation": {"post"},
    "/identity/me": {"get"},
    "/identity/logout": {"post"},
    "/identity/password": {"post"},
    "/identity/accounts/{accountId}/activation": {"post"},
    "/me": {"get"},
    "/me/scopes": {"get"},
    "/me/scope": {"put"},
    "/documents": {"get", "post"},
    "/documents/uploads": {"post"},
    "/documents/uploads/{uploadId}": {"get"},
    "/documents/uploads/{uploadId}/complete": {"post"},
    "/internal/documents/versions/{versionId}/scan": {"post"},
    "/internal/documents/versions/{versionId}/reconcile-promotion": {"post"},
    "/organization/temporary-assignments": {"get", "post"},
    "/organization/temporary-assignments/{temporaryAssignmentId}": {"get"},
    "/organization/temporary-assignments/{temporaryAssignmentId}/revoke": {"post"},
    "/organization/cluster": {"get", "post", "patch"},
    "/organization/facilities": {"get", "post"},
    "/organization/facilities/{facilityId}": {"get", "patch"},
    "/organization/units": {"get", "post"},
    "/organization/units/{unitId}": {"get", "patch"},
    "/organization/positions": {"get", "post"},
    "/organization/positions/{positionId}": {"get", "patch"},
    "/organization/job-titles": {"get", "post"},
    "/organization/people": {"get", "post"},
    "/organization/people/{personId}": {"get", "patch"},
    "/organization/people/{personId}/reference": {"get"},
    "/organization/assignments": {"get", "post"},
    "/organization/assignments/{assignmentId}/end": {"post"},
    "/organization/import-jobs": {"post"},
    "/organization/import-jobs/{jobId}": {"get"},
    "/organization/import-jobs/{jobId}/rows": {"get"},
    "/organization/import-jobs/{jobId}/{jobAction}": {"post"},
    "/identity/accounts": {"get", "post"},
    "/identity/accounts/{accountId}": {"get"},
    "/identity/accounts/{accountId}/{accountAction}": {"post"},
    "/authorization/bootstrap": {"get", "post"},
}
EXPECTED_ACCOUNT_STATES = ["pending", "active", "locked", "disabled", "archived"]
ORGANIZATION_RUNTIME_STATUS = {
    ("/organization/cluster", "get"): "implemented",
    ("/organization/cluster", "post"): "implemented",
    ("/organization/cluster", "patch"): "implemented",
    ("/organization/facilities", "get"): "implemented",
    ("/organization/facilities", "post"): "implemented",
    ("/organization/facilities/{facilityId}", "get"): "implemented",
    ("/organization/facilities/{facilityId}", "patch"): "implemented",
    ("/organization/units", "get"): "implemented",
    ("/organization/units", "post"): "implemented",
    ("/organization/units/{unitId}", "get"): "implemented",
    ("/organization/units/{unitId}", "patch"): "implemented",
    ("/organization/positions", "get"): "implemented",
    ("/organization/positions", "post"): "implemented",
    ("/organization/positions/{positionId}", "get"): "implemented",
    ("/organization/positions/{positionId}", "patch"): "implemented",
    ("/organization/job-titles", "get"): "implemented",
    ("/organization/job-titles", "post"): "implemented",
    ("/organization/people", "get"): "implemented",
    ("/organization/people", "post"): "implemented",
    ("/organization/people/{personId}", "get"): "implemented",
    ("/organization/people/{personId}", "patch"): "implemented",
    ("/organization/people/{personId}/reference", "get"): "implemented",
    ("/organization/assignments", "get"): "implemented",
    ("/organization/assignments", "post"): "implemented",
    ("/organization/assignments/{assignmentId}/end", "post"): "implemented",
    ("/organization/import-jobs", "post"): "implemented",
    ("/organization/import-jobs/{jobId}", "get"): "implemented",
    ("/organization/import-jobs/{jobId}/rows", "get"): "implemented",
    ("/organization/import-jobs/{jobId}/{jobAction}", "post"): "implemented",
    ("/identity/accounts", "get"): "implemented",
    ("/identity/accounts", "post"): "implemented",
    ("/identity/accounts/{accountId}", "get"): "implemented",
    ("/identity/accounts/{accountId}/{accountAction}", "post"): "implemented",
}
ORGANIZATION_RESPONSES = {
    ("/organization/cluster", "get"): {"200", "401", "403", "404"},
    ("/organization/cluster", "post"): {"201", "400", "401", "403", "409"},
    ("/organization/cluster", "patch"): {"200", "400", "401", "403", "404", "412", "500"},
    ("/organization/facilities", "get"): {"200", "401", "403"},
    ("/organization/facilities", "post"): {"201", "400", "401", "403", "409"},
    ("/organization/facilities/{facilityId}", "get"): {"200", "400", "401", "403", "404"},
    ("/organization/facilities/{facilityId}", "patch"): {"200", "400", "401", "403", "404", "409", "412", "500"},
    ("/organization/units", "get"): {"200", "400", "401", "403"},
    ("/organization/units", "post"): {"201", "400", "401", "403", "409", "500"},
    ("/organization/units/{unitId}", "get"): {"200", "400", "401", "403", "404"},
    ("/organization/units/{unitId}", "patch"): {"200", "400", "401", "403", "404", "409", "412", "500"},
    ("/organization/positions", "get"): {"200", "400", "401", "403"},
    ("/organization/positions", "post"): {"201", "400", "401", "403", "409", "500"},
    ("/organization/positions/{positionId}", "get"): {"200", "400", "401", "403", "404"},
    ("/organization/positions/{positionId}", "patch"): {"200", "400", "401", "403", "404", "409", "412", "500"},
    ("/organization/people", "get"): {"200", "400", "401", "403"},
    ("/organization/people", "post"): {"201", "400", "401", "403", "409", "500"},
    ("/organization/people/{personId}", "get"): {"200", "400", "401", "403", "404"},
    ("/organization/people/{personId}", "patch"): {"200", "400", "401", "403", "404", "409", "412", "500"},
    ("/organization/people/{personId}/reference", "get"): {"200", "400", "401", "403", "404"},
    ("/organization/assignments", "get"): {"200", "400", "401", "403"},
    ("/organization/assignments", "post"): {"201", "400", "401", "403", "404", "409", "500"},
    ("/organization/assignments/{assignmentId}/end", "post"): {"200", "400", "401", "403", "404", "409", "412", "500"},
    ("/organization/import-jobs", "post"): {"202", "400", "401", "403", "409", "500"},
    ("/organization/import-jobs/{jobId}", "get"): {"200", "400", "401", "403", "404"},
    ("/organization/import-jobs/{jobId}/rows", "get"): {"200", "400", "401", "403", "404"},
    ("/organization/import-jobs/{jobId}/{jobAction}", "post"): {"200", "400", "401", "403", "404", "409", "412", "500"},
    ("/identity/accounts", "get"): {"200", "400", "401", "403"},
    ("/identity/accounts", "post"): {"201", "400", "401", "403", "409", "500"},
    ("/identity/accounts/{accountId}", "get"): {"200", "400", "401", "403", "404"},
    ("/identity/accounts/{accountId}/{accountAction}", "post"): {"200", "400", "401", "403", "404", "409", "412", "500"},
}
ORGANIZATION_SUCCESS_RESPONSES = {
    ("/organization/cluster", "get", "200"): "#/components/responses/ClusterEntity",
    ("/organization/cluster", "post", "201"): "#/components/responses/ClusterEntity",
    ("/organization/cluster", "patch", "200"): "#/components/responses/ClusterEntity",
    ("/organization/facilities", "get", "200"): "#/components/responses/FacilityCollection",
    ("/organization/facilities", "post", "201"): "#/components/responses/FacilityEntity",
    ("/organization/facilities/{facilityId}", "get", "200"): "#/components/responses/FacilityEntity",
    ("/organization/facilities/{facilityId}", "patch", "200"): "#/components/responses/FacilityEntity",
    ("/organization/units", "get", "200"): "#/components/responses/OrganizationUnitCollection",
    ("/organization/units", "post", "201"): "#/components/responses/OrganizationUnitEntity",
    ("/organization/units/{unitId}", "get", "200"): "#/components/responses/OrganizationUnitEntity",
    ("/organization/units/{unitId}", "patch", "200"): "#/components/responses/OrganizationUnitEntity",
    ("/organization/positions", "get", "200"): "#/components/responses/PositionCollection",
    ("/organization/positions", "post", "201"): "#/components/responses/PositionEntity",
    ("/organization/positions/{positionId}", "get", "200"): "#/components/responses/PositionEntity",
    ("/organization/positions/{positionId}", "patch", "200"): "#/components/responses/PositionEntity",
    ("/organization/job-titles", "get", "200"): "#/components/responses/JobTitleCollection",
    ("/organization/job-titles", "post", "201"): "#/components/responses/JobTitleEntity",
    ("/organization/people", "get", "200"): "#/components/responses/PersonCollection",
    ("/organization/people", "post", "201"): "#/components/responses/PersonEntity",
    ("/organization/people/{personId}", "get", "200"): "#/components/responses/PersonEntity",
    ("/organization/people/{personId}", "patch", "200"): "#/components/responses/PersonEntity",
    ("/organization/assignments", "get", "200"): "#/components/responses/AssignmentCollection",
    ("/organization/assignments", "post", "201"): "#/components/responses/AssignmentEntity",
    ("/organization/assignments/{assignmentId}/end", "post", "200"): "#/components/responses/AssignmentEntity",
    ("/organization/import-jobs", "post", "202"): "#/components/responses/ImportJobEntity",
    ("/organization/import-jobs/{jobId}", "get", "200"): "#/components/responses/ImportJobEntity",
    ("/organization/import-jobs/{jobId}/rows", "get", "200"): "#/components/responses/ImportJobRowCollection",
    ("/organization/import-jobs/{jobId}/{jobAction}", "post", "200"): "#/components/responses/ImportJobEntity",
    ("/identity/accounts", "get", "200"): "#/components/responses/UserAccountCollection",
    ("/identity/accounts", "post", "201"): "#/components/responses/UserAccountEntity",
    ("/identity/accounts/{accountId}", "get", "200"): "#/components/responses/UserAccountEntity",
    ("/identity/accounts/{accountId}/{accountAction}", "post", "200"): "#/components/responses/UserAccountEntity",
}


def fail(message: str) -> None:
    print(f"ERROR: {message}", file=sys.stderr)
    raise SystemExit(1)


def load_yaml(path: Path) -> dict:
    document = yaml.safe_load(path.read_text(encoding="utf-8"))
    if not isinstance(document, dict):
        fail(f"{path.relative_to(ROOT)} must contain a YAML object")
    return document




def methods(path_item: dict) -> set[str]:
    return set(path_item) & {"get", "post", "put", "patch", "delete"}


snapshot = load_yaml(SNAPSHOT)
source = load_yaml(SOURCE)

snapshot_paths = snapshot.get("paths")
source_paths = source.get("paths")
if not isinstance(snapshot_paths, dict) or set(snapshot_paths) != set(EXPECTED_METHODS):
    fail("W1.2 snapshot paths must exactly match the frozen readiness surface")
if not isinstance(source_paths, dict):
    fail("governed OpenAPI paths must be an object")

for path, expected in EXPECTED_METHODS.items():
    source_item = source_paths.get(path)
    snapshot_item = snapshot_paths[path]
    if not isinstance(source_item, dict) or methods(source_item) != expected:
        fail(f"W1.2 methods for {path} must be {sorted(expected)}")
    if (
        not isinstance(snapshot_item, dict)
        or ("$ref" not in snapshot_item and methods(snapshot_item) != expected)
    ):
        fail(f"W1.2 snapshot methods for {path} must be {sorted(expected)} or use a governed path reference")
    refs = {
        parameter.get("$ref")
        for parameter in source_item.get("parameters", [])
        if isinstance(parameter, dict)
    }
    if "#/components/parameters/CorrelationId" not in refs:
        fail(f"W1.2 path must require X-Correlation-ID: {path}")

for (path, method), status in ORGANIZATION_RUNTIME_STATUS.items():
    if source_paths[path][method].get("x-implementation-status") != status:
        fail(f"{method.upper()} {path} must be marked {status}")

for (path, method), expected in ORGANIZATION_RESPONSES.items():
    actual = set(source_paths[path][method].get("responses", {}))
    if actual != expected:
        fail(f"{method.upper()} {path} responses must be exactly {sorted(expected)}")

for (path, method, status), expected_ref in ORGANIZATION_SUCCESS_RESPONSES.items():
    actual_ref = source_paths[path][method]["responses"][status].get("$ref")
    if actual_ref != expected_ref:
        fail(f"{method.upper()} {path} success response must reference {expected_ref}")

schemas = source.get("components", {}).get("schemas", {})
account_states = schemas.get("AccountStatus", {}).get("enum")
if account_states != EXPECTED_ACCOUNT_STATES:
    fail(f"AccountStatus must be exactly {EXPECTED_ACCOUNT_STATES}")

person_create = schemas.get("PersonCreate", {})
if "identity_user_id" in person_create.get("properties", {}):
    fail("PersonCreate must not reference an Identity user")
if set(person_create.get("required", [])) != {"employee_number", "display_name_ar", "status"}:
    fail("PersonCreate must freeze Organization-owned Person fields")

person_reference = schemas.get("PersonReference", {})
if set(person_reference.get("required", [])) != {"person_id", "person_version", "status", "display_name_ar"}:
    fail("PersonReference must include person_id, person_version, status, and display_name_ar")
if set(person_reference.get("properties", {})) & {"national_id", "primary_email", "primary_phone"}:
    fail("PersonReference must not expose authoritative PII")

account_create = schemas.get("UserAccountCreate", {})
if set(account_create.get("required", [])) != {"person_id", "person_version", "username"}:
    fail("UserAccountCreate must bind account creation to the validated person_version")

user_account = schemas.get("UserAccount", {})
if set(user_account.get("required", [])) != {"id", "username", "person_id", "person_version", "status", "must_change_password", "password_version", "locked_until", "display_name_ar", "display_name_en"}:
    fail("UserAccount response must publish the approved display snapshot and account lifecycle state")
if set(user_account.get("properties", {})) & {"password", "password_hash", "token", "national_id", "primary_email", "primary_phone"}:
    fail("UserAccount response must not expose credentials, tokens, or authoritative Person PII")

import_create = schemas.get("ImportJobCreate", {})
if set(import_create.get("required", [])) != {"quarantine_object_id", "template_code", "import_type"}:
    fail("ImportJobCreate must require an encrypted quarantine reference and governed template")

import_job = schemas.get("ImportJob", {})
if set(import_job.get("required", [])) != {"id", "template_code", "import_type", "status", "submitted_by_user_id", "approved_by_user_id", "total_rows", "valid_rows", "error_rows", "applied_at", "lock_version"}:
    fail("ImportJob response must publish only the governed lifecycle summary")
if set(import_job.get("properties", {})) & {"quarantine_object_id", "source_filename", "notes", "decision_reason", "payload"}:
    fail("ImportJob response must not expose quarantine references, raw rows, or operator notes")

import_row = schemas.get("ImportJobRow", {})
if set(import_row.get("required", [])) != {"id", "row_number", "proposed_action", "proposed_target_id", "validation_errors", "decision", "applied_at"}:
    fail("ImportJobRow response must publish the redacted row decision summary")
if set(import_row.get("properties", {})) & {"encrypted_payload", "payload", "raw_payload"}:
    fail("ImportJobRow response must not expose encrypted or raw row payloads")

facility_create = schemas.get("FacilityCreate", {})
if set(facility_create.get("required", [])) != {"cluster_id", "type_code", "code", "name"}:
    fail("FacilityCreate must freeze the implemented facility payload")
if set(facility_create.get("properties", {})) != {"cluster_id", "type_code", "code", "name", "name_en"}:
    fail("FacilityCreate must not expose unit-only fields such as parent_id")

cluster_patch = schemas.get("ClusterPatch", {})
if set(cluster_patch.get("required", [])) != {"name"} or set(cluster_patch.get("properties", {})) != {"name", "reason"}:
    fail("ClusterPatch must update the singleton profile without exposing archive or parent fields")

facility_patch = schemas.get("FacilityPatch", {})
if set(facility_patch.get("properties", {})) != {"name", "status", "reason"}:
    fail("FacilityPatch must freeze profile and lifecycle fields")

organization_unit = schemas.get("OrganizationUnit", {})
if set(organization_unit.get("required", [])) != {"id", "cluster_id", "parent_id", "parent_type", "type_code", "code", "name_ar", "name_en", "status", "path_cache", "depth", "lock_version"}:
    fail("OrganizationUnit response must publish hierarchy, cached path, lifecycle, and lock version")

position = schemas.get("Position", {})
if set(position.get("required", [])) != {"id", "organization_unit_id", "code", "title_ar", "manager_position_id", "is_active", "lock_version"}:
    fail("Position response must publish unit, manager position, activity, and lock version")

person = schemas.get("Person", {})
if set(person.get("required", [])) != {"id", "employee_number", "display_name_ar", "display_name_en", "status", "person_version"}:
    fail("Person response must publish the authorized profile and monotonic person_version")
if set(person.get("properties", {})) & {"national_id", "primary_email", "primary_phone"}:
    fail("Person response must not expose authoritative PII")

unit_create = schemas.get("OrganizationNodeCreate", {})
if unit_create.get("properties", {}).get("type_code", {}).get("pattern") != "^[a-z][a-z0-9_]{1,63}$":
    fail("OrganizationNodeCreate type_code must match the runtime governed taxonomy format")

position_create = schemas.get("PositionCreate", {})
manager_create = position_create.get("properties", {}).get("manager_position_id", {})
if {item.get("type", item.get("$ref")) for item in manager_create.get("oneOf", [])} != {"null", "#/components/schemas/UUIDv7"}:
    fail("PositionCreate manager_position_id must allow UUIDv7 or null")


assignment = schemas.get("Assignment", {})
if set(assignment.get("required", [])) != {"id", "person_id", "position_id", "start_at", "end_at", "is_primary", "status", "end_reason", "lock_version"}:
    fail("Assignment response must publish period, lifecycle, primary flag, and lock version")
if set(assignment.get("properties", {})) & {"display_name_ar", "employee_number", "national_id", "email", "phone"}:
    fail("Assignment response must not duplicate Person PII")

snapshot_schemas = snapshot.get("components", {}).get("schemas", {})
session_required = set(snapshot_schemas.get("W12Session", {}).get("required", []))
if not {"account_status", "must_change_password"}.issubset(session_required):
    fail("Session must publish account status and first-login password-change state")

for path, method, required_refs in (
    ("/me/scope", "put", {"#/components/parameters/IfMatch", "#/components/parameters/IdempotencyKey"}),
    ("/organization/cluster", "post", {"#/components/parameters/IdempotencyKey"}),
    ("/organization/cluster", "patch", {"#/components/parameters/IfMatch"}),
    ("/organization/units", "post", {"#/components/parameters/IdempotencyKey"}),
    ("/organization/units/{unitId}", "patch", {"#/components/parameters/IfMatch"}),
    ("/organization/positions", "post", {"#/components/parameters/IdempotencyKey"}),
    ("/organization/positions/{positionId}", "patch", {"#/components/parameters/IfMatch"}),
    ("/organization/people", "post", {"#/components/parameters/IdempotencyKey"}),
    ("/organization/people/{personId}", "patch", {"#/components/parameters/IfMatch"}),
    ("/organization/assignments", "post", {"#/components/parameters/IdempotencyKey"}),
    ("/organization/assignments/{assignmentId}/end", "post", {"#/components/parameters/IfMatch", "#/components/parameters/IdempotencyKey"}),
    ("/organization/import-jobs", "post", {"#/components/parameters/IdempotencyKey"}),
    ("/organization/import-jobs/{jobId}/{jobAction}", "post", {"#/components/parameters/IfMatch", "#/components/parameters/IdempotencyKey"}),
    ("/identity/accounts", "post", {"#/components/parameters/IdempotencyKey"}),
    ("/identity/accounts/{accountId}/{accountAction}", "post", {"#/components/parameters/IfMatch", "#/components/parameters/IdempotencyKey"}),
    ("/authorization/bootstrap", "post", {"#/components/parameters/IfMatch", "#/components/parameters/IdempotencyKey"}),
):
    operation = source_paths[path][method]
    refs = {
        parameter.get("$ref")
        for parameter in operation.get("parameters", [])
        if isinstance(parameter, dict)
    }
    if not required_refs.issubset(refs):
        fail(f"{method.upper()} {path} is missing governed replay/concurrency headers")


package = json.loads((ROOT / "apps/web/package.json").read_text(encoding="utf-8"))
if "w1-2.openapi.yaml" not in package.get("scripts", {}).get("api:lint", ""):
    fail("apps/web api:lint must include the frozen W1.2 snapshot")

print("W1.2 governed OpenAPI source and readiness snapshot are valid.")
