"""S2 RBAC matrix builder.

Reads `apps/api/routes/web.php`, builds a normalized route statement per
`Route::verb(...)`, then computes effective middleware = parent-group middleware
UNION inline `->middleware(...)` additions.
"""

from __future__ import annotations

import json
import pathlib
import re
import sys
from dataclasses import dataclass, field


ROUTE_DECL_RE = re.compile(
    r"""Route::(?P<verb>get|post|patch|put|delete)\(
        \s*
        (?P<quote>['\"])
        (?P<path>[^'"]+?)
        (?P=quote)""",
    re.VERBOSE,
)

GROUP_PREFIX_RE = re.compile(
    r"""Route::prefix\(\s*
        (?P<quote>['\"])
        (?P<prefix>[^'"]+)
        (?P=quote)
        \s*\)\s*->group""",
    re.VERBOSE,
)

GROUP_MIDDLEWARE_RE = re.compile(r"Route::middleware\(")

CONTROLLER_CLASS_RE = re.compile(r"([A-Z][A-Za-z0-9_\\]*Controller)::class")
CONTROLLER_ARRAY_RE = re.compile(r"\[([A-Z][A-Za-z0-9_\\]*Controller)::class,\s*'([a-zA-Z_][a-zA-Z0-9_]*)'\]")

INLINE_MIDDLEWARE_CALL_RE = re.compile(r"->middleware\(([^)]*)\)")
INLINE_WHEREIN_RE = re.compile(r"->whereIn\(\s*['\"]([a-zA-Z_][a-zA-Z0-9_]*)['\"]\s*,\s*(\[[^\]]*\])\s*\)")
INLINE_WITHOUT_MIDDLEWARE_RE = re.compile(r"->withoutMiddleware\(([^)]*)\)")

CLOSE_TOKEN_RE = re.compile(r"^\s*\}[\);]*\s*$")
CLOSE_CHAINED_RE = re.compile(r"^\s*\)\s*\}\s*->")
CAPABILITY_LITERAL_RE = re.compile(r"'([a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)+)'")




@dataclass
class GroupContext:
    prefixes: list[str] = field(default_factory=list)
    middleware: list[str] = field(default_factory=list)


@dataclass
class RouteStatement:
    method: str
    path: str
    line_number: int
    raw_statement: str
    controller: str | None
    controller_method: str | None
    inline_middleware: list[str]
    wherein: dict[str, list[str]]
    parent_middleware: list[str]
    prefix_chain: list[str]


@dataclass
class RbacRow:
    endpoint_tag: str
    method: str
    path: str
    controller: str | None
    controller_source: str | None
    controller_method: str | None
    capabilities: list[str]
    capability_check: str
    middleware: list[str]
    requires_session: bool
    requires_principal: bool
    requires_csrf: bool
    throttle: str | None
    security_warning: str | None
    source_line: int


def _strip_quoted(value: str) -> str:
    value = value.strip()
    if (value.startswith("'") and value.endswith("'")) or (value.startswith('"') and value.endswith('"')):
        return value[1:-1]
    return value


def _parse_middleware_list(payload: str) -> list[str]:
    payload = payload.strip()
    if not payload:
        return []

    items: list[str] = []
    if payload.startswith("["):
        if not payload.endswith("]"):
            payload += "]"
        inner = payload[1:-1]
        depth = 0
        buf = ""
        for ch in inner:
            if ch in "([{":
                depth += 1
                buf += ch
                continue
            if ch in ")]}":
                depth -= 1
                buf += ch
                continue
            if ch == "," and depth == 0:
                if buf.strip():
                    items.append(buf.strip())
                buf = ""
                continue
            buf += ch
        if buf.strip():
            items.append(buf.strip())
    else:
        cleaned = payload.rstrip(")").strip()
        if (cleaned.startswith("'") and cleaned.endswith("'")) or (cleaned.startswith('"') and cleaned.endswith('"')):
            cleaned = cleaned[1:-1]
        if cleaned:
            items.append(cleaned)

    normalized: list[str] = []
    for item in items:
        item = item.strip().rstrip(",").strip()
        if not item:
            continue
        if "::class" in item:
            cls_name = item.split("::")[0]
            normalized.append(cls_name.split("\\")[-1].strip(" '\"\t"))
        elif item.startswith("'") or item.startswith('"'):
            normalized.append(_strip_quoted(item))
        else:
            normalized.append(item)
    return normalized


