#!/usr/bin/env python3
"""Inventory live Laravel API routes from apps/api/routes/web.php.

This first slice implements the route-count and whereIn-family baseline used by
Stage 1 verification. The script is intentionally small but structured so later
slices can extend it into full endpoint inventory / OpenAPI reconciliation.
"""

from __future__ import annotations

import argparse
import dataclasses
import datetime
import importlib.util
import json
import pathlib
import re
import sys
from typing import Iterable


REPO_ROOT = pathlib.Path(__file__).resolve().parent.parent
ROUTES_FILE = REPO_ROOT / "apps/api/routes/web.php"
BOOTSTRAP_FILE = REPO_ROOT / "apps/api/bootstrap/app.php"
DEFAULT_ENDPOINTS_MD = REPO_ROOT / "docs/api/endpoints.md"
TRANSLATE_SUMMARY_PATH = REPO_ROOT / ".minimax-flow/translate-summary.json"

ROUTE_DECL_RE = re.compile(r"Route::(get|post|patch|put|delete)\(")
WHEREIN_RE = re.compile(r"->whereIn\(")
HEALTH_RE = re.compile(r"health:\s*'(/up)'")

CARD_HEADING_RE = re.compile(r"^### `(GET|POST|PATCH|PUT|DELETE)\s+([^`]+)`\s*$", re.MULTILINE)
CONTROLLER_FQCN_RE = re.compile(r"\*\*Controller FQCN:\*\*\s*`([^`]+)`")
PLACEHOLDER_RE = re.compile(r"`\{\{AR:([a-zA-Z0-9_]+)\}\}`")
SUMMARY_BULLET_RE = re.compile(r"^- \*\*ملخص[^*]*:\*\*[^\n]*\n", re.MULTILINE)


@dataclasses.dataclass(frozen=True)
class RouteRecord:
    method: str
    line_number: int
    source: str


@dataclasses.dataclass(frozen=True)
class InventorySummary:
    routes: list[RouteRecord]
    wherein_families: int
    bootstrap_health: str | None


def read_text(path: pathlib.Path) -> str:
    if not path.exists():
        raise FileNotFoundError(path)
    return path.read_text(encoding="utf-8")


def parse_routes(text: str) -> list[RouteRecord]:
    routes: list[RouteRecord] = []
    for line_number, line in enumerate(text.splitlines(), start=1):
        match = ROUTE_DECL_RE.search(line)
        if not match:
            continue
        routes.append(
            RouteRecord(
                method=match.group(1).upper(),
                line_number=line_number,
                source=line.rstrip(),
            )
        )
    return routes


def count_wherein_families(text: str) -> int:
    return sum(1 for line in text.splitlines() if WHEREIN_RE.search(line))


def bootstrap_health(text: str) -> str | None:
    match = HEALTH_RE.search(text)
    return match.group(1) if match else None


def build_summary() -> InventorySummary:
    routes_text = read_text(ROUTES_FILE)
    bootstrap_text = read_text(BOOTSTRAP_FILE)
    routes = parse_routes(routes_text)
    wherein_families = count_wherein_families(routes_text)
    health = bootstrap_health(bootstrap_text)
    return InventorySummary(routes=routes, wherein_families=wherein_families, bootstrap_health=health)


def print_summary(summary: InventorySummary) -> None:
    print(f"parsed={len(summary.routes)}")
    print(f"wherein_families={summary.wherein_families}")
    if summary.bootstrap_health:
        print(f"bootstrap_health={summary.bootstrap_health}")


def write_summary(summary: InventorySummary) -> None:
    out_dir = REPO_ROOT / ".minimax-flow"
    out_dir.mkdir(parents=True, exist_ok=True)
    inventory_file = out_dir / "route-inventory.json"
    payload = {
        "parsed": len(summary.routes),
        "wherein_families": summary.wherein_families,
        "bootstrap_health": summary.bootstrap_health,
        "routes": [dataclasses.asdict(route) for route in summary.routes],
    }

    import json

    inventory_file.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def run_rbac_mode(args, summary: InventorySummary) -> int:
    from rbac import build_matrix, write_matrix  # type: ignore

    matrix = build_matrix(REPO_ROOT, summary)

    output_dir = pathlib.Path(args.json or "/tmp/inventory-rbac")
    write_matrix(matrix, output_dir)

    print(f"rbac_rows={len(matrix['rows'])}")
    print(f"middleware_tuples={len(matrix['middleware_tuples'])}")
    print(f"output_dir={output_dir}")
    return 0


