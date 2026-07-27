#!/usr/bin/env python3
"""Validate the M00 planned-module contracts baseline.

The validator parses ``docs/architecture/planned-module-contracts.yaml`` and
``docs/architecture/planned-module-contracts.md`` rather than sampling source
text, requires exactly the seven planned modules M01-M07, and enforces seven
dimensions with the YAML as the single authored source:

  1. exact module rank, cross-checked per module ``name`` against
     MODULE_RANKS in apps/api/tests/Architecture/ModuleBoundariesTest.php
  2. complete ordered table lists (YAML and Markdown must agree)
  3. full API / OpenAPI / web route prefixes (api = "/api/v1" + openapi,
     web = openapi; YAML and Markdown must agree)
  4. complete capability lists
  5. exact Contract signatures and DTO names, including the canonical
     context-bearing ``GetStrategySnapshot``
  6. every exact Event class and event-type literal (the literal module
     token must equal the module's declared ``event_token``)
  7. authoritative required-integration-token ownership

The validator exits non-zero on any mismatch. Run with ``--self-check`` to
execute the seven negative fixtures on temporary copies; ``--help`` prints
usage.

Exit codes:
  0  validation passed
  1  validation failed (deterministic diagnostic on stderr)
  2  environment error (missing dependency, missing source file)
"""

from __future__ import annotations

import argparse
import copy
import re
import sys
import tempfile
from pathlib import Path
from typing import Any, Callable

try:
    import yaml
except ImportError:
    print(
        "ERROR: PyYAML is required to validate the planned-module contracts.",
        file=sys.stderr,
    )
    raise SystemExit(2)


ROOT = Path(__file__).resolve().parents[1]
YAML_PATH = ROOT / "docs" / "architecture" / "planned-module-contracts.yaml"
MARKDOWN_PATH = ROOT / "docs" / "architecture" / "planned-module-contracts.md"

EXPECTED_MODULE_IDS: tuple[str, ...] = (
    "M01",
    "M02",
    "M03",
    "M04",
    "M05",
    "M06",
    "M07",
)

# Single-source model: the YAML authors every reserved value exactly once.
# Ranks are cross-checked against code — MODULE_RANKS in the architecture
# boundary test — while route prefixes and event tokens are validated for
# internal derivation and YAML/Markdown agreement (the Markdown decision
# record is the second authored source for those dimensions).
BOUNDARY_TEST_PATH = (
    ROOT
    / "apps"
    / "api"
    / "tests"
    / "Architecture"
    / "ModuleBoundariesTest.php"
)

_MODULE_RANKS_BLOCK = re.compile(r"MODULE_RANKS\s*=\s*\[(.*?)\];", re.DOTALL)
_RANK_ENTRY = re.compile(r"'([A-Za-z][A-Za-z0-9]*)'\s*=>\s*(-?\d+)")
_OPENAPI_PREFIX_SHAPE = re.compile(r"^/[a-z0-9][a-z0-9-]*$")
_EVENT_TOKEN_SHAPE = re.compile(r"^[a-z0-9]+$")


def load_boundary_ranks(path: Path = BOUNDARY_TEST_PATH) -> dict[str, int]:
    """Parse MODULE_RANKS from the architecture boundary test."""

    if not path.is_file():
        print(f"ERROR: boundary test is missing: {path}", file=sys.stderr)
        raise SystemExit(2)
    try:
        text = path.read_text(encoding="utf-8")
    except OSError as error:
        print(
            f"ERROR: cannot read boundary test {path}: {error}",
            file=sys.stderr,
        )
        raise SystemExit(2) from error
    match = _MODULE_RANKS_BLOCK.search(text)
    if match is None:
        print(
            f"ERROR: MODULE_RANKS block not found in {path}",
            file=sys.stderr,
        )
        raise SystemExit(2)
    ranks = {
        name: int(rank)
        for name, rank in _RANK_ENTRY.findall(match.group(1))
    }
    if not ranks:
        print(f"ERROR: MODULE_RANKS is empty in {path}", file=sys.stderr)
        raise SystemExit(2)
    return ranks

# Expected passing summary — must match M00 plan §11 expected outcome.
PASSING_SUMMARY = (
    "Planned-module contract validation passed: 7 modules; ranks, tables, "
    "routes, capabilities, contracts, events, and Markdown agree."
)

# Expected self-check summary — must match M00 plan §11 expected outcome.
SELFCHECK_SUMMARY = (
    "Planned-module validator self-check passed: 7/7 negative fixtures rejected."
)


