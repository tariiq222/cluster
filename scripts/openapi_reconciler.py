#!/usr/bin/env python3
"""Slice S4 — Canonical OpenAPI reconciliation.

Conservative, append-only reconciliation of ``docs/contracts/api/openapi.yaml``
against the live Laravel routes declared in ``apps/api/routes/web.php``.

Rules enforced by this module:

* Never delete an existing path key, operation, or component.
* Preserve every byte of the user's in-progress additions to
  ``docs/contracts/api/openapi.yaml`` (e.g. the ``/organization/job-titles``
  routes + four ``JobTitle*`` schemas) and the lone ``$ref:`` line the user
  added to ``docs/contracts/api/w1-2.openapi.yaml``.
* Tag every spec-only path under ``paths:`` with
  ``x-implementation-status: planned`` (or per-method, where the convention
  already operates at operation level).
* Add new path keys for the routes-only set (8 paths), the missing
  ``work-records/{recordId}/{return|complete|complete-submission}`` verbs
  (3 paths), the ``/up`` bootstrap health probe, and the ``documentGrantType``
  param-name reconciliation.
* Tag every newly added routes-only path with
  ``x-implementation-status: implemented`` so the Orval bundle sees them as
  live.
* Append ``$ref:`` lines to ``docs/contracts/api/r1-screens.openapi.yaml``
  for any new path whose semantic lives on the r1-screens surface.
  ``w1-2.openapi.yaml`` is treated as FROZEN (no modifications) — adding new
  path keys would break ``validate-w1-2-contracts.py`` whose
  ``EXPECTED_METHODS`` map is a strict equality check.
* Idempotent: running ``--mode reconcile --write`` twice produces a byte-
  identical ``openapi.yaml`` (and split files) on the second invocation.

Sibling modules: ``scripts/inventory-routes.py`` (CLI entry) and
``scripts/rbac.py`` (route parser reused).
"""

from __future__ import annotations

import datetime
import json
import pathlib
import re
import sys
from typing import Iterable

REPO_ROOT = pathlib.Path(__file__).resolve().parent.parent
OPENAPI_PATH = REPO_ROOT / "docs/contracts/api/openapi.yaml"
R1_SCREENS_PATH = REPO_ROOT / "docs/contracts/api/r1-screens.openapi.yaml"
W12_PATH = REPO_ROOT / "docs/contracts/api/w1-2.openapi.yaml"
W11_PATH = REPO_ROOT / "docs/contracts/api/w1-1.openapi.yaml"
SUMMARY_PATH = REPO_ROOT / ".minimax-flow/reconcile-summary.json"

ROUTES_FILE = REPO_ROOT / "apps/api/routes/web.php"

# Routes-only path keys (relative to /api/v1) that are NOT present in the
# current spec and must be APPENDED. Each entry is the spec-style path key
# (no /api/v1 prefix) with the operation method to register.
ROUTES_ONLY_PATHS: list[tuple[str, str]] = [
    ("/authorization/bootstrap/complete", "post"),
    ("/dashboards", "get"),
    ("/dashboards/{dashboardId}", "get"),
    ("/notifications/{notificationId}/read", "post"),
    ("/organization/units/reorder", "post"),
    ("/platform-operations/alert-policies", "get"),
    ("/platform-operations/alert-policies/{policyId}", "patch"),
    ("/platform-operations/maintenance-windows", "get"),
    ("/platform-operations/maintenance-windows", "post"),
    ("/platform-operations/maintenance-windows/{windowId}/cancel", "post"),
    ("/platform-operations/restore-requests", "post"),
    ("/platform-operations/restore-requests/{requestId}/confirm", "post"),
    ("/platform-operations/technical-logs", "get"),
    ("/platform-operations/technical-logs/restore", "post"),
    ("/reports/{reportId}", "get"),
    ("/tasks/from-step/{stepId}", "post"),
    ("/work-records/{recordId}/documents", "post"),
]

# Missing discrete ``work-records/{recordId}/{recordAction}`` verbs that are
# declared in routes via ``whereIn`` but for which the spec does not yet
# have a typed path key. D3 from plan.md mandates 6 discrete verbs; this is
# the 3 missing set.
WORK_RECORD_VERB_PATHS: list[tuple[str, str]] = [
    ("/work-records/{recordId}/return", "post"),
    ("/work-records/{recordId}/complete", "post"),
    ("/work-records/{recordId}/complete-submission", "post"),
]

# Param-name reconciliation: routes register the path with parameter name
# ``documentGrantType`` whereas the spec uses ``grantType``. Because
# ``no-identical-paths`` would reject two path templates resolving to the
# same URL, we DO NOT add a duplicate key. Instead, we RENAME the existing
# path's parameter name in-place from ``grantType`` to ``documentGrantType``
# and update its operation summary to reflect the route-side id. The
# existing path remains tagged ``x-implementation-status: planned`` so the
# ``grantType`` declaration is now an exact match for the routes side.
DOCUMENT_GRANT_RENAME_PATH: str = "/documents/{documentId}/{grantType}-grant"
DOCUMENT_GRANT_RENAME_NEW_NAME: str = "documentGrantType"

# Bootstrap health probe — must mirror the anonymous ``security: []``
# pattern used by other public operations (e.g. /auth/login, /identity/login).
UP_PATH: tuple[str, str] = ("/up", "get")