def run_rbac_markdown_mode(args, summary: InventorySummary) -> int:
    from markdown_renderer import write_rbac_markdown  # type: ignore
    from rbac import build_matrix  # type: ignore

    matrix = build_matrix(REPO_ROOT, summary)
    output_dir = pathlib.Path(args.json or REPO_ROOT / "docs/api")
    target = write_rbac_markdown(REPO_ROOT, summary, output_dir)
    catalog = matrix["catalog"]
    counts = catalog["classification_counts"]
    print(f"catalog_count={catalog['actual_count']}")
    print(f"classifications=used:{counts['used']},intentional-ui-only:{counts['intentional-ui-only']},deprecated:{counts['deprecated']}")
    print(f"rbac_markdown={target}")
    return 0


def run_markdown_mode(args, summary: InventorySummary) -> int:
    from markdown_renderer import write_markdown  # type: ignore

    output_dir = pathlib.Path(args.json or REPO_ROOT / "docs/api")
    target = write_markdown(REPO_ROOT, summary, output_dir)

    print(f"markdown_cards={len(summary.routes)}")
    print(f"output_file={target}")
    return 0


def _split_md_into_cards(markdown: str) -> list[tuple[str, str, str, str]]:
    """Return a list of (method, path, controller_fqcn, card_body) tuples.

    ``card_body`` is the raw text between (and including) consecutive
    ``### METHOD PATH`` headings, up to the next ``## `` section heading or
    the next ``### `` heading — whichever comes first.
    """
    cards: list[tuple[str, str, str, str]] = []
    matches = list(CARD_HEADING_RE.finditer(markdown))
    if not matches:
        return cards
    module_boundary = re.compile(r"^(##\s|\*\*[A-Z])", re.MULTILINE)

    for index, match in enumerate(matches):
        start = match.start()
        end = matches[index + 1].start() if index + 1 < len(matches) else len(markdown)
        body = markdown[start:end]

        # Stop card body at the next module/section heading inside the body.
        boundary_match = module_boundary.search(body, pos=match.end() - start)
        if boundary_match is not None:
            body = body[: boundary_match.start()]

        controller_match = CONTROLLER_FQCN_RE.search(body)
        if controller_match is None:
            continue
        controller_fqcn = controller_match.group(1).strip()
        cards.append(
            (
                match.group(1).upper(),
                match.group(2).strip(),
                controller_fqcn,
                body,
            )
        )
    return cards


def _run_translate_mode(args, markdown: str) -> tuple[str, dict]:
    """Replace `{{AR:op_key}}` placeholders and add Arabic summary bullets.

    Idempotent: removes any previously-written `ملخص` bullet before re-adding
    the same line, and silently no-ops on already-translated cards.
    """
    from arabic_translator import translate_card  # type: ignore

    cards = _split_md_into_cards(markdown)
    if not cards:
        raise ValueError("no endpoint cards found in markdown; cannot translate")

    translated_count = 0
    samples: list[str] = []
    first_chunks: list[str] = []

    def _rewrite_card(card_body: str, arabic: str) -> tuple[str, bool]:
        """Return (new_body, changed_flag)."""
        # Remove any previously-written summary bullet.
        cleaned = SUMMARY_BULLET_RE.sub("", card_body)
        # Replace placeholders that live in this card.
        new_body, placeholders = PLACEHOLDER_RE.subn(arabic, cleaned)
        if placeholders == 0 and cleaned == card_body:
            return card_body, False
        new_body = new_body + f"- **ملخص (AR):** {arabic}\n"
        return new_body, True

    # Track an offset to apply edits to the original string.
    new_chunks: list[str] = []
    cursor = 0
    for method, path, controller_fqcn, card_body in cards:
        # Skip cards whose controller hint indicates a missing mapping.
        if "::" in controller_fqcn:
            class_part, method_part = controller_fqcn.split("::", 1)
            controller_fqcn_clean = class_part
        else:
            method_part = None
            controller_fqcn_clean = controller_fqcn

        arabic = translate_card(method, path, controller_fqcn_clean, method_part)
        new_card, changed = _rewrite_card(card_body, arabic)
        if changed:
            translated_count += 1
            if len(samples) < 5:
                samples.append(arabic)

        card_start_offset = markdown.index(card_body, cursor)
        # card_start_offset may now be off if previous cards rewrote; but the
        # offsets stack because all earlier cards are inserted verbatim above
        # this one. We translate that by appending verbatim chunks above each
        # rewritten card.
        new_chunks.append(markdown[cursor:card_start_offset])
        new_chunks.append(new_card)
        cursor = card_start_offset + len(card_body)

    new_chunks.append(markdown[cursor:])
    new_markdown = "".join(new_chunks)

    summary = {
        "timestamp": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "endpoint_count_translated": translated_count,
        "sample_arabic": samples,
    }
    return new_markdown, summary


