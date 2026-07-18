#!/usr/bin/env python3

import json
import re
import sys
from collections import Counter
from pathlib import Path

try:
    import yaml
except ImportError:
    print("ERROR: PyYAML is required to validate W1.2 contracts.", file=sys.stderr)
    raise SystemExit(2)


ROOT = Path(__file__).resolve().parent.parent
SNAPSHOT = ROOT / "docs/contracts/api/w1-2.openapi.yaml"
SOURCE = ROOT / "docs/contracts/api/openapi.yaml"
ASYNCAPI = ROOT / "docs/contracts/events/asyncapi.yaml"
CAPABILITY_CONTRACT_PATHS = (
    "contracts/capabilities/identity-credentials-and-sessions.md",
    "contracts/capabilities/document-signed-direct-upload.md",
    "contracts/capabilities/organization-import-rows-v1.md",
    "contracts/capabilities/temporary-assignment.md",
)
CAPABILITY_DOCUMENT_PATHS = tuple(f"docs/{path}" for path in CAPABILITY_CONTRACT_PATHS)
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
EXPECTED_EVENTS = {
    "cluster-created": (
        "ClusterCreated",
        ROOT / "docs/contracts/schemas/cluster-created.schema.json",
        {"cluster", "access_context", "classification"},
    ),
    "facility-created": (
        "FacilityCreated",
        ROOT / "docs/contracts/schemas/facility-created.schema.json",
        {"facility", "access_context", "classification"},
    ),
    "cluster-updated": (
        "ClusterUpdated",
        ROOT / "docs/contracts/schemas/cluster-updated.schema.json",
        {"cluster", "access_context", "classification"},
    ),
    "facility-updated": (
        "FacilityUpdated",
        ROOT / "docs/contracts/schemas/facility-updated.schema.json",
        {"facility", "access_context", "classification"},
    ),
    "facility-archived": (
        "FacilityArchived",
        ROOT / "docs/contracts/schemas/facility-archived.schema.json",
        {"facility", "access_context", "classification"},
    ),
    "organization-unit-created": (
        "OrganizationUnitCreated",
        ROOT / "docs/contracts/schemas/organization-unit-changed.schema.json",
        {"organization_unit", "access_context", "classification"},
    ),
    "organization-unit-moved": (
        "OrganizationUnitMoved",
        ROOT / "docs/contracts/schemas/organization-unit-changed.schema.json",
        {"organization_unit", "access_context", "classification"},
    ),
    "organization-unit-updated": (
        "OrganizationUnitUpdated",
        ROOT / "docs/contracts/schemas/organization-unit-changed.schema.json",
        {"organization_unit", "access_context", "classification"},
    ),
    "organization-unit-archived": (
        "OrganizationUnitArchived",
        ROOT / "docs/contracts/schemas/organization-unit-changed.schema.json",
        {"organization_unit", "access_context", "classification"},
    ),
    "position-created": (
        "PositionCreated",
        ROOT / "docs/contracts/schemas/position-changed.schema.json",
        {"position", "access_context", "classification"},
    ),
    "position-updated": (
        "PositionUpdated",
        ROOT / "docs/contracts/schemas/position-changed.schema.json",
        {"position", "access_context", "classification"},
    ),
    "assignment-started": (
        "AssignmentStarted",
        ROOT / "docs/contracts/schemas/assignment-changed.schema.json",
        {"assignment", "access_context", "classification"},
    ),
    "assignment-ended": (
        "AssignmentEnded",
        ROOT / "docs/contracts/schemas/assignment-changed.schema.json",
        {"assignment", "access_context", "classification"},
    ),
    "person-registered": (
        "PersonRegistered",
        ROOT / "docs/contracts/schemas/person-changed.schema.json",
        {"person", "access_context", "classification"},
    ),
    "person-updated": (
        "PersonUpdated",
        ROOT / "docs/contracts/schemas/person-changed.schema.json",
        {"person", "access_context", "classification"},
    ),
    "import-job-submitted": (
        "ImportJobSubmitted",
        ROOT / "docs/contracts/schemas/import-job-changed.schema.json",
        {"import_job", "access_context", "classification"},
    ),
    "import-job-validated": (
        "ImportJobValidated",
        ROOT / "docs/contracts/schemas/import-job-changed.schema.json",
        {"import_job", "access_context", "classification"},
    ),
    "import-job-approved": (
        "ImportJobApproved",
        ROOT / "docs/contracts/schemas/import-job-changed.schema.json",
        {"import_job", "access_context", "classification"},
    ),
    "import-job-rejected": (
        "ImportJobRejected",
        ROOT / "docs/contracts/schemas/import-job-changed.schema.json",
        {"import_job", "access_context", "classification"},
    ),
    "import-job-applied": (
        "ImportJobApplied",
        ROOT / "docs/contracts/schemas/import-job-changed.schema.json",
        {"import_job", "access_context", "classification"},
    ),
    "import-job-cancelled": (
        "ImportJobCancelled",
        ROOT / "docs/contracts/schemas/import-job-changed.schema.json",
        {"import_job", "access_context", "classification"},
    ),
    "import-job-failed": (
        "ImportJobFailed",
        ROOT / "docs/contracts/schemas/import-job-changed.schema.json",
        {"import_job", "access_context", "classification"},
    ),
    "identity-provisioning-requested": (
        "IdentityProvisioningRequested",
        ROOT / "docs/contracts/schemas/identity-provisioning-requested.schema.json",
        {"person_id", "person_version", "access_context", "classification"},
    ),
    "person-access-status-changed": (
        "PersonAccessStatusChanged",
        ROOT / "docs/contracts/schemas/person-access-status-changed.schema.json",
        {"person_id", "person_version", "access_context", "classification"},
    ),
    "user-account-created": (
        "UserAccountCreated",
        ROOT / "docs/contracts/schemas/user-account-changed.schema.json",
        {"account_id", "person_id", "person_version", "status", "action", "lock_version", "access_context", "classification"},
    ),
    "user-account-changed": (
        "UserAccountChanged",
        ROOT / "docs/contracts/schemas/user-account-changed.schema.json",
        {"account_id", "person_id", "person_version", "status", "action", "lock_version", "access_context", "classification"},
    ),
}