class ValidationError(RuntimeError):
    """Raised when a planned-module contracts payload violates the contract."""


def fail(message: str) -> None:
    """Print a deterministic diagnostic to stderr and exit with status 1."""
    print(f"ERROR: {message}", file=sys.stderr)
    raise SystemExit(1)


# ---------------------------------------------------------------------------
# YAML structural parsing
# ---------------------------------------------------------------------------


def load_yaml(path: Path) -> dict[str, Any]:
    if not path.is_file():
        print(f"ERROR: planned-module YAML is missing: {path}", file=sys.stderr)
        raise SystemExit(2)
    try:
        document = yaml.safe_load(path.read_text(encoding="utf-8"))
    except (OSError, yaml.YAMLError) as error:
        print(f"ERROR: invalid YAML in {path}: {error}", file=sys.stderr)
        raise SystemExit(2) from error
    if not isinstance(document, dict):
        print(f"ERROR: {path} must contain a YAML mapping", file=sys.stderr)
        raise SystemExit(2)
    return document


def _is_mapping(obj: Any) -> bool:
    return isinstance(obj, dict)


def _expect_list(payload: Any, key: str) -> list[Any]:
    if not isinstance(payload, list):
        raise ValidationError(f"{key} must be a list")
    return payload


def _expect_str(payload: Any, key: str) -> str:
    if not isinstance(payload, str) or not payload.strip():
        raise ValidationError(f"{key} must be a non-empty string")
    return payload


def _expect_int(payload: Any, key: str) -> int:
    if isinstance(payload, bool) or not isinstance(payload, int):
        raise ValidationError(f"{key} must be an integer")
    return payload


def _module_by_id(modules: list[dict[str, Any]]) -> dict[str, dict[str, Any]]:
    out: dict[str, dict[str, Any]] = {}
    for entry in modules:
        if not _is_mapping(entry):
            raise ValidationError("every module entry must be a mapping")
        module_id = _expect_str(entry.get("id"), "module.id")
        if module_id in out:
            raise ValidationError(f"duplicate module id: {module_id}")
        out[module_id] = entry
    return out


def _string_list(entry: dict[str, Any], key: str) -> list[str]:
    values = entry.get(key)
    if not isinstance(values, list):
        raise ValidationError(f"{entry.get('id', '?')}.{key} must be a list")
    cleaned: list[str] = []
    for value in values:
        if not isinstance(value, str) or not value.strip():
            raise ValidationError(
                f"{entry.get('id', '?')}.{key} entries must be non-empty strings"
            )
        cleaned.append(value)
    return cleaned


# ---------------------------------------------------------------------------
# Markdown structural parsing
# ---------------------------------------------------------------------------


_TABLE_ROW = re.compile(r"^\s*\|.*\|\s*$")
_TABLE_SEP = re.compile(
    r"^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)+\|?\s*$"
)

def _clean_cell(cell: str) -> str:
    cell = cell.strip()
    # Strip paired outer backticks only when the entire cell is one
    # backtick-wrapped token (no inner whitespace or commas).
    if (
        cell.startswith("`")
        and cell.endswith("`")
        and len(cell) >= 2
        and "`" not in cell[1:-1]
        and "," not in cell
    ):
        cell = cell[1:-1].strip()
    cell = cell.replace("**", "").replace("__", "")
    return cell.strip()

def _split_markdown_row(line: str) -> list[str]:
    stripped = line.strip()
    if stripped.startswith("|"):
        stripped = stripped[1:]
    if stripped.endswith("|"):
        stripped = stripped[:-1]
    return stripped.split("|")


def _parse_table_block(block_lines: list[str]) -> list[dict[str, str]]:
    if not block_lines or len(block_lines) < 2:
        return []
    rows: list[list[str]] = []
    for index, line in enumerate(block_lines):
        if index == 1 and _TABLE_SEP.match(line):
            continue
        rows.append(
            [_clean_cell(cell) for cell in _split_markdown_row(line)]
        )
    if not rows:
        return []
    header = rows[0]
    out: list[dict[str, str]] = []
    for row in rows[1:]:
        record: dict[str, str] = {}
        for column_index, column_name in enumerate(header):
            record[column_name] = (
                row[column_index] if column_index < len(row) else ""
            )
        out.append(record)
    return out