def run_translate_mode(args, summary: InventorySummary) -> int:
    md_path = pathlib.Path(args.md_path) if args.md_path else DEFAULT_ENDPOINTS_MD
    if not md_path.exists():
        print(f"missing markdown input: {md_path}", file=sys.stderr)
        return 2

    out_path = pathlib.Path(args.out_path) if args.out_path else md_path
    markdown = md_path.read_text(encoding="utf-8")

    if "{{AR:" not in markdown:
        # Idempotent no-op, but still refresh the summary artifact for review.
        translated_count = markdown.count("**ملخص (AR):**")
        summary_payload = {
            "timestamp": datetime.datetime.now(datetime.timezone.utc).isoformat(),
            "endpoint_count_translated": translated_count,
            "sample_arabic": [],
            "no_op": True,
        }
        TRANSLATE_SUMMARY_PATH.parent.mkdir(parents=True, exist_ok=True)
        TRANSLATE_SUMMARY_PATH.write_text(
            json.dumps(summary_payload, indent=2, ensure_ascii=False) + "\n",
            encoding="utf-8",
        )
        print(f"no-op: no {{AR: placeholders left; refcount={translated_count}")
        print(f"summary_written={TRANSLATE_SUMMARY_PATH}")
        return 0

    try:
        new_markdown, summary_payload = _run_translate_mode(args, markdown)
    except ValueError as exc:
        print(f"translate failed: {exc}", file=sys.stderr)
        return 1

    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(new_markdown, encoding="utf-8")

    TRANSLATE_SUMMARY_PATH.parent.mkdir(parents=True, exist_ok=True)
    TRANSLATE_SUMMARY_PATH.write_text(
        json.dumps(summary_payload, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )

    print(f"translated={summary_payload['endpoint_count_translated']}")
    print(f"sample_count={len(summary_payload['sample_arabic'])}")
    print(f"output_file={out_path}")
    print(f"summary_written={TRANSLATE_SUMMARY_PATH}")
    return 0


def main(argv: Iterable[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--check", action="store_true", help="Read-only verification mode (default)")
    mode.add_argument("--write", action="store_true", help="Persist inventory snapshot to .minimax-flow/route-inventory.json")
    mode.add_argument("--dry-run", action="store_true", help="Alias for --check (no writes)")
    parser.add_argument(
        "--mode",
        choices=["inspect", "rbac", "rbac-md", "md", "translate"],
        default="inspect",
        help="Output mode: inspect | rbac (JSON) | rbac-md (RBAC markdown only) | md (all API markdown) | translate",
    )
    parser.add_argument(
        "--json",
        metavar="DIR",
        help="When mode=rbac|rbac-md|md, write output to the given directory",
    )
    parser.add_argument(
        "--md-path",
        metavar="PATH",
        help="With --mode translate, the endpoints.md file to translate (default: docs/api/endpoints.md)",
    )
    parser.add_argument(
        "--out-path",
        metavar="PATH",
        help="With --mode translate, write the translated markdown here (default: overwrite the --md-path file in place)",
    )
    args = parser.parse_args(list(argv) if argv is not None else None)


    if args.mode == "translate":
        # Translate operates purely on the markdown file; the routes summary
        # is unused but we still build it to keep error-handling consistent.
        try:
            summary = build_summary()
        except FileNotFoundError as exc:
            print(f"missing source file: {exc}", file=sys.stderr)
            return 2
        return run_translate_mode(args, summary)

    try:
        summary = build_summary()
    except FileNotFoundError as exc:
        print(f"missing source file: {exc}", file=sys.stderr)
        return 2

    if args.mode == "inspect":
        if args.write:
            write_summary(summary)
            print("wrote inventory snapshot to .minimax-flow/route-inventory.json")
        print_summary(summary)
        if args.check or args.dry_run:
            if len(summary.routes) != 150:
                print(f"route count mismatch: expected 150, got {len(summary.routes)}", file=sys.stderr)
                return 1
            if summary.wherein_families != 7:
                print(f"whereIn family mismatch: expected 7, got {summary.wherein_families}", file=sys.stderr)
                return 1
            if summary.bootstrap_health != "/up":
                print(f"health route mismatch: expected /up, got {summary.bootstrap_health!r}", file=sys.stderr)
                return 1
        return 0

    if args.mode == "rbac":
        return run_rbac_mode(args, summary)
    if args.mode == "rbac-md":
        return run_rbac_markdown_mode(args, summary)
    if args.mode == "md":
        return run_markdown_mode(args, summary)

    print(f"unknown mode {args.mode!r}", file=sys.stderr)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