def _parse_wherein_list(payload: str) -> list[str]:
    inner = payload.strip()
    if not (inner.startswith("[") and inner.endswith("]")):
        return []
    inner = inner[1:-1]
    items: list[str] = []
    depth = 0
    buf = ""
    for ch in inner:
        if ch in "([{":
            depth += 1
            buf += ch
            continue
        if ch in ")]}":
            depth -= 1
            buf += ch
            continue
        if ch == "," and depth == 0:
            items.append(_strip_quoted(buf))
            buf = ""
            continue
        buf += ch
    if buf.strip():
        items.append(_strip_quoted(buf))
    return [item for item in items if item]


def _extract_controller(statement: str) -> tuple[str | None, str | None]:
    array_match = CONTROLLER_ARRAY_RE.search(statement)
    if array_match:
        return array_match.group(1), array_match.group(2)
    class_match = CONTROLLER_CLASS_RE.search(statement)
    if class_match:
        return class_match.group(1), None
    return None, None


def _controller_short_name(controller: str | None, method: str | None) -> str:
    if not controller:
        return "unknown"
    name = controller.split("\\")[-1]
    if method:
        return f"{name}::{method}"
    return name


def parse_routes(text: str) -> list[RouteStatement]:
    statements: list[RouteStatement] = []
    lines = text.splitlines()

    contexts: list[GroupContext] = []
    i = 0
    while i < len(lines):
        line = lines[i]

        if GROUP_PREFIX_RE.search(line):
            prefix_match = GROUP_PREFIX_RE.search(line)
            contexts.append(GroupContext(prefixes=[prefix_match.group("prefix")]))
            i += 1
            continue
        if GROUP_MIDDLEWARE_RE.search(line):
            middleware_list = _collect_group_middleware(lines, i)
            # Add middleware to the parent context (replace, not accumulate across siblings)
            contexts.append(GroupContext(middleware=middleware_list))
            i = _skip_group_open(lines, i)
            continue

        route_match = ROUTE_DECL_RE.search(line)
        if route_match:
            statement, consumed = _consume_route_statement(lines, i, route_match, contexts)
            statements.append(statement)
            i += consumed
            continue

        if CLOSE_TOKEN_RE.match(line) or CLOSE_CHAINED_RE.match(line):
            if contexts:
                contexts.pop()
        i += 1

    return statements


def _collect_group_middleware(lines: list[str], start: int) -> list[str]:
    payload_lines: list[str] = []
    i = start
    saw_open = False
    while i < len(lines):
        line = lines[i]
        payload_lines.append(line)
        if "Route::middleware(" in line:
            saw_open = True
        if "->group" in line and saw_open:
            break
        i += 1

    text = "\n".join(payload_lines)
    open_marker = "Route::middleware("
    open_idx = text.find(open_marker)
    if open_idx == -1:
        return []
    tail = text[open_idx + len(open_marker) :]

    bracket_balance = 0
    captured_until = None
    for j, ch in enumerate(tail):
        if ch == "(":
            bracket_balance += 1
        elif ch == ")":
            bracket_balance -= 1
        if bracket_balance == 0 and (ch == ")" or ch == "]"):
            captured_until = j
            break
    if captured_until is None:
        return []
    literal = tail[: captured_until + 1]
    parsed = _parse_middleware_list(literal)
    return parsed


def _find_matching_close(payload: str) -> int | None:
    depth = 0
    for idx, ch in enumerate(payload):
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
            if depth == 0:
                return idx
    return None


