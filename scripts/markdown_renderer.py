"""Render the live Laravel route inventory as Markdown."""

from __future__ import annotations

import datetime as dt
import pathlib
import re
from collections import defaultdict

from rbac import build_matrix, parse_routes


IMPORT_RE = re.compile(r"^use\s+([^;]+);$", re.MULTILINE)
STATUS_RE = re.compile(r"(?:response\(\)->json|::response|->response|\$this->response)\([^;]*?,\s*(\d{3})(?:\s*[,\)])", re.DOTALL)

ERROR_SITES = [
    ("HttpSupport::problem", "apps/api/app/Http/Controllers/Api/HttpSupport.php:51-62", "RFC 7807-style `application/problem+json` envelope."),
    ("IdentityApi::problem", "apps/api/Modules/Identity/Http/IdentityApi.php:52-66", "Identity problem envelope with correlation metadata."),
    ("OrganizationApi::problem", "apps/api/Modules/Organization/Http/OrganizationApi.php:52-67", "Organization problem envelope with correlation metadata."),
    ("AuthorizationApi::problem", "apps/api/Modules/Authorization/Http/AuthorizationApi.php", "Authorization problem envelope with correlation metadata."),
    ("ReportingApi::problem", "apps/api/Modules/Reporting/Http/ReportingApi.php", "Reporting problem envelope with correlation metadata."),
    ("SearchApi::problem", "apps/api/Modules/Search/Http/SearchApi.php", "Search problem envelope with correlation metadata."),
    ("LinkDocumentController about:blank outliers", "apps/api/app/Http/Controllers/Api/LinkDocumentController.php:24,29,31", "Legacy `about:blank` responses for unauthorized, invalid link, and unavailable document outcomes."),
]

SPECIAL_PATHS = [
    "/up",
    "/reports",
    "/reports/{reportId}",
    "/reports/{reportId}/exports",
    "/exports/{exportId}",
    "/dashboards",
    "/dashboards/{dashboardId}",
    "/internal/documents/versions/{versionId}/scan",
    "/internal/documents/versions/{versionId}/reconcile-promotion",
]


def _imports(routes_text: str) -> dict[str, str]:
    imports: dict[str, str] = {}
    for fqcn in IMPORT_RE.findall(routes_text):
        imports[fqcn.rsplit("\\", 1)[-1]] = fqcn
    return imports


def _controller_fqcn(controller: str | None, imports: dict[str, str]) -> str:
    if controller is None:
        return "Closure"
    if "\\" in controller:
        return controller
    return imports.get(controller, controller)


def _controller_file(repo_root: pathlib.Path, fqcn: str) -> pathlib.Path | None:
    if fqcn.startswith("App\\"):
        candidate = repo_root / "apps/api/app" / pathlib.Path(*fqcn.removeprefix("App\\").split("\\"))
    elif fqcn.startswith("Modules\\"):
        candidate = repo_root / "apps/api" / pathlib.Path(*fqcn.split("\\"))
    else:
        return None
    candidate = candidate.with_suffix(".php")
    return candidate if candidate.exists() else None


def _operation_key(method: str, path: str) -> str:
    value = f"{method.lower()}_{path.strip('/')}"
    value = re.sub(r"[^a-zA-Z0-9]+", "_", value).strip("_")
    return value or method.lower()


def _summary(path: str, method: str) -> str:
    resource = path.removeprefix("/api/v1/").replace("-", " ")
    action = {
        "GET": "Retrieve",
        "POST": "Create or execute",
        "PATCH": "Update",
        "PUT": "Replace",
        "DELETE": "Delete",
    }.get(method, "Access")
    return f"{action} {resource}."


def _module_name(path: str) -> str:
    relative = path.removeprefix("/api/v1/")
    first = relative.split("/", 1)[0]
    aliases = {"auth": "Identity", "me": "Identity", "exports": "Reporting", "dashboards": "Reporting", "reports": "Reporting"}
    return aliases.get(first, first.replace("-", " ").title())


def _status_codes(repo_root: pathlib.Path, fqcn: str, method: str) -> list[int]:
    controller_file = _controller_file(repo_root, fqcn)
    statuses: set[int] = set()
    if controller_file is not None:
        text = controller_file.read_text(encoding="utf-8")
        statuses.update(int(value) for value in STATUS_RE.findall(text))
        statuses.update(int(value) for value in re.findall(r"\b(?:status|problem)\s*[:(,]\s*(\d{3})\b", text))
    if not statuses:
        statuses.add(201 if method == "POST" else 200)
    return sorted(statuses)