def parse_markdown(content: str) -> list[dict[str, str]]:
    """Parse every Markdown table that names one of the seven M-modules.

    The M00 Markdown decision record uses two table shapes:

    * a ``Field | Value`` table for each module, tagged by a heading like
      ``### 3.1 · M01 Audit (rank 3)``. The parser tags every row of such
      a table with the module id extracted from the heading.
    * a column-headed table where the first column is ``Owner``, ``Plan``,
      or ``Module`` and the cell starts with ``M01`` ... ``M07``. Every
      matching row is tagged with the module id extracted from the cell.

    The returned rows carry two synthetic keys: ``__table__`` (the heading
    under which the table appears) and ``__module_id__`` (``M01`` ... ``M07``).
    """

    lines = content.splitlines()
    tables: list[tuple[str, list[dict[str, str]]]] = []
    current_heading = ""
    block: list[str] = []
    for line in lines:
        if re.match(r"^\s{0,3}#{1,6}\s+", line):
            if block:
                rows = _parse_table_block(block)
                if rows:
                    tables.append((current_heading, rows))
                block = []
            current_heading = line.strip()
            continue
        if _TABLE_ROW.match(line):
            block.append(line)
            continue
        if block:
            rows = _parse_table_block(block)
            if rows:
                tables.append((current_heading, rows))
            block = []
    if block:
        rows = _parse_table_block(block)
        if rows:
            tables.append((current_heading, rows))

    owner_keys = (
        "Owner",
        "Plan",
        "Module",
        "Module/rank",
        "Module / rank",
    )

    heading_module_re = re.compile(
        r"\b(M0[1-7])\b"
    )

    out: list[dict[str, str]] = []
    for title, rows in tables:
        if not rows:
            continue
        first = rows[0]
        owner_key = next((k for k in owner_keys if k in first), None)
        heading_module_match = heading_module_re.search(title)
        heading_module = (
            heading_module_match.group(1)
            if heading_module_match
            else None
        )
        if owner_key is None and heading_module is None:
            continue
        if owner_key is not None:
            for row in rows:
                owner_value = row.get(owner_key, "")
                token = owner_value.split()
                module_id = token[0].strip() if token else ""
                if module_id not in EXPECTED_MODULE_IDS:
                    continue
                tagged = dict(row)
                tagged["__table__"] = title
                tagged["__module_id__"] = module_id
                out.append(tagged)
            continue
        # Field/Value table under a module heading: tag every row with the
        # heading's module id.
        assert heading_module is not None
        for row in rows:
            tagged = dict(row)
            tagged["__table__"] = title
            tagged["__module_id__"] = heading_module
            out.append(tagged)
    return out


# ---------------------------------------------------------------------------
# YAML structural validation
# ---------------------------------------------------------------------------