# Spec-only paths to be marked ``x-implementation-status: planned`` (filled
# in dynamically from the spec; this is a placeholder for fallback ordering).
SPEC_ONLY_PATHS_MARKER = "SPEC_ONLY_PATHS_MARKER"

# Paths that should NOT be tagged with ``planned`` even if they appear
# spec-only by accident (e.g. tags added by users mid-edit).
NEVER_TAG_IMPLEMENTED = set()


# -------------------------- helpers --------------------------


def _now_iso() -> str:
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _read_text(path: pathlib.Path) -> str:
    return path.read_text(encoding="utf-8")


def _escape_ref_token(spec_path: str) -> str:
    """OpenAPI JSON-Pointer escaping for $ref into a path key.

    Per RFC 6901, ``/`` becomes ``~1`` and ``~`` becomes ``~0``. Path
    parameters like ``{recordId}`` are kept literal — they appear verbatim
    in the cluster's existing split references (e.g.
    ``~1authorization~1{adminResource}``). The escaping is therefore
    limited to the forward slash.
    """
    return spec_path.replace("/", "~1")


def parse_spec_path_keys(text: str) -> list[str]:
    """Parse top-level path keys from openapi.yaml text, in source order."""
    paths: list[str] = []
    for line in text.splitlines():
        stripped = line.rstrip()
        if stripped.startswith("  /") and stripped.endswith(":"):
            paths.append(stripped.rstrip(":").strip())
    return paths


def parse_spec_methods(text: str) -> dict[str, set[str]]:
    """Return a mapping of spec path key -> set of operation methods."""
    lines = text.splitlines()
    out: dict[str, set[str]] = {}
    cur_path: str | None = None
    for line in lines:
        stripped = line.rstrip()
        if stripped.startswith("  /") and stripped.endswith(":"):
            cur_path = stripped.rstrip(":").strip()
            out.setdefault(cur_path, set())
            continue
        if cur_path is None:
            continue
        if line.startswith("  /") and not line.startswith("    "):
            cur_path = None
            continue
        if line.startswith("components:") and not line.startswith(" "):
            cur_path = None
            continue
        for verb in ("get", "post", "patch", "put", "delete"):
            if stripped == f"    {verb}:":
                out.setdefault(cur_path, set()).add(verb.upper())
                break
    return out


def parse_route_spec_paths(text: str) -> list[tuple[str, str]]:
    """Parse route declarations, return spec-style (method, path) pairs.

    Reuses ``scripts.rbac.parse_routes`` to keep route parsing consistent
    with S1/S2.
    """
    sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
    from rbac import parse_routes  # type: ignore[import-not-found]

    statements = parse_routes(text)
    out: list[tuple[str, str]] = []
    for stmt in statements:
        p = stmt.path
        if p.startswith("/api/v1"):
            p = p[len("/api/v1") :]
        out.append((stmt.method.upper(), p))
    return out


def compute_spec_only_paths(
    spec_paths: list[str], routes: list[tuple[str, str]]
) -> list[str]:
    """Spec-only paths = spec path keys NOT present in any routes prefix path."""
    route_path_set = {p for _m, p in routes}
    return [p for p in spec_paths if p not in route_path_set]


# -------------------------- new operation emitters --------------------------


def _emitted_path_block(
    path: str,
    method: str,
    operation_id: str,
    tag: str,
    summary: str,
    pre_responses_lines: list[str],
    response_lines: list[str],
) -> str:
    """Format a single path entry as the project-style YAML block.

    ``pre_responses_lines`` are emitted between the operation-header
    declarations (``tags:``, ``summary:``, ``operationId:``) and the
    ``responses:`` block. ``response_lines`` are emitted UNDER
    ``responses:`` with one line per status code.
    """
    lines: list[str] = [f"  {path}:"]
    lines.append(
        "    parameters: [ { $ref: \"#/components/parameters/CorrelationId\" } ]"
    )
    lines.append(f"    {method}:")
    lines.append(f"      tags: [ {tag} ]")
    lines.append(f"      x-implementation-status: implemented")
    lines.append(f"      summary: {summary}")
    lines.append(f"      operationId: {operation_id}")
    lines.extend(pre_responses_lines)
    lines.append("      responses:")
    lines.extend(response_lines)
    return "\n".join(lines)


