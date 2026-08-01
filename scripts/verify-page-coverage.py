#!/usr/bin/env python3
"""Fail when an API operation has no page assignment in docs/design/PAGES.md.

The endpoint table is generated from the OpenAPI contract and routes/web.php.
PAGES.md assigns every operation to exactly one page, or to the explicit
"outside the UI" page. Silence is a defect: an unassigned operation is how the
backend/frontend gap reopens.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TABLE = ROOT / "docs" / "api" / "endpoints-table.md"
PAGES = ROOT / "docs" / "design" / "PAGES.md"
PLANNED_MARKER = "## ملحق: مسارات مخططة"


def table_operations() -> set[tuple[str, str]]:
    text = TABLE.read_text(encoding="utf-8")
    live, planned = text.split(PLANNED_MARKER)
    ops: set[tuple[str, str]] = set()
    for line in live.splitlines():
        cells = [c.strip() for c in line.split("|")[1:-1]]
        if len(cells) >= 4 and cells[0].isdigit():
            ops.add((cells[2].strip("`"), cells[3].strip("`").replace("/api/v1/", "")))
    for line in planned.splitlines():
        cells = [c.strip() for c in line.split("|")[1:-1]]
        if len(cells) >= 2 and cells[0].startswith("`") and cells[0] != "`Method`":
            ops.add((cells[0].strip("`"), cells[1].strip("`").replace("/api/v1/", "")))
    return ops


def page_operations() -> set[tuple[str, str]]:
    text = PAGES.read_text(encoding="utf-8")
    ops: set[tuple[str, str]] = set()
    for line in text.splitlines():
        cells = [c.strip() for c in line.split("|")[1:-1]]
        if len(cells) == 4 and cells[0].startswith("`") and cells[0] != "`Method`":
            ops.add((cells[0].strip("`"), cells[1].strip("`")))
    return ops


def main() -> int:
    for path in (TABLE, PAGES):
        if not path.exists():
            print(f"ERROR: missing {path.relative_to(ROOT)}", file=sys.stderr)
            return 1

    declared = table_operations()
    assigned = page_operations()

    missing = sorted(declared - assigned)
    stale = sorted(assigned - declared)

    if missing:
        print(
            f"ERROR: {len(missing)} API operation(s) have no page in docs/design/PAGES.md:",
            file=sys.stderr,
        )
        for method, endpoint in missing:
            print(f"  {method} {endpoint}", file=sys.stderr)
        print(
            "\nAssign each to a page, or to the 'مسارات بلا واجهة' page if it is "
            "intentionally not exposed in the UI.",
            file=sys.stderr,
        )

    if stale:
        print(
            f"ERROR: {len(stale)} page assignment(s) reference operations that no "
            "longer exist in the contract:",
            file=sys.stderr,
        )
        for method, endpoint in stale:
            print(f"  {method} {endpoint}", file=sys.stderr)

    if missing or stale:
        return 1

    print(f"Page coverage OK: {len(declared)} operations, all assigned.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