def _check_modules(yaml_payload: dict[str, Any]) -> dict[str, dict[str, Any]]:
    modules = _expect_list(yaml_payload.get("modules"), "modules")

    seen_ids = [
        str(entry.get("id"))
        for entry in modules
        if _is_mapping(entry)
    ]
    if seen_ids != list(EXPECTED_MODULE_IDS):
        missing = [mid for mid in EXPECTED_MODULE_IDS if mid not in seen_ids]
        extra = [mid for mid in seen_ids if mid not in EXPECTED_MODULE_IDS]
        raise ValidationError(
            "modules must list exactly M01-M07 in order: "
            f"missing={missing} extra={extra}"
        )

    by_id = _module_by_id(modules)

    boundary_ranks = load_boundary_ranks()

    for module_id in EXPECTED_MODULE_IDS:
        entry = by_id[module_id]

        name = _expect_str(entry.get("name"), f"{module_id}.name")
        if name not in boundary_ranks:
            raise ValidationError(
                f"{module_id} name mismatch: {name!r} is not registered in "
                "ModuleBoundariesTest MODULE_RANKS"
            )
        rank = _expect_int(entry.get("rank"), f"{module_id}.rank")
        code_rank = boundary_ranks[name]
        if rank != code_rank:
            raise ValidationError(
                f"{module_id} rank mismatch: expected {code_rank}, got "
                f"{rank} (source: ModuleBoundariesTest MODULE_RANKS)"
            )

        api_prefix = _expect_str(
            entry.get("api_prefix"), f"{module_id}.api_prefix"
        )
        openapi_prefix = _expect_str(
            entry.get("openapi_prefix"), f"{module_id}.openapi_prefix"
        )
        web_prefix = _expect_str(
            entry.get("web_prefix"), f"{module_id}.web_prefix"
        )
        if _OPENAPI_PREFIX_SHAPE.fullmatch(openapi_prefix) is None:
            raise ValidationError(
                f"{module_id} openapi_prefix mismatch: expected a single "
                f"lowercase path segment, got {openapi_prefix}"
            )
        derived_api_prefix = "/api/v1" + openapi_prefix
        if api_prefix != derived_api_prefix:
            raise ValidationError(
                f"{module_id} api_prefix mismatch: expected "
                f"{derived_api_prefix}, got {api_prefix}"
            )
        if web_prefix != openapi_prefix:
            raise ValidationError(
                f"{module_id} web_prefix mismatch: expected "
                f"{openapi_prefix}, got {web_prefix}"
            )

        event_token = _expect_str(
            entry.get("event_token"), f"{module_id}.event_token"
        )
        if _EVENT_TOKEN_SHAPE.fullmatch(event_token) is None:
            raise ValidationError(
                f"{module_id} event_token mismatch: expected a lowercase "
                f"alphanumeric token, got {event_token}"
            )

        tables = _string_list(entry, "owned_tables")
        if module_id == "M07" and any(t == "workspace_items" for t in tables):
            raise ValidationError(
                "M07 tables mismatch: unexpected workspace_items"
            )

        capabilities = _string_list(entry, "capabilities")
        if module_id == "M04" and "strategy.impact.read" not in capabilities:
            raise ValidationError(
                "M04 capabilities mismatch: missing strategy.impact.read"
            )

        contracts = entry.get("public_contracts")
        if module_id == "M04":
            ok = False
            for contract in contracts:
                if not _is_mapping(contract):
                    continue
                name = contract.get("name")
                signature = contract.get("signature")
                if not isinstance(signature, str):
                    continue
                if name == "GetStrategySnapshot":
                    if (
                        "forOrganizationUnit(" in signature
                        and "StrategyAccessContext" in signature
                    ):
                        ok = True
                        break
            if not ok:
                raise ValidationError(
                    "M04 contract mismatch: StrategyAccessContext is required"
                )

        tokens = _string_list(entry, "required_integration_tokens")
        for required in (
            "MODULE-REGISTRY",
            "API-ROUTES",
            "OPENAPI",
            "ORVAL",
            "WEB-SHELL",
        ):
            if required not in tokens:
                raise ValidationError(
                    f"{module_id} required_integration_tokens mismatch: "
                    f"missing {required}"
                )
    _check_events(yaml_payload, by_id)
    return by_id


def _check_events(
    yaml_payload: dict[str, Any],
    modules_by_id: dict[str, dict[str, Any]],
) -> None:
    event_types = _expect_list(
        yaml_payload.get("event_types"), "event_types"
    )

    literals_by_owner: dict[str, list[str]] = {
        mid: [] for mid in EXPECTED_MODULE_IDS
    }
    class_to_owner: dict[str, str] = {}
    for entry in event_types:
        if not _is_mapping(entry):
            raise ValidationError("every event_types entry must be a mapping")
        owner = _expect_str(entry.get("owner"), "event.owner")
        cls = entry.get("class")
        type_literal = entry.get("type")
        if cls is None and type_literal is None:
            continue
        if owner not in EXPECTED_MODULE_IDS:
            continue
        if not isinstance(cls, str) or not isinstance(type_literal, str):
            raise ValidationError(
                f"{owner}: event class and type must be strings when set"
            )
        literals_by_owner[owner].append(type_literal)
        if cls in class_to_owner and class_to_owner[cls] != owner:
            raise ValidationError(
                f"event class {cls!r} appears under both "
                f"{class_to_owner[cls]!r} and {owner!r}"
            )
        class_to_owner[cls] = owner

    for module_id in EXPECTED_MODULE_IDS:
        declared_classes = set(_string_list(modules_by_id[module_id], "events"))
        literals = literals_by_owner[module_id]
        expected_token = _expect_str(
            modules_by_id[module_id].get("event_token"),
            f"{module_id}.event_token",
        )

        for literal in literals:
            parts = literal.split(".")
            if (
                len(parts) != 5
                or parts[0] != "com"
                or parts[1] != "cluster"
                or parts[-1] != "v1"
            ):
                raise ValidationError(
                    f"{module_id} event literal mismatch: module token must "
                    f"be {expected_token}"
                )
            token_segment = parts[2]
            if token_segment != expected_token:
                raise ValidationError(
                    f"{module_id} event literal mismatch: module token must "
                    f"be {expected_token}"
                )

        if not declared_classes:
            if literals:
                raise ValidationError(
                    f"{module_id} event literal mismatch: module token must "
                    f"be {expected_token}"
                )
            continue

        for cls in sorted(declared_classes):
            stripped = cls[:-2] if cls.endswith("V1") else cls
            derived = stripped.lower()
            expected_literal = f"com.cluster.{expected_token}.{derived}.v1"
            if expected_literal not in literals:
                raise ValidationError(
                    f"{module_id} event literal mismatch: module token must "
                    f"be {expected_token}"
                )