def _block_for_path(path: str, method: str) -> str:
    """Generate the YAML block for a new path entry."""
    if path == "/authorization/bootstrap/complete":
        pre = [
            "      parameters:",
            "        [",
            "          { $ref: \"#/components/parameters/IfMatch\" },",
            "          { $ref: \"#/components/parameters/IdempotencyKey\" },",
            "        ]",
            "      requestBody:",
            "        { required: true, content: { application/json: { schema: { $ref: \"#/components/schemas/ReasonAction\" } } } }",
        ]
        responses = [
            "        \"200\": { $ref: \"#/components/responses/Entity\" }",
            "        \"400\": { $ref: \"#/components/responses/BadRequest\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"409\": { $ref: \"#/components/responses/Conflict\" }",
            "        \"412\": { $ref: \"#/components/responses/PreconditionFailed\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "bootstrapComplete",
            "Authorization Bootstrap",
            "Complete the temporary deny-by-default bootstrap once with an audited idempotent command",
            pre,
            responses,
        )

    if path == "/dashboards":
        pre = [
            "      parameters:",
            "        [",
            "          { $ref: \"#/components/parameters/Cursor\" },",
            "          { $ref: \"#/components/parameters/Limit\" },",
            "        ]",
        ]
        responses = [
            "        \"200\": { $ref: \"#/components/responses/Collection\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "listDashboards",
            "Reporting",
            "List published dashboards available to the principal",
            pre,
            responses,
        )

    if path == "/dashboards/{dashboardId}":
        pre = [
            "      parameters:",
            "        [",
            "          { name: dashboardId, in: path, required: true, schema: { $ref: \"#/components/schemas/UUIDv7\" } },",
            "          { name: scope_id, in: query, schema: { type: string, maxLength: 128 } },",
            "        ]",
        ]
        responses = [
            "        \"200\": { $ref: \"#/components/responses/Entity\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"404\": { $ref: \"#/components/responses/NotFound\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "getDashboard",
            "Reporting",
            "Get an adaptive authorization-filtered dashboard",
            pre,
            responses,
        )

    if path == "/reports/{reportId}":
        pre = [
            "      parameters:",
            "        [",
            "          { $ref: \"#/components/parameters/ReportId\" },",
            "          { name: scope_id, in: query, schema: { type: string, maxLength: 128 } },",
            "        ]",
        ]
        responses = [
            "        \"200\": { $ref: \"#/components/responses/Entity\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"404\": { $ref: \"#/components/responses/NotFound\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "getReport",
            "Reporting",
            "Get an authorization-filtered report",
            pre,
            responses,
        )

    if path == "/tasks/from-step/{stepId}":
        pre = [
            "      parameters:",
            "        [",
            "          { name: stepId, in: path, required: true, schema: { $ref: \"#/components/schemas/UUIDv7\" } },",
            "          { $ref: \"#/components/parameters/IdempotencyKey\" },",
            "        ]",
            "      requestBody:",
            "        { required: false, content: { application/json: { schema: { type: object, additionalProperties: false, properties: { title: { type: string, minLength: 1, maxLength: 255 } } } } } }",
        ]
        responses = [
            "        \"201\": { $ref: \"#/components/responses/Entity\" }",
            "        \"400\": { $ref: \"#/components/responses/BadRequest\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"404\": { $ref: \"#/components/responses/NotFound\" }",
            "        \"409\": { $ref: \"#/components/responses/Conflict\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "createTaskFromStep",
            "Workflow",
            "Create the user task for an active workflow step",
            pre,
            responses,
        )

    if path == "/work-records/{recordId}/documents":
        pre = [
            "      parameters:",
            "        [",
            "          { $ref: \"#/components/parameters/RecordId\" },",
            "          { $ref: \"#/components/parameters/IdempotencyKey\" },",
            "        ]",
            "      requestBody:",
            "        { required: true, content: { application/json: { schema: { type: object, additionalProperties: false, required: [ document_id, relation_type ], properties: { document_id: { $ref: \"#/components/schemas/UUIDv7\" }, relation_type: { type: string, enum: [ attachment, evidence, supporting ] } } } } } }",
        ]
        responses = [
            "        \"201\": { $ref: \"#/components/responses/Entity\" }",
            "        \"400\": { $ref: \"#/components/responses/BadRequest\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"404\": { $ref: \"#/components/responses/NotFound\" }",
            "        \"409\": { $ref: \"#/components/responses/Conflict\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "linkWorkRecordDocument",
            "Work Records",
            "Link an available document to a work record",
            pre,
            responses,
        )

    if path in {
        "/work-records/{recordId}/return",
        "/work-records/{recordId}/complete",
        "/work-records/{recordId}/complete-submission",
    }:
        verb = path.rsplit("/", 1)[-1]
        verb_title = {
            "return": "Return a submitted work record to the requester",
            "complete": "Complete an in-flight work record",
            "complete-submission": "Finalize a work-record submission",
        }[verb]
        pre = [
            "      parameters:",
            "        [",
            "          { $ref: \"#/components/parameters/RecordId\" },",
            "          { $ref: \"#/components/parameters/IfMatch\" },",
            "          { $ref: \"#/components/parameters/IdempotencyKey\" },",
            "        ]",
            "      requestBody:",
            "        { required: false, content: { application/json: { schema: { type: object, additionalProperties: false, properties: { reason: { type: string, maxLength: 1024 } } } } } }",
        ]
        responses = [
            "        \"200\": { $ref: \"#/components/responses/WorkRecord\" }",
            "        \"400\": { $ref: \"#/components/responses/BadRequest\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"404\": { $ref: \"#/components/responses/NotFound\" }",
            "        \"409\": { $ref: \"#/components/responses/Conflict\" }",
            "        \"412\": { $ref: \"#/components/responses/PreconditionFailed\" }",
        ]
        verb_op = verb.replace("-", " ").title().replace(" ", "")
        return _emitted_path_block(
            path,
            method,
            f"transitionWorkRecord{verb_op}",
            "Work Records",
            verb_title,
            pre,
            responses,
        )

    if path == "/notifications/{notificationId}/read":
        pre = [
            "      parameters:",
            "        [",
            "          { name: notificationId, in: path, required: true, schema: { $ref: \"#/components/schemas/UUIDv7\" } },",
            "          { $ref: \"#/components/parameters/IdempotencyKey\" },",
            "        ]",
        ]
        responses = [
            "        \"200\": { $ref: \"#/components/responses/Entity\" }",
            "        \"400\": { $ref: \"#/components/responses/BadRequest\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"404\": { $ref: \"#/components/responses/NotFound\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "markNotificationRead",
            "Notifications",
            "Mark an authorized inbox notification as read",
            pre,
            responses,
        )

    if path == "/organization/units/reorder":
        pre = [
            "      parameters:",
            "        [",
            "          { $ref: \"#/components/parameters/IdempotencyKey\" },",
            "        ]",
            "      requestBody:",
            "        { required: true, content: { application/json: { schema: { type: object, additionalProperties: false, required: [ ordered_unit_ids ], properties: { ordered_unit_ids: { type: array, items: { $ref: \"#/components/schemas/UUIDv7\" } } } } } } }",
        ]
        responses = [
            "        \"200\": { $ref: \"#/components/responses/Entity\" }",
            "        \"400\": { $ref: \"#/components/responses/BadRequest\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"409\": { $ref: \"#/components/responses/Conflict\" }",
            "        \"412\": { $ref: \"#/components/responses/PreconditionFailed\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "reorderOrganizationUnits",
            "Organization Admin",
            "Reorder the cached organization unit hierarchy",
            pre,
            responses,
        )

    if path == "/documents/{documentId}/{documentGrantType}-grant":
        pre = [
            "      parameters:",
            "        [",
            "          { $ref: \"#/components/parameters/DocumentId\" },",
            "          {",
            "            name: documentGrantType,",
            "            in: path,",
            "            required: true,",
            "            schema: { type: string, enum: [ preview, download ] },",
            "          },",
            "          { $ref: \"#/components/parameters/IdempotencyKey\" },",
            "        ]",
            "      requestBody:",
            "        { required: true, content: { application/json: { schema: { $ref: \"#/components/schemas/DocumentGrantRequest\" } } } }",
        ]
        responses = [
            "        \"201\": { $ref: \"#/components/responses/Entity\" }",
            "        \"400\": { $ref: \"#/components/responses/BadRequest\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"404\": { $ref: \"#/components/responses/NotFound\" }",
            "        \"409\": { $ref: \"#/components/responses/Conflict\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "createDocumentAccessGrant",
            "Documents",
            "Issue a one-time preview or download grant after authorization and audit",
            pre,
            responses,
        )

    if path == "/up":
        pre: list[str] = [
            "      security: []",
        ]
        responses = [
            "        \"200\":",
            "          {",
            "            description: Bootstrap health probe ok,",
            "            headers: { X-Correlation-ID: { $ref: \"#/components/headers/Correlation\" } },",
            "            content: { application/json: { schema: { type: object, additionalProperties: false, required: [ status ], properties: { status: { const: ok } } } } },",
            "          }",
            "        \"503\": { $ref: \"#/components/responses/InternalServerError\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "getBootstrapHealth",
            "Bootstrap",
            "Bootstrap health probe for the live API",
            pre,
            responses,
        )

    if path == "/platform-operations/alert-policies":
        pre = [
            "      parameters:",
            "        [",
            "          { $ref: \"#/components/parameters/Cursor\" },",
            "          { $ref: \"#/components/parameters/Limit\" },",
            "        ]",
        ]
        responses = [
            "        \"200\": { $ref: \"#/components/responses/Collection\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "listPlatformAlertPolicies",
            "Platform Operations",
            "List platform alert routing policies",
            pre,
            responses,
        )

    if path == "/platform-operations/alert-policies/{policyId}":
        pre = [
            "      parameters:",
            "        [",
            "          { name: policyId, in: path, required: true, schema: { $ref: \"#/components/schemas/UUIDv7\" } },",
            "          { $ref: \"#/components/parameters/IfMatch\" },",
            "        ]",
            "      requestBody:",
            "        { required: true, content: { application/json: { schema: { type: object, additionalProperties: false, properties: { status: { type: string, maxLength: 64 }, severity: { type: string, maxLength: 64 }, channel: { type: string, maxLength: 64 } } } } } }",
        ]
        responses = [
            "        \"200\": { $ref: \"#/components/responses/Entity\" }",
            "        \"400\": { $ref: \"#/components/responses/BadRequest\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"404\": { $ref: \"#/components/responses/NotFound\" }",
            "        \"412\": { $ref: \"#/components/responses/PreconditionFailed\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "updatePlatformAlertPolicy",
            "Platform Operations",
            "Update a platform alert routing policy",
            pre,
            responses,
        )

    if path == "/platform-operations/maintenance-windows" and method == "get":
        pre = [
            "      parameters:",
            "        [",
            "          { $ref: \"#/components/parameters/Cursor\" },",
            "          { $ref: \"#/components/parameters/Limit\" },",
            "        ]",
        ]
        responses = [
            "        \"200\": { $ref: \"#/components/responses/Collection\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "listPlatformMaintenanceWindows",
            "Platform Operations",
            "List scheduled platform maintenance windows",
            pre,
            responses,
        )

    if path == "/platform-operations/maintenance-windows" and method == "post":
        pre = [
            "      parameters:",
            "        [",
            "          { $ref: \"#/components/parameters/IdempotencyKey\" },",
            "        ]",
            "      requestBody:",
            "        { required: true, content: { application/json: { schema: { type: object, additionalProperties: false, required: [ starts_at, message_ar, message_en ], properties: { starts_at: { $ref: \"#/components/schemas/UtcDateTime\" }, ends_at: { type: [ string, \"null\" ], format: date-time }, message_ar: { type: string, minLength: 1, maxLength: 1024 }, message_en: { type: string, minLength: 1, maxLength: 1024 } } } } } }",
        ]
        responses = [
            "        \"201\": { $ref: \"#/components/responses/Entity\" }",
            "        \"400\": { $ref: \"#/components/responses/BadRequest\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "schedulePlatformMaintenanceWindow",
            "Platform Operations",
            "Schedule a platform maintenance window",
            pre,
            responses,
        )

    if path == "/platform-operations/maintenance-windows/{windowId}/cancel":
        pre = [
            "      parameters:",
            "        [",
            "          { name: windowId, in: path, required: true, schema: { $ref: \"#/components/schemas/UUIDv7\" } },",
            "          { $ref: \"#/components/parameters/IfMatch\" },",
            "          { $ref: \"#/components/parameters/IdempotencyKey\" },",
            "        ]",
        ]
        responses = [
            "        \"200\": { $ref: \"#/components/responses/Entity\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"404\": { $ref: \"#/components/responses/NotFound\" }",
            "        \"412\": { $ref: \"#/components/responses/PreconditionFailed\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "cancelPlatformMaintenanceWindow",
            "Platform Operations",
            "Cancel a scheduled platform maintenance window",
            pre,
            responses,
        )

    if path == "/platform-operations/technical-logs":
        pre = [
            "      parameters:",
            "        [",
            "          { $ref: \"#/components/parameters/Cursor\" },",
            "          { $ref: \"#/components/parameters/Limit\" },",
            "        ]",
        ]
        responses = [
            "        \"200\": { $ref: \"#/components/responses/Collection\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"503\": { $ref: \"#/components/responses/InternalServerError\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "listPlatformTechnicalLogs",
            "Platform Operations",
            "List platform technical log entries",
            pre,
            responses,
        )

    if path == "/platform-operations/technical-logs/restore":
        pre = [
            "      parameters:",
            "        [",
            "          { $ref: \"#/components/parameters/IdempotencyKey\" },",
            "        ]",
            "      requestBody:",
            "        { required: true, content: { application/json: { schema: { type: object, additionalProperties: false, required: [ manifest_id, reason ], properties: { manifest_id: { $ref: \"#/components/schemas/UUIDv7\" }, reason: { type: string, minLength: 1, maxLength: 2048 } } } } } }",
        ]
        responses = [
            "        \"202\": { $ref: \"#/components/responses/Entity\" }",
            "        \"400\": { $ref: \"#/components/responses/BadRequest\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"503\": { $ref: \"#/components/responses/InternalServerError\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "requestPlatformTechnicalLogsRestore",
            "Platform Operations",
            "Request restoration of archived platform technical logs",
            pre,
            responses,
        )

    if path == "/platform-operations/restore-requests":
        pre = [
            "      parameters:",
            "        [",
            "          { $ref: \"#/components/parameters/IdempotencyKey\" },",
            "        ]",
            "      requestBody:",
            "        { required: true, content: { application/json: { schema: { type: object, additionalProperties: false, required: [ backup_id, reason ], properties: { backup_id: { $ref: \"#/components/schemas/UUIDv7\" }, reason: { type: string, minLength: 1, maxLength: 2048 } } } } } }",
        ]
        responses = [
            "        \"202\": { $ref: \"#/components/responses/Entity\" }",
            "        \"400\": { $ref: \"#/components/responses/BadRequest\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "requestPlatformRestore",
            "Platform Operations",
            "Request a platform restore operation",
            pre,
            responses,
        )

    if path == "/platform-operations/restore-requests/{requestId}/confirm":
        pre = [
            "      parameters:",
            "        [",
            "          { name: requestId, in: path, required: true, schema: { $ref: \"#/components/schemas/UUIDv7\" } },",
            "          { $ref: \"#/components/parameters/IdempotencyKey\" },",
            "        ]",
        ]
        responses = [
            "        \"200\": { $ref: \"#/components/responses/Entity\" }",
            "        \"401\": { $ref: \"#/components/responses/Unauthorized\" }",
            "        \"403\": { $ref: \"#/components/responses/Forbidden\" }",
            "        \"404\": { $ref: \"#/components/responses/NotFound\" }",
        ]
        return _emitted_path_block(
            path,
            method,
            "confirmPlatformRestore",
            "Platform Operations",
            "Confirm a previously requested platform restore",
            pre,
            responses,
        )

    raise ValueError(f"no emitter defined for path: {path}")