def fail(message: str) -> None:
    print(f"ERROR: {message}", file=sys.stderr)
    raise SystemExit(1)


def load_yaml(path: Path) -> dict:
    document = yaml.safe_load(path.read_text(encoding="utf-8"))
    if not isinstance(document, dict):
        fail(f"{path.relative_to(ROOT)} must contain a YAML object")
    return document


def load_markdown(path: Path) -> tuple[dict, str]:
    content = path.read_text(encoding="utf-8")
    parts = content.split("---", 2)
    if len(parts) != 3 or parts[0].strip():
        fail(f"{path.relative_to(ROOT)} must contain YAML front matter")
    metadata = yaml.safe_load(parts[1])
    if not isinstance(metadata, dict):
        fail(f"{path.relative_to(ROOT)} front matter must be an object")
    return metadata, parts[2]


def nav_paths(node: object) -> list[str]:
    if isinstance(node, str):
        return [node]
    if isinstance(node, list):
        return [path for item in node for path in nav_paths(item)]
    if isinstance(node, dict):
        return [path for item in node.values() for path in nav_paths(item)]
    return []


def methods(path_item: dict) -> set[str]:
    return set(path_item) & {"get", "post", "put", "patch", "delete"}


snapshot = load_yaml(SNAPSHOT)
source = load_yaml(SOURCE)
asyncapi = load_yaml(ASYNCAPI)

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
    if not isinstance(snapshot_item, dict):
        fail(f"W1.2 snapshot path must be an object: {path}")
    if path == "/auth/login":
        if methods(snapshot_item) != expected:
            fail("W1.2 login must override only POST with the frozen session response")
    elif "$ref" not in snapshot_item:
        fail(f"W1.2 snapshot path must reference the governed OpenAPI source: {path}")
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

uuidv7_pattern = "^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
import_row_specs = {
    "facilities-import-row-v1.schema.json": "FacilityCreate",
    "organization-units-import-row-v1.schema.json": "OrganizationNodeCreate",
    "positions-import-row-v1.schema.json": "PositionCreate",
}
for filename, source_schema_name in import_row_specs.items():
    path = ROOT / "docs/contracts/schemas" / filename
    row_schema = json.loads(path.read_text(encoding="utf-8"))
    create_schema = schemas.get(source_schema_name, {})
    if row_schema.get("$schema") != "https://json-schema.org/draft/2020-12/schema":
        fail(f"{filename} must use JSON Schema Draft 2020-12")
    if row_schema.get("$id") != f"https://contracts.cluster.example/schemas/{filename}":
        fail(f"{filename} must publish its governed schema id")
    if row_schema.get("type") != "object" or row_schema.get("additionalProperties") is not False:
        fail(f"{filename} must be a closed object schema")
    if set(row_schema.get("required", [])) != set(create_schema.get("required", [])):
        fail(f"{filename} required fields must match {source_schema_name}")
    row_properties = row_schema.get("properties", {})
    create_properties = create_schema.get("properties", {})
    if set(row_properties) != set(create_properties):
        fail(f"{filename} fields must match {source_schema_name}")
    if row_schema.get("$defs", {}).get("uuidv7", {}).get("pattern") != uuidv7_pattern:
        fail(f"{filename} must constrain identifiers to lowercase UUIDv7")
    for field, create_property in create_properties.items():
        row_property = row_properties[field]
        if create_property.get("$ref") == "#/components/schemas/UUIDv7":
            if row_property != {"$ref": "#/$defs/uuidv7"}:
                fail(f"{filename} {field} must reference UUIDv7")
        elif field == "manager_position_id":
            variants = {item.get("type", item.get("$ref")) for item in row_property.get("oneOf", [])}
            if variants != {"null", "#/$defs/uuidv7"}:
                fail(f"{filename} manager_position_id must allow UUIDv7 or null")
        elif row_property != create_property:
            fail(f"{filename} {field} constraints must match {source_schema_name}")

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