def _skip_group_open(lines: list[str], start: int) -> int:
    depth = 0
    i = start
    while i < len(lines):
        line = lines[i]
        depth += line.count("(") - line.count(")")
        if "->group" in line:
            return i + 1
        if depth <= 0:
            return i + 1
        i += 1
    return i


def _consume_route_statement(
    lines: list[str], start: int, route_match: re.Match, contexts: list[GroupContext]
) -> tuple[RouteStatement, int]:
    collected: list[str] = [lines[start]]
    i = start + 1
    while i < len(lines):
        line = lines[i]
        stripped = line.lstrip()
        if stripped.startswith("Route::"):
            break
        collected.append(line)
        if line.rstrip().endswith(");") or line.rstrip().endswith("];"):
            i += 1
            break
        if not stripped.startswith("->"):
            # New statement without -> continuation
            break
        i += 1

    raw = "\n".join(collected)

    path = route_match.group("path")
    verb = route_match.group("verb")

    controller, controller_method = _extract_controller(raw)

    inline_middleware: list[str] = []
    for call in INLINE_MIDDLEWARE_CALL_RE.finditer(raw):
        inline_middleware.extend(_parse_middleware_list(call.group(1)))
    for without_call in INLINE_WITHOUT_MIDDLEWARE_RE.finditer(raw):
        inline_middleware.extend(["-" + m for m in _parse_middleware_list(without_call.group(1))])

    wherein: dict[str, list[str]] = {}
    for wherein_match in INLINE_WHEREIN_RE.finditer(raw):
        param = wherein_match.group(1)
        values = _parse_wherein_list(wherein_match.group(2))
        wherein[param] = values

    parent_middleware: list[str] = []
    prefix_chain: list[str] = []
    for ctx in contexts:
        parent_middleware.extend(ctx.middleware)
        prefix_chain.extend(ctx.prefixes)
    parent_middleware = list(dict.fromkeys(parent_middleware))

    full_path = "/" + "/".join([*prefix_chain, path]).strip("/") if prefix_chain else "/" + path.strip("/")

    return (
        RouteStatement(
            method=verb.upper(),
            path=full_path,
            line_number=start + 1,
            raw_statement=raw,
            controller=controller,
            controller_method=controller_method,
            inline_middleware=inline_middleware,
            wherein=wherein,
            parent_middleware=parent_middleware,
            prefix_chain=prefix_chain,
        ),
        i - start,
    )


def _catalog_capabilities(repo_root: pathlib.Path) -> list[str]:
    source = (
        repo_root
        / "apps/api/Modules/Authorization/Contracts/CapabilityCatalog.php"
    ).read_text(encoding="utf-8")
    try:
        payload = source.split("private const CAPABILITIES = [", 1)[1].split("];", 1)[0]
    except IndexError as error:
        raise ValueError("CapabilityCatalog::CAPABILITIES could not be parsed") from error
    capabilities = CAPABILITY_LITERAL_RE.findall(payload)
    if len(capabilities) != len(set(capabilities)):
        raise ValueError("CapabilityCatalog contains duplicate capability codes")
    return capabilities


def _production_php_sources(repo_root: pathlib.Path) -> list[tuple[pathlib.Path, str]]:
    sources: list[tuple[pathlib.Path, str]] = []
    roots = (
        repo_root / "apps/api/Modules",
        repo_root / "apps/api/app",
        repo_root / "apps/api/routes",
        repo_root / "apps/api/config",
    )
    for source_root in roots:
        for path in sorted(source_root.rglob("*.php")):
            relative = path.relative_to(repo_root)
            if "Tests" in relative.parts or "tests" in relative.parts:
                continue
            if path.name == "CapabilityCatalog.php":
                continue
            sources.append((relative, path.read_text(encoding="utf-8")))
    return sources