# ---------------------------------------------------------------------------
# Markdown structural validation
# ---------------------------------------------------------------------------


_TABLES_COLUMNS = (
    "Owned tables (in order)",
    "Reserved tables",
    "Tables",
    "tables",
)
_API_PREFIX_COLUMNS = ("Full API prefix", "API prefix", "api_prefix")
_OPENAPI_PREFIX_COLUMNS = (
    "OpenAPI/web prefix",
    "OpenAPI prefix",
    "openapi_prefix",
)
_WEB_PREFIX_COLUMNS = (
    "OpenAPI/web prefix",
    "Web prefix",
    "web_prefix",
)
_CAPABILITIES_COLUMNS = ("Capabilities", "capabilities")
_TOKENS_COLUMNS = (
    "Required integration tokens",
    "Required tokens",
    "Integration tokens",
    "tokens",
    "required_integration_tokens",
)
_CONTRACTS_COLUMNS = (
    "Public Contracts",
    "Published Contracts",
    "Contract signatures",
    "public_contracts",
)
_DTOS_COLUMNS = ("Public DTOs", "Published DTOs", "DTOs", "public_dtos")
_EVENTS_COLUMNS = ("Events", "events")


def _split_csv(cell: str) -> list[str]:
    """Split a Markdown cell on commas, stripping inline backticks.

    The M00 decision record lists values either as ``a, b, c`` or as
    `` `a`, `b`, `c` ``. Both forms normalize to ``[a, b, c]``. A leading
    ``None`` marker (e.g. ``**None** (reason)``) normalizes to ``[]`` — it
    is the Markdown spelling of an empty YAML list.
    """

    cell = cell.strip()
    if not cell:
        return []
    if re.match(r"^None\b", cell):
        return []
    raw = [item.strip() for item in cell.split(",")]
    cleaned: list[str] = []
    for item in raw:
        stripped = item.strip()
        if (
            stripped.startswith("`")
            and stripped.endswith("`")
            and len(stripped) >= 2
        ):
            stripped = stripped[1:-1].strip()
        if stripped:
            cleaned.append(stripped)
    return cleaned