channels = asyncapi.get("channels", {})
messages = asyncapi.get("components", {}).get("messages", {})
for channel_name, (message_name, schema_path, expected_required) in EXPECTED_EVENTS.items():
    if channel_name not in channels or message_name not in messages:
        fail(f"AsyncAPI must publish and consume {message_name}")
    schema = json.loads(schema_path.read_text(encoding="utf-8"))
    required = set(schema.get("required", []))
    if not expected_required.issubset(required):
        fail(f"{schema_path.name} is missing versioning or security fields")
    if set(schema.get("properties", {})) & {"national_id", "email", "phone", "password", "token"}:
        fail(f"{schema_path.name} must not carry PII or secrets")

document_rules = {
    "docs/adr/024-organization-identity-import-boundaries.md": {
        "required": ["status: accepted"],
        "forbidden": [],
    },
    "docs/architecture/context-map.md": {
        "required": ["| `Identity` | `Organization`, `PlatformSettings` |"],
        "forbidden": [],
    },
    "docs/domain/organization-and-people.md": {
        "required": ["IdentityProvisioningRequested", "person_version", "`person_id` CHAR(36) UUIDv7 NOT NULL FK"],
        "forbidden": ["FK -> `users.id`", "`raw_payload`"],
    },
    "docs/domain/identity.md": {
        "required": ["Pending، Active، Locked، Disabled، Archived", "`person_id` CHAR(36) UUIDv7 NULL"],
        "forbidden": ["UserAccountSuspended", "SuspendUserAccount", "Disabled أو Suspended"],
    },
    "docs/data-security/logical-data-model.md": {
        "required": ["| Person | Organization |", "RFC 9562 UUIDv7"],
        "forbidden": ["| Person | Identity |", "FK to Person, unique"],
    },
    "docs/data-security/audit-and-privacy.md": {
        "required": ["RFC 9562 UUIDv7", "IN p_event_id BINARY(16)"],
        "forbidden": ["UUID_TO_BIN(UUID())", "SET v_chain_id = UUID()"],
    },
    "docs/contracts/capabilities/identity-credentials-and-sessions.md": {
        "required": [
            "**حالة التنفيذ:** `planned`",
            "`Secure` و`HttpOnly` و`SameSite=Lax`",
            "إثبات CSRF",
            "رابط تفعيل أحادي الاستخدام",
            "يبقى `pending`",
            "TOTP إلزامي",
            "جلسة opaque محفوظة على الخادم بلا JWT",
        ],
        "forbidden": ["**حالة التنفيذ:** `implemented`"],
    },
    "docs/contracts/capabilities/document-signed-direct-upload.md": {
        "required": [
            "**حالة التنفيذ:** `planned`",
            "`quarantine_object_id` opaque",
            "`method=PUT`",
            "SHA-256",
            "MIME",
            "AV",
            "سبع سنوات",
            "عشر سنوات",
            "1 GiB",
            "200 MB",
        ],
        "forbidden": ["**حالة التنفيذ:** `implemented`"],
    },
    "docs/contracts/capabilities/organization-import-rows-v1.md": {
        "required": [
            "**حالة التنفيذ:** `planned`",
            "`facilities`",
            "`organization_units`",
            "`positions`",
            "create-only",
            "additionalProperties=false",
        ],
        "forbidden": ["**حالة التنفيذ:** `implemented`"],
    },
    "docs/contracts/capabilities/temporary-assignment.md": {
        "required": [
            "**حالة التنفيذ:** `planned`",
            "OrganizationUnit واحدة",
            "`reason`",
            "لا backdating",
            "90 يوماً",
            "(person_id, organization_unit_id, capability_code)",
            "`409`",
        ],
        "forbidden": ["**حالة التنفيذ:** `implemented`"],
    },
}
for relative_path, rules in document_rules.items():
    content = (ROOT / relative_path).read_text(encoding="utf-8")
    for required in rules["required"]:
        if required not in content:
            fail(f"{relative_path} is missing required W1.2 boundary: {required}")
    for forbidden in rules["forbidden"]:
        if forbidden in content:
            fail(f"{relative_path} retains forbidden W1.2 boundary: {forbidden}")