def _source_pointer(repo_root: pathlib.Path, fqcn: str, method: str | None) -> str:
    controller_file = _controller_file(repo_root, fqcn)
    if controller_file is None:
        return "controller source unresolved"
    relative = controller_file.relative_to(repo_root).as_posix()
    return f"{relative}{f'::{method}' if method else ''}"


def _row_pointer(row: dict) -> str:
    return f"rbac-matrix.md#row-{row['source_line']}"


def _front_matter(
    *,
    doc_id: str,
    title: str,
    doc_type: str,
    owner: str,
    reviewers: list[str],
    sources: list[str],
    references: list[str],
    review_cycle: str,
) -> str:
    lines = [
        "---",
        f"doc_id: {doc_id}",
        f"title: {title}",
        f"type: {doc_type}",
        "status: accepted",
        "version: 1.0.0",
        f"date: {dt.date.today().isoformat()}",
        f"owner: {owner}",
        "reviewers:",
    ]
    lines.extend(f"  - {reviewer}" for reviewer in reviewers)
    lines.extend([
        "classification: internal",
        f"review_cycle: {review_cycle}",
        "sources:",
    ])
    lines.extend(f"  - {source}" for source in sources)
    lines.append("references:")
    lines.extend(f"  - {reference}" for reference in references)
    lines.append("---")
    return "\n".join(lines)