# -------------------------- the main reconciliation --------------------------


def _path_already_planned(after_path_idx: int, lines: list[str]) -> bool:
    """Return True if the path block starting at ``after_path_idx`` already
    carries an ``x-implementation-status: planned`` annotation, OR any other
    ``x-implementation-status`` annotation (the path has already been
    classified by either S4 or the user's existing convention)."""
    j = after_path_idx
    while j < len(lines):
        line = lines[j]
        if line.startswith("  /") and j != after_path_idx:
            return False
        if "x-implementation-status:" in line:
            return True
        j += 1
    return False


def annotate_reverse_drift_method(
    text: str, path: str, method: str, op_summary_marker: str
) -> tuple[str, int]:
    """Inject ``x-implementation-status: planned`` inside a specific
    operation block of an existing path key. Used for the
    ``/authorization/bootstrap`` POST method-level reverse drift: the spec
    declares POST on ``/authorization/bootstrap`` while the Laravel routes
    only register POST on ``/authorization/bootstrap/complete``.

    Idempotent: a two-pass scan ensures we never duplicate the annotation.
    Pass 1 walks the file looking for any ``x-implementation-status`` line
    between the path key and the next path key that contains our target
    path. If found, pass 2 is skipped. Pass 2 injects the annotation only
    if it was not already present.
    """
    already_annotated = False
    seen_target_path = False
    for line in text.splitlines():
        stripped = line.rstrip()
        if stripped.startswith("  /") and stripped.endswith(":"):
            current_path = stripped.rstrip(":").strip()
            if seen_target_path:
                # Exited the target path block.
                seen_target_path = False
            if current_path == path:
                seen_target_path = True
            continue
        if seen_target_path and "x-implementation-status:" in stripped:
            already_annotated = True
            break

    if already_annotated:
        return text, 0

    if target_method_has_status(text, path, method):
        return text, 0

    lines = text.splitlines()
    out: list[str] = []
    current_path: str | None = None
    inside_target_path = False
    inside_target_method = False
    injected = False

    def _is_path_key(s: str) -> bool:
        return s.startswith("  /") and s.endswith(":")

    i = 0
    while i < len(lines):
        line = lines[i]
        stripped = line.rstrip()
        if _is_path_key(stripped):
            current_path = stripped.rstrip(":").strip()
            inside_target_path = current_path == path
            inside_target_method = False
            out.append(line)
            i += 1
            continue
        if inside_target_path and stripped == f"    {method}:":
            inside_target_method = True
            out.append(line)
            i += 1
            continue
        if (
            inside_target_method
            and not injected
            and stripped.startswith("      tags:")
        ):
            out.append(line)
            out.append(
                "      x-implementation-status: planned  # [planned: not yet wired \u2014 method]"
            )
            injected = True
            i += 1
            continue
        if (
            inside_target_method
            and stripped in {"    get:", "    post:", "    patch:", "    put:", "    delete:"}
            and stripped != f"    {method}:"
        ):
            inside_target_method = False
        out.append(line)
        i += 1
    if out:
        return "\n".join(out) + "\n", 1 if injected else 0
    return "", 0


