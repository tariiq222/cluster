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
ASYNCAPI = ROOT / "docs/contracts/events/asyncapi.yaml"
EXPECTED_METHODS = {
    "/auth/login": {"post"},
    "/auth/logout": {"post"},
    "/me": {"get"},
    "/me/scopes": {"get"},
    "/me/scope": {"put"},
    "/documents": {"get", "post"},
    "/documents/uploads": {"post"},
    "/documents/uploads/{uploadId}/complete": {"post"},
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
}
ORGANIZATION_RESPONSES = {
    ("/organization/cluster", "get"): {"200", "401", "403", "404"},
    ("/organization/cluster", "post"): {"201", "400", "401", "403", "409"},
    ("/organization/cluster", "patch"): {"200", "400", "401", "403", "404", "412", "500"},
    ("/organization/facilities", "get"): {"200", "401", "403"},
    ("/organization/facilities", "post"): {"201", "400", "401", "403", "409"},
    ("/organization/facilities/{facilityId}", "get"): {"200", "400", "401", "403", "404"},
    ("/organization/facilities/{facilityId}", "patch"): {"200", "400", "401", "403", "404", "409", "412", "500"},
}
ORGANIZATION_SUCCESS_RESPONSES = {
    ("/organization/cluster", "get", "200"): "#/components/responses/ClusterEntity",
    ("/organization/cluster", "post", "201"): "#/components/responses/ClusterEntity",
    ("/organization/cluster", "patch", "200"): "#/components/responses/ClusterEntity",
    ("/organization/facilities", "get", "200"): "#/components/responses/FacilityCollection",
    ("/organization/facilities", "post", "201"): "#/components/responses/FacilityEntity",
    ("/organization/facilities/{facilityId}", "get", "200"): "#/components/responses/FacilityEntity",
    ("/organization/facilities/{facilityId}", "patch", "200"): "#/components/responses/FacilityEntity",
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

import_create = schemas.get("ImportJobCreate", {})
if set(import_create.get("required", [])) != {"quarantine_object_id", "template_code", "import_type"}:
    fail("ImportJobCreate must require an encrypted quarantine reference and governed template")

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

snapshot_schemas = snapshot.get("components", {}).get("schemas", {})
session_required = set(snapshot_schemas.get("W12Session", {}).get("required", []))
if not {"account_status", "must_change_password"}.issubset(session_required):
    fail("Session must publish account status and first-login password-change state")

for path, method, required_refs in (
    ("/me/scope", "put", {"#/components/parameters/IfMatch", "#/components/parameters/IdempotencyKey"}),
    ("/organization/cluster", "post", {"#/components/parameters/IdempotencyKey"}),
    ("/organization/cluster", "patch", {"#/components/parameters/IfMatch"}),
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
}
for relative_path, rules in document_rules.items():
    content = (ROOT / relative_path).read_text(encoding="utf-8")
    for required in rules["required"]:
        if required not in content:
            fail(f"{relative_path} is missing required W1.2 boundary: {required}")
    for forbidden in rules["forbidden"]:
        if forbidden in content:
            fail(f"{relative_path} retains forbidden W1.2 boundary: {forbidden}")

catalog = (ROOT / "docs/catalog.yaml").read_text(encoding="utf-8")
for catalog_path in (
    "contracts/api/w1-2.openapi.yaml",
    "contracts/schemas/cluster-created.schema.json",
    "contracts/schemas/cluster-updated.schema.json",
    "contracts/schemas/facility-created.schema.json",
    "contracts/schemas/facility-updated.schema.json",
    "contracts/schemas/facility-archived.schema.json",
    "contracts/schemas/identity-provisioning-requested.schema.json",
    "contracts/schemas/person-access-status-changed.schema.json",
):
    if catalog.count(f"path: {catalog_path}") != 1:
        fail(f"docs/catalog.yaml must discover {catalog_path} exactly once")

package = json.loads((ROOT / "apps/web/package.json").read_text(encoding="utf-8"))
if "w1-2.openapi.yaml" not in package.get("scripts", {}).get("api:lint", ""):
    fail("apps/web api:lint must include the frozen W1.2 snapshot")

print("W1.2 contracts, events, ownership boundaries, and readiness snapshot are valid.")