def render_markdown(repo_root: pathlib.Path, summary) -> str:
    routes_text = (repo_root / "apps/api/routes/web.php").read_text(encoding="utf-8")
    statements = parse_routes(routes_text)
    matrix = build_matrix(repo_root, summary)
    rows = matrix["rows"]
    if len(statements) != len(rows):
        raise ValueError(f"route/RBAC mismatch: {len(statements)} routes, {len(rows)} rows")

    imports = _imports(routes_text)
    grouped: dict[str, list[tuple[object, dict]]] = defaultdict(list)
    for statement, row in zip(statements, rows):
        grouped[_module_name(statement.path)].append((statement, row))

    lines = [
        "# Backend Endpoint Inventory",
        "",
        "> Generated from `apps/api/routes/web.php`; do not edit endpoint cards by hand.",
        "",
        "## Overview",
        "",
        f"This inventory documents {len(statements)} live `Route::` declarations plus the bootstrap health route.",
        "Laravel routes are the runtime source of truth. The canonical contract is `docs/contracts/api/openapi.yaml`.",
        "Arabic summaries remain as inline placeholders for the dedicated translation slice.",
        "",
        "## Module Sections",
        "",
    ]

    for module in sorted(grouped):
        lines.extend([f"**{module}**", ""])
        for statement, row in grouped[module]:
            fqcn = _controller_fqcn(statement.controller, imports)
            operation_key = _operation_key(statement.method, statement.path)
            status_codes = _status_codes(repo_root, fqcn, statement.method)
            middleware = " → ".join(row["middleware"]) or "none"
            controller_method = f"::{statement.controller_method}" if statement.controller_method else ""
            lines.extend(
                [
                    f"### `{statement.method} {statement.path}`",
                    "",
                    f"- **Summary (EN / AR):** {_summary(statement.path, statement.method)} `{{{{AR:{operation_key}}}}}`",
                    f"- **Operation key:** `{operation_key}`",
                    f"- **Middleware chain:** `{middleware}`",
                    f"- **CSRF required:** `{'yes' if row['requires_csrf'] else 'no'}`",
                    f"- **RBAC row:** [`{row['endpoint_tag']}`]({_row_pointer(row)}); principal required: `{'yes' if row['requires_principal'] else 'no'}`.",
                    f"- **Request `$ref`:** `#/components/schemas/{operation_key}_request` (schema placeholder).",
                    f"- **Response `$ref`:** `#/components/schemas/{operation_key}_response` (schema placeholder).",
                    f"- **Status codes:** `{', '.join(str(code) for code in status_codes)}`.",
                    f"- **Throttle:** `{row['throttle'] or 'default / none declared'}`.",
                    f"- **Controller FQCN:** `{fqcn}{controller_method}`.",
                    f"- **Controller source:** `{_source_pointer(repo_root, fqcn, statement.controller_method)}`.",
                    f"- **OpenAPI pointer:** `docs/contracts/api/openapi.yaml#paths.{statement.path}.{statement.method.lower()}`.",
                    f"- **Route source:** `apps/api/routes/web.php:{statement.line_number}`.",
                    "",
                ]
            )

    lines.extend(
        [
            "## RBAC Matrix",
            "",
            "The detailed row pointer on each card corresponds to the generated `rbac-matrix.json` entry.",
            "",
            "| Method | Path | Middleware | Session | Principal | CSRF | Throttle |",
            "|---|---|---|---:|---:|---:|---|",
        ]
    )
    for row in rows:
        lines.append(
            f"| {row['method']} | `{row['path']}` | `{' → '.join(row['middleware']) or 'none'}` | "
            f"{'yes' if row['requires_session'] else 'no'} | {'yes' if row['requires_principal'] else 'no'} | "
            f"{'yes' if row['requires_csrf'] else 'no'} | `{row['throttle'] or 'none'}` |"
        )

    lines.extend(["", "## Error Catalog", ""])
    for name, pointer, description in ERROR_SITES:
        lines.extend([f"- **{name}** — `{pointer}`", f"  - {description}"])

    lines.extend(
        [
            "",
            "## Exports / Internal / Health",
            "",
            "These operational and reporting surfaces are called out separately from their endpoint cards.",
            "",
        ]
    )
    for path in SPECIAL_PATHS:
        if path == "/up":
            lines.append("- `/up` — Laravel bootstrap health endpoint; OpenAPI `security: []`.")
        elif path.startswith("/internal/"):
            lines.append(f"- `{path}` (`/api/v1{path}`) — internal worker route; throttle `60,1` and no identity session middleware.")
        else:
            lines.append(f"- `{path}` (`/api/v1{path}`) — reporting/export surface; see its endpoint card and RBAC row.")

    lines.extend(
        [
            "",
            "## Regeneration & Orval & Coverage",
            "",
            "### Regeneration",
            "",
            "Run the inventory and contract checks from the repository root:",
            "",
            "```shell",
            "python3 scripts/inventory-routes.py --mode md --json docs/api",
            "./scripts/validate-docs.sh",
            "npm --prefix apps/web run api:generate",
            "npm --prefix apps/web run api:check",
            "```",
            "",
            "### Orval",
            "",
            "`apps/web/orval.config.ts` generates one client directly from the single authoritative contract",
            "`docs/contracts/api/openapi.yaml`.",
            "",
            "### Coverage",
            "",
            f"- Live route declarations represented by cards: {len(statements)} / {len(statements)}.",
            "- Bootstrap-only health route represented in the dedicated operational section: `/up`.",
            "- Arabic summary placeholders intentionally remain for S6.",
            "",
            "### Contract Source",
            "",
            "- `docs/contracts/api/openapi.yaml` is the single authoritative API contract.",
            "- Operations tagged `x-implementation-status: planned` are documented but not live; every untagged operation must be wired.",
            "- The previous real gap, `POST /api/v1/platform-operations/backups`, is now declared by the master contract as `dispatchPlatformBackup`; it returns an asynchronous entity (`202`) and is owned by `platform_operations.backup.run`.",
            "- `GET /api/v1/platform-operations/backups` remains a single backup-status entity owned by `platform_operations.backup.read`; it is not a collection route.",
            "- Source evidence: `apps/api/routes/web.php` and `docs/contracts/api/openapi.yaml`.",
            "",
        ]
    )
    body = "\n".join(lines)
    return _front_matter(
        doc_id="API-INV-001",
        title="Backend Endpoint Inventory",
        doc_type="engineering",
        owner="مكتب هندسة البرمجيات",
        reviewers=["مكتب هندسة المنصة", "مسؤول أمن المعلومات"],
        sources=["docs/contracts/api/openapi.yaml", "docs/api/rbac-matrix.md"],
        references=["docs/contracts/api/openapi.yaml", "docs/api/rbac-matrix.md"],
        review_cycle="مع كل تغيير routes",
    ) + "\n" + body