def _web_sources(repo_root: pathlib.Path) -> list[tuple[pathlib.Path, str]]:
    source_root = repo_root / "apps/web/src"
    paths = sorted([*source_root.rglob("*.ts"), *source_root.rglob("*.tsx")])
    return [
        (path.relative_to(repo_root), path.read_text(encoding="utf-8"))
        for path in paths
        if "generated" not in path.parts
        and ".test." not in path.name
        and ".spec." not in path.name
    ]


def _classify_catalog(repo_root: pathlib.Path, capabilities: list[str]) -> list[dict]:
    """Classify catalog entries from production and web callsite evidence.

    The catalog is authoritative; this function never adds or mutates entries.
    A capability referenced by API PHP is ``used``. A capability referenced only
    by the non-generated web source is ``intentional-ui-only``. An unreferenced
    entry is ``deprecated`` unless a future route/catalog helper explicitly
    references it, in which case generation reports ``Unknown`` for review.
    """
    production = _production_php_sources(repo_root)
    web = _web_sources(repo_root)
    classified: list[dict] = []
    for capability in capabilities:
        production_refs = sorted(str(path) for path, text in production if capability in text)
        web_refs = sorted(str(path) for path, text in web if capability in text)
        if production_refs:
            classification = "used"
            evidence = production_refs
        elif web_refs:
            classification = "intentional-ui-only"
            evidence = web_refs
        else:
            classification = "deprecated"
            evidence = ["no production API or web callsite reference"]
        classified.append({
            "capability": capability,
            "classification": classification,
            "evidence": evidence,
        })
    unknown = [item for item in classified if item["classification"] == "Unknown"]
    if unknown:
        names = ", ".join(item["capability"] for item in unknown)
        raise ValueError(f"Unknown catalog classifications require follow-up: {names}")
    return classified


def _controller_metadata(
    repo_root: pathlib.Path,
    statements: list[RouteStatement],
    capabilities: list[str],
) -> dict[str, dict]:
    controller_names = {statement.controller.split("\\")[-1] for statement in statements if statement.controller}
    candidates: dict[str, list[pathlib.Path]] = {}
    for path in sorted((repo_root / "apps/api/Modules").rglob("*.php")):
        if "Tests" in path.parts or "tests" in path.parts:
            continue
        if path.stem in controller_names:
            candidates.setdefault(path.stem, []).append(path)
    metadata: dict[str, dict] = {}
    for controller in sorted(controller_names):
        paths = candidates.get(controller, [])
        if len(paths) != 1:
            metadata[controller] = {"source": None, "capabilities": [], "calls": [], "attributes": [], "check": "missing-controller-source" if not paths else "ambiguous-controller-source"}
            continue
        path = paths[0]
        text = path.read_text(encoding="utf-8")
        literals = sorted({capability for capability in capabilities if capability in text})
        calls = sorted(set(re.findall(r"->(?:decide|evaluateOnly|authorize|allowed|principalAccess|decideAccess)\s*\(", text)))
        attributes = sorted(set(re.findall(r"#\[([^]]+)\]", text)))
        metadata[controller] = {"source": str(path.relative_to(repo_root)), "capabilities": literals, "calls": calls, "attributes": attributes, "check": "controller-local" if literals else "controller-dynamic" if calls else "missing-controller-local-check"}
    return metadata


def _normalize_middleware_alias(name: str) -> str:
    name = name.strip().strip("-")
    if name == "":
        return ""
    base = name.split("::")[0].split("\\")[-1]
    base = base.replace("Middleware", "").replace("::class", "")
    if not base:
        return ""
    s = re.sub(r"(.)([A-Z][a-z])", r"\1_\2", base)
    s = re.sub(r"([a-z0-9])([A-Z])", r"\1_\2", s)
    return s.lower().strip("_")