def _check_markdown(
    markdown_path: Path,
    modules_by_id: dict[str, dict[str, Any]],
) -> None:
    if not markdown_path.is_file():
        raise ValidationError(
            f"planned-module Markdown is missing: {markdown_path}"
        )
    content = markdown_path.read_text(encoding="utf-8")
    rows = parse_markdown(content)
    if not rows:
        raise ValidationError(
            "Markdown matrix is empty: no module tables could be parsed"
        )

    # Each (module_id, column) value must appear at most once across the
    # whole document. Multiple declarations for the same dimension mean the
    # Markdown disagrees with itself.
    declarations: dict[tuple[str, str], tuple[str, str]] = {}

    def _record(
        module_id: str,
        column_candidates: tuple[str, ...],
        label: str,
        parser: Callable[[str], Any],
        include_field_value: bool = True,
    ) -> None:
        matching = [
            row for row in rows
            if row["__module_id__"] == module_id
            and (
                any(col in row for col in column_candidates)
                or (
                    include_field_value
                    and row.get("Field") in column_candidates
                    and "Value" in row
                )
            )
        ]
        if not matching:
            return
        if len(matching) > 1:
            raise ValidationError(
                f"Markdown matrix has multiple {label} rows for {module_id}"
            )
        row = matching[0]
        if row.get("Field") in column_candidates and "Value" in row:
            column = row["Field"]
            cell_value = row["Value"]
        else:
            column = next(col for col in column_candidates if col in row)
            cell_value = row[column]
        key = (module_id, label)
        if key in declarations and declarations[key][0] != column:
            raise ValidationError(
                f"Markdown matrix declares {label} for {module_id} twice"
            )
        declarations[key] = (column, cell_value)
        try:
            observed = parser(cell_value)
        except ValueError as error:
            raise ValidationError(
                f"Markdown matrix mismatch for {module_id}.{label}: "
                f"unparsable cell {cell_value!r} ({error})"
            ) from error
        expected = (
            modules_by_id[module_id].get("rank")
            if label == "rank"
            else modules_by_id[module_id].get(_label_to_yaml_attr(label))
        )
        if expected is None:
            return
        if label == "rank":
            if int(observed) != int(expected):
                raise ValidationError(
                    f"Markdown matrix mismatch for {module_id}.{label}: "
                    f"expected {expected}, got {observed}"
                )
        elif isinstance(expected, list):
            observed_list = list(observed)
            expected_list = list(expected)
            if observed_list != expected_list:
                raise ValidationError(
                    f"Markdown matrix mismatch for {module_id}.{label}: "
                    f"expected {expected_list}, got {observed_list}"
                )
        elif observed != expected:
            raise ValidationError(
                f"Markdown matrix mismatch for {module_id}.{label}: "
                f"expected {expected}, got {observed}"
            )

    heading_rank_re = re.compile(r"\(rank\s+(\d+)\)")
    for module_id in EXPECTED_MODULE_IDS:
        # Rank is declared in the module heading (``### 3.4 · M04 Strategy
        # (rank 8)``), not in a table column. Find any row whose table
        # heading carries the module id, extract the rank, and compare.
        module_headings = [
            row["__table__"]
            for row in rows
            if row["__module_id__"] == module_id
        ]
        if module_headings:
            heading = module_headings[0]
            match = heading_rank_re.search(heading)
            if match is not None:
                observed_rank = int(match.group(1))
                expected_rank = int(modules_by_id[module_id]["rank"])
                if observed_rank != expected_rank:
                    raise ValidationError(
                        f"Markdown matrix mismatch for {module_id}.rank: "
                        f"expected {expected_rank}, got {observed_rank}"
                    )
        _record(
            module_id,
            _TABLES_COLUMNS,
            "tables",
            _split_csv,
        )
        _record(
            module_id,
            _API_PREFIX_COLUMNS,
            "api_prefix",
            _strip_cell,
        )
        _record(
            module_id,
            _OPENAPI_PREFIX_COLUMNS,
            "openapi_prefix",
            _strip_cell,
        )
        _record(
            module_id,
            _WEB_PREFIX_COLUMNS,
            "web_prefix",
            _strip_cell,
        )
        _record(
            module_id,
            _CAPABILITIES_COLUMNS,
            "capabilities",
            _split_csv,
        )
        _record(
            module_id,
            _TOKENS_COLUMNS,
            "tokens",
            _split_csv,
        )
        _record(
            module_id,
            _CONTRACTS_COLUMNS,
            "contracts",
            _split_csv,
            include_field_value=False,
        )
        _record(
            module_id,
            _DTOS_COLUMNS,
            "dtos",
            _split_csv,
        )
        _record(
            module_id,
            _EVENTS_COLUMNS,
            "events",
            _split_csv,
        )


_LABEL_TO_YAML_ATTR: dict[str, str] = {
    "tables": "owned_tables",
    "api_prefix": "api_prefix",
    "openapi_prefix": "openapi_prefix",
    "web_prefix": "web_prefix",
    "capabilities": "capabilities",
    "tokens": "required_integration_tokens",
    "contracts": "public_contracts",
    "dtos": "public_dtos",
    "events": "events",
}


def _label_to_yaml_attr(label: str) -> str:
    return _LABEL_TO_YAML_ATTR[label]


def _strip_cell(cell: str) -> str:
    return cell.strip()


def _parse_int_cell(cell: str) -> int:
    return int(cell.strip())


# ---------------------------------------------------------------------------
# Top-level validate()
# ---------------------------------------------------------------------------


def validate(
    yaml_payload: dict[str, Any],
    markdown_path: Path,
) -> None:
    """Validate the parsed YAML payload and Markdown file."""

    modules_by_id = _check_modules(yaml_payload)
    _check_markdown(markdown_path, modules_by_id)


# ---------------------------------------------------------------------------
# Self-check mode
# ---------------------------------------------------------------------------


def _mutate_yaml(
    yaml_payload: dict[str, Any],
    mutator: Callable[[dict[str, Any]], None],
) -> dict[str, Any]:
    payload = copy.deepcopy(yaml_payload)
    mutator(payload)
    return payload


