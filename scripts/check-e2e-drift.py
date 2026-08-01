#!/usr/bin/env python3
"""Fail when the e2e failing set differs from the frozen known-red list.

Nine W1.1 specs were already failing at the pre-programme commit 17a84ac,
verified through a worktree with identical results. With that, "run the spec
and confirm it passes" is unusable as an acceptance criterion, so the gate
frozen here works in both directions:

- a spec that fails but is not in known-red.json is a real regression;
- a spec that passes but is in known-red.json means the list is stale and
  must be pruned in the same commit that fixed the spec.

A frozen list that is never pruned rots into permission to fail.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
KNOWN_RED = ROOT / "apps/web/e2e/known-red.json"
FAILED_STATUSES = {"unexpected", "flaky"}


def load_report(path: Path) -> list[dict[str, str]]:
    data = json.loads(path.read_text(encoding="utf-8"))
    tests: list[dict[str, str]] = []

    def walk(suite: dict, inherited_file: str | None = None) -> None:
        file = suite.get("file") or inherited_file
        for child in suite.get("suites", []):
            walk(child, file)
        for spec in suite.get("specs", []):
            for test in spec.get("tests", []):
                tests.append(
                    {
                        "file": Path(file).name if file else "",
                        "title": spec["title"],
                        "status": test["status"],
                    }
                )

    for suite in data.get("suites", []):
        walk(suite)
    return tests


def frozen_red() -> set[tuple[str, str]]:
    entries = json.loads(KNOWN_RED.read_text(encoding="utf-8"))
    return {(Path(e["file"]).name, e["title"]) for e in entries}


def main() -> int:
    if len(sys.argv) != 2:
        print("usage: check-e2e-drift.py <playwright-results.json>", file=sys.stderr)
        return 2
    report_path = Path(sys.argv[1])
    if not report_path.exists():
        print(
            f"ERROR: report {report_path} does not exist — the W1.1 runner did not produce a JSON report.",
            file=sys.stderr,
        )
        return 1

    frozen = frozen_red()
    failing = {
        (test["file"], test["title"])
        for test in load_report(report_path)
        if test["status"] in FAILED_STATUSES
    }

    regressions = sorted(failing - frozen)
    pruned = sorted(frozen - failing)
    exit_code = 0

    if regressions:
        exit_code = 1
        print(
            f"ERROR: {len(regressions)} spec(s) failed that are not in known-red.json — real regressions:",
            file=sys.stderr,
        )
        for file, title in regressions:
            print(f"  {file} › {title}", file=sys.stderr)

    if pruned:
        exit_code = 1
        print(
            f"ERROR: {len(pruned)} frozen spec(s) now pass — prune them from known-red.json "
            "in the same commit that fixed them:",
            file=sys.stderr,
        )
        for file, title in pruned:
            print(f"  {file} › {title}", file=sys.stderr)

    if exit_code:
        return exit_code

    print(f"E2E drift OK: {len(failing)} failing, all frozen in known-red.json.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