def _effective_middleware(parent_middleware: list[str], inline_middleware: list[str]) -> list[str]:
    explicit_remove = {m.lstrip("-") for m in inline_middleware if m.startswith("-")}
    additions = [m for m in inline_middleware if not m.startswith("-")]
    combined: list[str] = []
    for mw in [*parent_middleware, *additions]:
        norm = _normalize_middleware_alias(mw)
        if norm and norm not in combined:
            combined.append(norm)
    if explicit_remove:
        normalized_remove = {_normalize_middleware_alias(m) for m in explicit_remove}
        combined = [m for m in combined if m not in normalized_remove]
    return combined


def _derive_throttle(text: str) -> str | None:
    match = re.search(r"throttle:(\d+,\d+)", text)
    return match.group(1) if match else None


def _build_endpoint_tag(stmt: RouteStatement) -> str:
    slug = stmt.path.strip("/").replace("/", "-")
    slug = re.sub(r"\{[^}]+\}", lambda m: m.group(0).strip("{}"), slug)
    name = _controller_short_name(stmt.controller, stmt.controller_method)
    tag = f"{slug}-{stmt.controller_method.lower()}" if stmt.controller_method else slug
    return f"{tag}:{stmt.method.lower()}:{name.lower()}"

def build_matrix(repo_root: pathlib.Path, summary) -> dict:
    routes_text = (repo_root / "apps/api/routes/web.php").read_text(encoding="utf-8")
    statements = parse_routes(routes_text)
    catalog = _catalog_capabilities(repo_root)
    classifications = _classify_catalog(repo_root, catalog)
    controller_metadata = _controller_metadata(repo_root, statements, catalog)
    rows: list[RbacRow] = []
    for stmt in statements:
        effective = _effective_middleware(stmt.parent_middleware, stmt.inline_middleware)
        throttle = _derive_throttle(stmt.raw_statement) or _derive_throttle(" ".join(stmt.parent_middleware))
        controller_name = stmt.controller.split("\\")[-1] if stmt.controller else None
        metadata = controller_metadata.get(controller_name or "", {"source": None, "capabilities": [], "check": "missing-controller-source"})
        rows.append(RbacRow(
            endpoint_tag=_build_endpoint_tag(stmt), method=stmt.method, path=stmt.path,
            controller=controller_name, controller_source=metadata["source"], controller_method=stmt.controller_method,
            capabilities=metadata["capabilities"], capability_check=metadata["check"], middleware=effective,
            requires_session="identity_session" in effective,
            requires_principal="require_identity_session_principal" in effective,
            requires_csrf="identity_csrf" in effective or "identity_csrf_middleware" in effective or any(m.endswith("IdentityCsrfMiddleware") for m in stmt.parent_middleware + stmt.inline_middleware),
            throttle=throttle, security_warning="internal-worker" if "/internal/" in stmt.path else None, source_line=stmt.line_number,
        ))
    classification_counts = {classification: sum(1 for row in classifications if row["classification"] == classification) for classification in ("used", "intentional-ui-only", "deprecated", "Unknown")}
    controllers_without_checks = sorted({row.controller for row in rows if row.controller and row.capability_check not in {"controller-local", "controller-dynamic"}})
    return {"catalog": {"actual_count": len(catalog), "historical_expected_count": 110, "count_mismatch": len(catalog) != 110, "classification_counts": classification_counts, "classifications": classifications}, "rows": [_row_to_dict(row) for row in rows], "controllers_without_capability_checks": controllers_without_checks, "middleware_tuples": [list(t) for t in sorted({tuple(row.middleware) for row in rows})]}


def _row_to_dict(row: RbacRow) -> dict:
    return {k: getattr(row, k) for k in row.__dataclass_fields__}


def write_matrix(matrix: dict, output_dir: pathlib.Path) -> None:
    output_dir.mkdir(parents=True, exist_ok=True)
    target = output_dir / "rbac-matrix.json"
    target.write_text(json.dumps(matrix, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


if __name__ == "__main__":
    sys.stderr.write("rbac is imported by scripts/inventory-routes.py — run that entry point.\n")
    sys.exit(2)