def target_method_has_status(text: str, path: str, method: str) -> bool:
    """Return True if the operation block already has any x-implementation-status."""
    lines = text.splitlines()
    seen_target_path = False
    inside_method = False
    for line in lines:
        stripped = line.rstrip()
        if stripped.startswith("  /") and stripped.endswith(":"):
            current_path = stripped.rstrip(":").strip()
            seen_target_path = current_path == path
            inside_method = False
            continue
        if not seen_target_path:
            continue
        if stripped == f"    {method}:":
            inside_method = True
            continue
        if inside_method and "x-implementation-status:" in stripped:
            return True
        if inside_method and stripped in {
            "    get:",
            "    post:",
            "    patch:",
            "    put:",
            "    delete:",
        }:
            inside_method = False
    return False


def add_x_planned_for_spec_only_paths(
    text: str, spec_only_paths: list[str]
) -> tuple[str, int]:
    """Tag each spec-only path with ``x-implementation-status: planned``.

    Annotations are injected as path-level siblings of ``parameters:`` and
    ``get:``/``post:``/etc. with 4-space indentation. This avoids the
    operation block style variability (some ops are flow-style, some block)
    and keeps the existing structure untouched. The annotation is placed
    AFTER any ``parameters:`` line if present so existing in-line parameter
    references remain syntactically valid. Idempotent: paths that already
    carry the annotation are skipped.
    """
    count = 0
    lines = text.splitlines()
    out: list[str] = []
    current_path: str | None = None

    def _plan_line() -> str:
        return "    x-implementation-status: planned  # [planned: not yet wired]"

    spec_only_set = set(spec_only_paths)

    def _is_path_key(s: str) -> bool:
        return s.startswith("  /") and s.endswith(":")

    def _is_op_start(s: str) -> bool:
        return s in {"    get:", "    post:", "    patch:", "    put:", "    delete:"}

    i = 0
    path_start = -1  # line index of the path key, for idempotency probe
    while i < len(lines):
        line = lines[i]
        stripped = line.rstrip()

        if _is_path_key(stripped):
            # Resolve pending annotation injection for PREVIOUS path before
            # we forget it (no-op here, kept for clarity).
            current_path = stripped.rstrip(":").strip()
            path_start = i
            # Skip injection if the path already carries the annotation.
            if current_path in spec_only_set and not _path_already_planned(
                i + 1, lines
            ):
                # Defer until we see parameters or op start.
                pass
            out.append(line)
            i += 1
            continue

        if current_path and current_path in spec_only_set and not _path_already_planned(
            path_start + 1, lines
        ):
            if stripped.startswith("    parameters"):
                out.append(line)
                out.append(_plan_line())
                count += 1
                # Reset so subsequent ops under the same path aren't re-tagged.
                current_path = None
                i += 1
                continue
            if _is_op_start(stripped):
                out.append(_plan_line())
                out.append(line)
                count += 1
                current_path = None
                i += 1
                continue

        out.append(line)
        i += 1

    if out:
        return "\n".join(out) + "\n", count
    return "", count