def render_rbac_markdown(repo_root: pathlib.Path, summary) -> str:
    routes_text = (repo_root / "apps/api/routes/web.php").read_text(encoding="utf-8")
    statements = parse_routes(routes_text)
    matrix = build_matrix(repo_root, summary)
    rows = matrix["rows"]
    if len(statements) != len(rows):
        raise ValueError(f"route/RBAC mismatch: {len(statements)} routes, {len(rows)} rows")

    catalog = matrix["catalog"]
    counts = catalog["classification_counts"]
    lines = [
        "# RBAC Matrix",
        "",
        "> Generated by `python3 scripts/inventory-routes.py --mode rbac-md` from the live route registry, controller source, and `CapabilityCatalog`.",
        "",
        "## Capability catalog reconciliation",
        "",
        f"- **Actual runtime catalog count:** `{catalog['actual_count']}`",
        f"- **Historical F067 expectation:** `{catalog['historical_expected_count']}` — **mismatch; the runtime catalog is authoritative.**",
        f"- **Classifications:** `{counts['used']}` used, `{counts['intentional-ui-only']}` intentional UI-only, `{counts['deprecated']}` deprecated.",
        "- Generation fails if any catalog entry is duplicated or left unclassified.",
        "",
        "| Capability | Classification | Evidence |",
        "| --- | --- | --- |",
    ]
    for item in catalog["classifications"]:
        evidence = "<br>".join(f"`{value}`" for value in item["evidence"])
        lines.append(f"| `{item['capability']}` | `{item['classification']}` | {evidence} |")
    lines.extend(
        [
            "",
            "## Controllers without controller-local capability checks",
            "",
            "These controllers are flagged rather than assigned a fabricated capability. Some delegate authorization to a handler; the controller column remains intentionally unverified until the check is visible at the HTTP boundary.",
            "",
        ]
    )
    for controller in matrix["controllers_without_capability_checks"]:
        lines.append(f"- `{controller}`")
    lines.extend(["", "## Route matrix", ""])
    for row in rows:
        capability_summary = (
            ", ".join(row["capabilities"])
            if row["capabilities"]
            else "DYNAMIC — controller-local capability resolver"
            if row["capability_check"] == "controller-dynamic"
            else "UNVERIFIED — no controller-local capability check"
        )
        lines.extend(
            [
                f"### row-{row['source_line']}",
                "",
                f"- **Method:** `{row['method']}`",
                f"- **Path:** `{row['path']}`",
                f"- **Endpoint tag:** `{row['endpoint_tag']}`",
                f"- **Controller:** `{row['controller'] or 'unresolved'}`",
                f"- **Controller source:** `{row['controller_source'] or 'unresolved'}`",
                f"- **Capability:** `{capability_summary}`",
                f"- **Capability check:** `{row['capability_check']}`",
                f"- **Middleware:** `{' → '.join(row['middleware']) or 'none'}`",
                f"- **Session:** `{'yes' if row['requires_session'] else 'no'}`",
                f"- **Principal:** `{'yes' if row['requires_principal'] else 'no'}`",
                f"- **CSRF:** `{'yes' if row['requires_csrf'] else 'no'}`",
                f"- **Throttle:** `{row['throttle'] or 'none'}`",
                "",
            ]
        )
    body = "\n".join(lines)
    return _front_matter(
        doc_id="API-RBAC-001",
        title="RBAC Matrix",
        doc_type="engineering",
        owner="مكتب هندسة البرمجيات",
        reviewers=["مكتب هندسة المنصة", "مسؤول أمن المعلومات"],
        sources=["apps/api/routes/web.php", "apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php", "scripts/rbac.py"],
        references=["docs/api/endpoints.md", "docs/contracts/api/openapi.yaml"],
        review_cycle="مع كل تغيير routes",
    ) + "\n" + body


def write_rbac_markdown(repo_root: pathlib.Path, summary, output_dir: pathlib.Path) -> pathlib.Path:
    output_dir.mkdir(parents=True, exist_ok=True)
    target = output_dir / "rbac-matrix.md"
    target.write_text(render_rbac_markdown(repo_root, summary), encoding="utf-8")
    return target


def write_markdown(repo_root: pathlib.Path, summary, output_dir: pathlib.Path) -> pathlib.Path:
    output_dir.mkdir(parents=True, exist_ok=True)
    target = output_dir / "endpoints.md"
    target.write_text(render_markdown(repo_root, summary), encoding="utf-8")
    (output_dir / "rbac-matrix.md").write_text(render_rbac_markdown(repo_root, summary), encoding="utf-8")
    return target