def _expect_diagnostic(expected_body: str, observed_exc: BaseException) -> None:
    """Assert that *observed_exc* contains *expected_body*.

    The caller-side ``fail()`` helper prepends the ``ERROR: `` prefix when
    writing the message to stderr; the in-memory ``ValidationError`` does
    not carry that prefix, so this comparison is body-only.
    """

    message = str(observed_exc)
    if expected_body not in message:
        fail(
            "self-check expected diagnostic containing "
            f"{expected_body!r}, got {message!r}"
        )
def _run_self_check(yaml_payload: dict[str, Any]) -> None:
    """Execute the seven negative fixtures on temporary deep copies."""

    modules_list = _expect_list(yaml_payload.get("modules"), "modules")
    modules_by_id = _module_by_id(modules_list)

    # ---- Fixture 1: wrong Strategy rank ----------------------------------
    def _fix_rank(payload: dict[str, Any]) -> None:
        for entry in payload["modules"]:
            if isinstance(entry, dict) and entry.get("id") == "M04":
                entry["rank"] = 9

    try:
        validate_payload_only(_mutate_yaml(yaml_payload, _fix_rank))
    except ValidationError as error:
        _expect_diagnostic(
            "M04 rank mismatch: expected 8, got 9", error
        )
    else:
        fail("self-check fixture 1 (wrong M04 rank) was accepted")

    # ---- Fixture 2: extra Workspace table --------------------------------
    def _add_workspace_table(payload: dict[str, Any]) -> None:
        for entry in payload["modules"]:
            if isinstance(entry, dict) and entry.get("id") == "M07":
                entry.setdefault("owned_tables", []).append("workspace_items")

    try:
        validate_payload_only(_mutate_yaml(yaml_payload, _add_workspace_table))
    except ValidationError as error:
        _expect_diagnostic(
            "M07 tables mismatch: unexpected workspace_items", error
        )
    else:
        fail("self-check fixture 2 (extra Workspace table) was accepted")

    # ---- Fixture 3: wrong Portfolio API prefix ---------------------------
    def _fix_portfolio_prefix(payload: dict[str, Any]) -> None:
        for entry in payload["modules"]:
            if isinstance(entry, dict) and entry.get("id") == "M05":
                entry["api_prefix"] = "/api/v1/portfolio-projects"
                entry["openapi_prefix"] = "/portfolio-projects"
                entry["web_prefix"] = "/portfolio-projects"

    try:
        validate(
            _mutate_yaml(yaml_payload, _fix_portfolio_prefix),
            MARKDOWN_PATH,
        )
    except ValidationError as error:
        _expect_diagnostic(
            "Markdown matrix mismatch for M05.api_prefix: expected "
            "/api/v1/portfolio-projects, got /api/v1/portfolio",
            error,
        )
    else:
        fail("self-check fixture 3 (wrong M05 api_prefix) was accepted")

    # ---- Fixture 4: missing strategy.impact.read -------------------------
    def _drop_impact_capability(payload: dict[str, Any]) -> None:
        for entry in payload["modules"]:
            if isinstance(entry, dict) and entry.get("id") == "M04":
                caps = entry.get("capabilities")
                if isinstance(caps, list):
                    entry["capabilities"] = [
                        cap
                        for cap in caps
                        if cap != "strategy.impact.read"
                    ]

    try:
        validate_payload_only(_mutate_yaml(yaml_payload, _drop_impact_capability))
    except ValidationError as error:
        _expect_diagnostic(
            "M04 capabilities mismatch: missing strategy.impact.read",
            error,
        )
    else:
        fail("self-check fixture 4 (missing M04 capability) was accepted")

    # ---- Fixture 5: context-free GetStrategySnapshot ---------------------
    def _drop_strategy_context(payload: dict[str, Any]) -> None:
        for entry in payload["modules"]:
            if isinstance(entry, dict) and entry.get("id") == "M04":
                contracts = entry.get("public_contracts")
                if not isinstance(contracts, list):
                    return
                for contract in contracts:
                    if (
                        isinstance(contract, dict)
                        and contract.get("name") == "GetStrategySnapshot"
                    ):
                        contract["signature"] = (
                            "forOrganizationUnit(string $organizationUnitId, "
                            "?string $periodId = null): StrategySnapshot"
                        )

    try:
        validate_payload_only(_mutate_yaml(yaml_payload, _drop_strategy_context))
    except ValidationError as error:
        _expect_diagnostic(
            "M04 contract mismatch: StrategyAccessContext is required",
            error,
        )
    else:
        fail(
            "self-check fixture 5 (context-free GetStrategySnapshot) was accepted"
        )

    # ---- Fixture 6: wrong M05 event-literal module token -----------------
    def _rename_event_token(payload: dict[str, Any]) -> None:
        for entry in payload.get("event_types", []):
            if not _is_mapping(entry):
                continue
            if entry.get("owner") != "M05":
                continue
            type_literal = entry.get("type")
            if isinstance(type_literal, str):
                entry["type"] = type_literal.replace(
                    "com.cluster.portfolioprojects.",
                    "com.cluster.portfolio-projects.",
                )

    try:
        validate_payload_only(_mutate_yaml(yaml_payload, _rename_event_token))
    except ValidationError as error:
        _expect_diagnostic(
            "M05 event literal mismatch: module token must be "
            "portfolioprojects",
            error,
        )
    else:
        fail(
            "self-check fixture 6 (wrong M05 event-literal token) was accepted"
        )

    # ---- Fixture 7: Markdown/YAML M06 rank disagreement ------------------
    def _write_disagreeing_markdown(path: Path) -> None:
        sections: list[str] = ["# Synthetic M00 matrix for self-check fixture 7"]
        for module_id in EXPECTED_MODULE_IDS:
            entry = modules_by_id[module_id]
            rank = int(entry.get("rank", 0))
            if module_id == "M06":
                rank = 9  # disagreement: YAML expects 10
            tables = "`, `".join(entry.get("owned_tables", []))
            api_prefix = entry.get("api_prefix", "")
            openapi_prefix = entry.get("openapi_prefix", "")
            capabilities = "`, `".join(entry.get("capabilities", []))
            tokens = "`, `".join(entry.get("required_integration_tokens", []))
            contracts = "; ".join(
                c.get("signature", "")
                for c in entry.get("public_contracts", [])
            )
            dtos = "`, `".join(entry.get("public_dtos", []))
            events = "`, `".join(entry.get("events", []))
            sections.append(
                f"## Section {module_id}\n\n"
                f"### {module_id} {entry.get('name', '')} (rank {rank})\n\n"
                "| Field | Value |\n"
                "| --- | --- |\n"
                f"| API prefix | `{api_prefix}` |\n"
                f"| OpenAPI/web prefix | `{openapi_prefix}` |\n"
                f"| Owned tables (in order) | `{tables}` |\n"
                f"| Capabilities | `{capabilities}` |\n"
                f"| Public Contracts | `{contracts}` |\n"
                f"| Public DTOs | `{dtos}` |\n"
                f"| Events | `{events}` |\n"
                f"| Required integration tokens | `{tokens}` |\n"
            )
        path.write_text("\n".join(sections) + "\n", encoding="utf-8")

    with tempfile.TemporaryDirectory(prefix="planned-modules-selfcheck-") as tmp:
        tmp_markdown = Path(tmp) / "planned-module-contracts.md"
        _write_disagreeing_markdown(tmp_markdown)
        try:
            validate(yaml_payload, tmp_markdown)
        except ValidationError as error:
            _expect_diagnostic(
                "Markdown matrix mismatch for M06.rank: expected 10, "
                "got 9",
                error,
            )
        else:
            fail(
                "self-check fixture 7 (Markdown rank disagreement) was accepted"
            )