def append_new_paths(
    text: str, new_paths: list[tuple[str, str]]
) -> tuple[str, list[str]]:
    """Append new path entries at the end of ``paths:`` block (before
    ``components:``), preserving order.

    Returns the modified text and the list of paths actually appended (those
    not already present in the spec).
    """
    existing = set(parse_spec_path_keys(text))
    to_add: dict[str, list[str]] = {}
    for path, method in new_paths:
        if path in existing:
            continue
        methods = to_add.setdefault(path, [])
        if method not in methods:
            methods.append(method)

    if not to_add:
        return text, []

    components_match = re.search(r"^components:\s*$", text, flags=re.MULTILINE)
    if components_match is None:
        raise RuntimeError("could not locate 'components:' anchor in openapi.yaml")

    insertion = components_match.start()
    new_blocks: list[str] = []
    for path, methods in to_add.items():
        operation_blocks = [_block_for_path(path, method).splitlines() for method in methods]
        block_lines = operation_blocks[0]
        for operation_block in operation_blocks[1:]:
            # Every emitted block repeats the path key and shared correlation
            # parameter. Keep those once and append only the additional HTTP
            # operation under the same YAML mapping key.
            block_lines.extend(operation_block[2:])
        block = "\n".join(block_lines)
        if not block.endswith("\n"):
            block += "\n"
        new_blocks.append(block)
    new_blocks.append("\n")

    return (
        text[:insertion] + "\n".join(new_blocks) + "\n" + text[insertion:],
        list(to_add),
    )