contracts_index_metadata, contracts_index_body = load_markdown(ROOT / "docs/contracts/README.md")
index_references = contracts_index_metadata.get("references", [])
if not isinstance(index_references, list):
    fail("docs/contracts/README.md references must be a list")
for contract_path in CAPABILITY_DOCUMENT_PATHS:
    if index_references.count(contract_path) != 1:
        fail(f"docs/contracts/README.md must reference {contract_path} exactly once")

index_links = Counter(re.findall(r"\[[^\]]+\]\(([^)\s]+)\)", contracts_index_body))
for contract_path in CAPABILITY_CONTRACT_PATHS:
    relative_link = contract_path.removeprefix("contracts/")
    if index_links[relative_link] != 1:
        fail(f"docs/contracts/README.md must link {relative_link} exactly once")

module_metadata, module_body = load_markdown(ROOT / "docs/contracts/module-contracts.md")
module_references = module_metadata.get("references", [])
if not isinstance(module_references, list):
    fail("docs/contracts/module-contracts.md references must be a list")
for contract_path in CAPABILITY_DOCUMENT_PATHS:
    if module_references.count(contract_path) != 1:
        fail(f"docs/contracts/module-contracts.md must reference {contract_path} exactly once")

module_boundary_terms = {
    "Identity credentials": {"Credential", "pending", "opaque", "CSRF"},
    "Documents quarantine": {"Documents", "quarantine_object_id", "Organization"},
    "Organization import rows": {"facilities", "organization_units", "positions", "create-only"},
    "TemporaryAssignment": {"TemporaryAssignment", "90", "Authorization"},
}
for boundary, terms in module_boundary_terms.items():
    missing_terms = sorted(term for term in terms if term not in module_body)
    if missing_terms:
        fail(f"docs/contracts/module-contracts.md is missing {boundary} boundary terms: {missing_terms}")

mkdocs = load_yaml(ROOT / "mkdocs.yml")
mkdocs_nav_counts = Counter(nav_paths(mkdocs.get("nav", [])))
for contract_path in CAPABILITY_CONTRACT_PATHS:
    if mkdocs_nav_counts[contract_path] != 1:
        fail(f"mkdocs.yml must expose {contract_path} exactly once")

catalog = (ROOT / "docs/catalog.yaml").read_text(encoding="utf-8")
for catalog_path in (
    "contracts/api/w1-2.openapi.yaml",
    "contracts/schemas/cluster-created.schema.json",
    "contracts/schemas/cluster-updated.schema.json",
    "contracts/schemas/facility-created.schema.json",
    "contracts/schemas/facility-updated.schema.json",
    "contracts/schemas/facility-archived.schema.json",
    "contracts/schemas/organization-unit-changed.schema.json",
    "contracts/schemas/position-changed.schema.json",
    "contracts/schemas/assignment-changed.schema.json",
    "contracts/schemas/person-changed.schema.json",
    "contracts/schemas/import-job-changed.schema.json",
    "contracts/schemas/user-account-changed.schema.json",
    "contracts/schemas/identity-provisioning-requested.schema.json",
    "contracts/schemas/person-access-status-changed.schema.json",
    *CAPABILITY_CONTRACT_PATHS,
    "contracts/schemas/facilities-import-row-v1.schema.json",
    "contracts/schemas/organization-units-import-row-v1.schema.json",
    "contracts/schemas/positions-import-row-v1.schema.json",
):
    if catalog.count(f"path: {catalog_path}") != 1:
        fail(f"docs/catalog.yaml must discover {catalog_path} exactly once")

package = json.loads((ROOT / "apps/web/package.json").read_text(encoding="utf-8"))
if "w1-2.openapi.yaml" not in package.get("scripts", {}).get("api:lint", ""):
    fail("apps/web api:lint must include the frozen W1.2 snapshot")

print("W1.2 contracts, remaining capability contracts, import schemas, and readiness snapshot are valid.")