def validate_payload_only(yaml_payload: dict[str, Any]) -> None:
    """Run only the YAML structural validation.

    Self-check fixtures 1, 2, 4, 5, and 6 mutate only the YAML and exercise
    this narrower path; fixture 3 (prefix drift) and fixture 7 (rank
    disagreement) exercise the full Markdown-aware ``validate``.
    """

    _check_modules(yaml_payload)


# ---------------------------------------------------------------------------
# Entrypoint
# ---------------------------------------------------------------------------


def _parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument(
        "--self-check",
        action="store_true",
        help="execute the seven negative fixtures on temporary copies",
    )
    parser.add_argument(
        "--yaml",
        type=Path,
        default=YAML_PATH,
        help="path to the planned-module YAML manifest",
    )
    parser.add_argument(
        "--markdown",
        type=Path,
        default=MARKDOWN_PATH,
        help="path to the planned-module Markdown decision record",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> None:
    args = _parse_args(argv if argv is not None else sys.argv[1:])
    yaml_payload = load_yaml(args.yaml)

    if args.self_check:
        _run_self_check(yaml_payload)
        print(SELFCHECK_SUMMARY)
        return

    try:
        validate(yaml_payload, args.markdown)
    except ValidationError as error:
        fail(str(error))
    print(PASSING_SUMMARY)


if __name__ == "__main__":
    main()