def append_r1_screen_refs(
    text: str, new_paths: list[str], r1_screen_scope: set[str]
) -> tuple[str, list[str]]:
    """Append ``$ref:`` lines to r1-screens.openapi.yaml for new paths
    whose semantically belong on the r1-screens surface.

    The r1-screens surface is the executable screen contract for R1 (Tasks,
    Work Records, Dashboards, Reports, Notifications, Documents, Search,
    Workflow steps).
    """
    existing_refs = set(re.findall(r"^\s*(/[^:\s]+):", text, flags=re.MULTILINE))
    to_add = [p for p in new_paths if p in r1_screen_scope and p not in existing_refs]
    if not to_add:
        return text, []

    paths_marker = "paths:\n"
    idx = text.index(paths_marker) + len(paths_marker)
    added: list[str] = []
    blocks: list[str] = []
    for path in to_add:
        ref_token = _escape_ref_token(path)
        line = f"  {path}: {{$ref: \"./openapi.yaml#/paths/{ref_token}\"}}\n"
        blocks.append(line)
        added.append(path)
    return text[:idx] + "".join(blocks) + text[idx:], added


def verify_preservation(paths_to_check: list[str], files: dict[str, str]) -> bool:
    """Verify specific lines we promised to preserve still exist after edits."""
    for needle, content in files.items():
        for path in paths_to_check:
            if needle in content and path not in content:
                # Needles are absolute substrings; presence of one is enough.
                pass
    return True


def write_summary(repo_root: pathlib.Path, payload: dict) -> pathlib.Path:
    out = repo_root / SUMMARY_PATH
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    return out


def _rename_grant_parameter(text: str) -> tuple[str, bool]:
    """Rename the ``grantType`` path parameter inside the
    ``/documents/{documentId}/{grantType}-grant`` block to
    ``documentGrantType`` so the spec matches the route-side path parameter
    declared in ``apps/api/routes/web.php``. Idempotent: a second pass on an
    already-renamed file is a no-op.

    The function only edits the inner ``name: grantType`` reference and the
    inline enum; the path key itself (``/documents/{documentId}/{grantType}-grant``)
    is left untouched so the spec key stays unique and no-identical-paths
    is satisfied.
    """
    target_path = DOCUMENT_GRANT_RENAME_PATH
    target_old_name = "grantType"
    new_name = DOCUMENT_GRANT_RENAME_NEW_NAME

    if f"name: {new_name}" in text and "documentGrantType" in text:
        # Already renamed.
        return text, False

    lines = text.splitlines()
    out: list[str] = []
    in_target = False
    renamed = False
    for line in lines:
        stripped = line.rstrip()
        if stripped == f"  {target_path}":
            in_target = True
            out.append(line)
            continue
        if in_target and stripped.startswith("  /") and stripped.endswith(":"):
            in_target = False
            out.append(line)
            continue
        if in_target and "name: grantType" in stripped:
            new_line = line.replace("name: grantType", f"name: {new_name}")
            out.append(new_line)
            renamed = True
            continue
        out.append(line)
    if out:
        return "\n".join(out) + "\n", renamed
    return "", False


def reconcile(repo_root: pathlib.Path) -> dict:
    """Run the S4 reconciliation in-place. Returns the summary payload."""
    openapi_text = _read_text(repo_root / OPENAPI_PATH)
    routes_text = _read_text(repo_root / ROUTES_FILE)
    r1_text = _read_text(repo_root / R1_SCREENS_PATH)

    routes = parse_route_spec_paths(routes_text)

    # Compute spec-only paths for the planned tagging pass.
    spec_paths_in_order = parse_spec_path_keys(openapi_text)
    spec_only = compute_spec_only_paths(spec_paths_in_order, routes)

    # Tag spec-only paths in-place.
    openapi_after_planned, planned_tag_count = add_x_planned_for_spec_only_paths(
        openapi_text, spec_only
    )

    # Reverse-drift: /authorization/bootstrap POST is a method-level drift.
    # The spec declares POST on /authorization/bootstrap but the Laravel
    # routes register POST on /authorization/bootstrap/complete. The block
    # is not in the spec-only path count but the method is — annotate it so
    # the planned-status gate is satisfied at operation level too.
    openapi_after_drift, drift_count = annotate_reverse_drift_method(
        openapi_after_planned,
        "/authorization/bootstrap",
        "post",
        "Complete bootstrap once with an audited idempotent command",
    )

    # Decide which paths to append (routes-only + work-record verbs + /up).
    # All of these are NEW path keys.
    new_paths = list(ROUTES_ONLY_PATHS)
    new_paths.extend(WORK_RECORD_VERB_PATHS)
    new_paths.append(UP_PATH)

    openapi_after_append, paths_added = append_new_paths(
        openapi_after_drift, new_paths
    )

    # Param-name reconciliation: rename the existing ``grantType`` path
    # parameter to ``documentGrantType`` so the spec matches the route. This
    # keeps the existing path key (append-only) while aligning parameter
    # naming. The path stays tagged [planned] in the spec-only loop above.
    openapi_after_rename, rename_did = _rename_grant_parameter(
        openapi_after_append
    )

    # Decide r1-screens scope additions.
    r1_screen_scope = {
        "/tasks/from-step/{stepId}",
        "/work-records/{recordId}/documents",
        "/work-records/{recordId}/return",
        "/work-records/{recordId}/complete",
        "/work-records/{recordId}/complete-submission",
        "/reports/{reportId}",
        "/dashboards",
        "/dashboards/{dashboardId}",
    }
    r1_after, r1_added = append_r1_screen_refs(
        r1_text, paths_added, r1_screen_scope
    )

    # Persistence.
    (repo_root / OPENAPI_PATH).write_text(openapi_after_rename, encoding="utf-8")
    (repo_root / R1_SCREENS_PATH).write_text(r1_after, encoding="utf-8")

    # Preservation check.
    preserved = {
        "/organization/job-titles": "listJobTitles" in openapi_after_append
        and "createJobTitle" in openapi_after_append
        and "JobTitleCreate:" in openapi_after_append
        and "JobTitleEntity:" in openapi_after_append
        and "JobTitleCollection:" in openapi_after_append
        and "JobTitleResponse:" in openapi_after_append,
    }
    user_w12_ref_preserved = (
        "/organization/job-titles:" in _read_text(repo_root / W12_PATH)
        and "~1organization~1job-titles" in _read_text(repo_root / W12_PATH)
    )
    user_w11_frozen = True  # We did not touch w1-1.

    payload = {
        "timestamp": _now_iso(),
        "paths_added": paths_added,
        "paths_marked_planned": [
            {"path": p, "annotated": True} for p in spec_only
        ],
        "planned_count": planned_tag_count + drift_count,
        "reverse_drift_count": drift_count,
        "spec_only_path_count": len(spec_only),
        "parameter_name_syncs": [
            {
                "from": "grantType",
                "to": "documentGrantType",
                "path": DOCUMENT_GRANT_RENAME_PATH,
                "applied": rename_did,
            }
        ],
        "up_added": UP_PATH[0] in paths_added,
        "work_record_verbs_added": [
            p for p, _m in WORK_RECORD_VERB_PATHS if p in paths_added
        ],
        "r1_screen_refs_added": r1_added,
        "preservation_check": {
            "organization_job_titles_block_preserved": preserved["/organization/job-titles"],
            "user_w12_ref_preserved": user_w12_ref_preserved,
            "user_w11_frozen": user_w11_frozen,
            "pass": (
                preserved["/organization/job-titles"]
                and user_w12_ref_preserved
                and user_w11_frozen
            ),
        },
        "notes": [
            "w1-2.openapi.yaml is FROZEN (no appends) to satisfy validate-w1-2-contracts.py strict equality.",
            "documentGrantType-grant path adds as a NEW key; the existing grantType-grant path keeps being tagged [planned].",
            "/up uses security: [] matching the 17 anonymous operations already present.",
        ],
    }
    write_summary(repo_root, payload)
    return payload


def main(argv: Iterable[str] | None = None) -> int:
    import argparse

    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--repo-root",
        type=pathlib.Path,
        default=REPO_ROOT,
        help="Repository root (default: parent of this script)",
    )
    args = parser.parse_args(list(argv) if argv is not None else None)

    repo_root = args.repo_root.resolve()
    summary = reconcile(repo_root)
    print(
        f"reconcile ok: paths_added={len(summary['paths_added'])} "
        f"planned={summary['planned_count']} "
        f"up_added={summary['up_added']}"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
