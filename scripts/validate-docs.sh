#!/usr/bin/env bash
set -euo pipefail

readonly root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

for script in scripts/*.sh; do
  bash -n "$script"
done

readonly python_binary="${PYTHON_BINARY:-python3}"

if ! command -v "$python_binary" >/dev/null 2>&1; then
  printf 'ERROR: %s is required for documentation validation.\n' "$python_binary" >&2
  exit 2
fi

"$python_binary" scripts/validate-notifications-openapi.py
"$python_binary" scripts/validate-auth-openapi.py
"$python_binary" scripts/validate-work-records-openapi.py
"$python_binary" scripts/validate-architecture-closure.py
"$python_binary" scripts/validate-planned-module-contracts.py
"$python_binary" - <<'PY'
from __future__ import annotations

import json
import re
import sys
import unicodedata
from collections import Counter
from pathlib import Path
from urllib.parse import unquote, urlsplit

try:
    import yaml
except ImportError:
    print(
        "ERROR: PyYAML is required; install it before running documentation validation.",
        file=sys.stderr,
    )
    raise SystemExit(2)


ROOT = Path.cwd().resolve()
DOCS = ROOT / "docs"
MARKDOWN_LINK = re.compile(
    r"!?\[[^\]]*\]\((?:<([^>]+)>|([^\s)]+))(?:\s+[\"'][^\"']*[\"'])?\)"
)
HTML_LINK = re.compile(r"<(?:a|img)\b[^>]+(?:href|src)=[\"']([^\"']+)[\"']", re.IGNORECASE)
DOC_REFERENCE = re.compile(
    r"(?<![A-Za-z0-9_.-])(docs/[A-Za-z0-9_.\-/]+\.(?:md|yaml|yml|json|mmd))"
)
EXCLUDED_PARTS = {
    ".git",
    ".minimax-flow",
    ".opencode",
    ".pi",
    ".pi-subagents",
    ".planning",
    "node_modules",
}
errors: list[str] = []


def relative(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def add_error(message: str) -> None:
    errors.append(message)


def markdown_anchors(content: str) -> set[str]:
    anchors: set[str] = set()
    counts: Counter[str] = Counter()
    fenced = False
    fence_marker = ""
    for line in content.splitlines():
        fence = re.match(r"^\s*(```+|~~~+)", line)
        if fence:
            marker = fence.group(1)[0]
            if not fenced:
                fenced = True
                fence_marker = marker
            elif marker == fence_marker:
                fenced = False
            continue
        if fenced:
            continue
        heading = re.match(r"^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$", line)
        if not heading:
            continue
        title = heading.group(1)
        explicit = re.search(r"\s*\{#([A-Za-z][\w:.-]*)\}\s*$", title)
        if explicit:
            anchors.add(explicit.group(1))
            title = title[: explicit.start()]
        title = re.sub(r"[*_`~\[\]]", "", title)
        slug = unicodedata.normalize("NFKD", title).encode("ascii", "ignore").decode("ascii")
        if not slug.strip():
            slug = unicodedata.normalize("NFKC", title).lower()
        slug = re.sub(r"[^\w\s-]", "", slug.lower()).strip()
        slug = re.sub(r"[-\s]+", "-", slug)
        if not slug:
            continue
        count = counts[slug]
        counts[slug] += 1
        anchors.add(slug if count == 0 else f"{slug}_{count}")
    return anchors


def validate_link(source: Path, raw_target: str, anchor_cache: dict[Path, set[str]]) -> None:
    target = raw_target.strip()
    parsed = urlsplit(target)
    if parsed.scheme in {"http", "https", "mailto", "data"} or target.startswith("{{"):
        return
    if parsed.scheme or parsed.netloc:
        add_error(f"unsupported link scheme in {relative(source)}: {raw_target}")
        return
    target_path = unquote(parsed.path)
    if target_path.startswith("/"):
        resolved = (DOCS / target_path.lstrip("/")).resolve()
    elif target_path:
        resolved = (source.parent / target_path).resolve()
    else:
        resolved = source.resolve()
    try:
        resolved.relative_to(ROOT)
    except ValueError:
        add_error(f"link escapes repository in {relative(source)}: {raw_target}")
        return
    if not resolved.exists():
        add_error(f"broken link in {relative(source)}: {raw_target}")
        return
    if parsed.fragment:
        if not resolved.is_file() or resolved.suffix.lower() != ".md":
            add_error(f"fragment target is not Markdown in {relative(source)}: {raw_target}")
            return
        fragment = unquote(parsed.fragment)
        anchors = anchor_cache.get(resolved)
        if anchors is None:
            anchors = markdown_anchors(resolved.read_text(encoding="utf-8"))
            anchor_cache[resolved] = anchors
        if fragment not in anchors:
            add_error(f"broken fragment in {relative(source)}: {raw_target}")


if not DOCS.is_dir():
    add_error("docs/ directory is missing")

for path in sorted(DOCS.rglob("*.yaml")) + sorted(DOCS.rglob("*.yml")):
    try:
        document = yaml.safe_load(path.read_text(encoding="utf-8"))
        if document is None:
            add_error(f"empty YAML document: {relative(path)}")
    except (OSError, yaml.YAMLError) as error:
        add_error(f"invalid YAML: {relative(path)}: {error}")

for path in sorted(ROOT.rglob("*.json")):
    if any(part in EXCLUDED_PARTS for part in path.parts):
        continue
    try:
        json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        add_error(f"invalid JSON: {relative(path)}: {error}")

markdown_paths = sorted(DOCS.rglob("*.md"))
anchor_cache = {
    path.resolve(): markdown_anchors(path.read_text(encoding="utf-8"))
    for path in markdown_paths
}
for path in markdown_paths:
    content = path.read_text(encoding="utf-8")
    if re.search(r"(?<![A-Za-z0-9_.-])doc/", content):
        add_error(f"deprecated doc/ reference in {relative(path)}; use docs/")
    for match in MARKDOWN_LINK.finditer(content):
        validate_link(path, match.group(1) or match.group(2), anchor_cache)
    # Decide on a per-document basis whether the source file is a plan describing future
    # artifacts that have not yet been produced. Only skip DOC_REFERENCE for plans with
    # `status: planned` or `status: blocked` in their YAML frontmatter; everything else
    # (including in-progress, complete, not-a-defect, and pre-frontmatter archives) keeps
    # the strict check so real documentation drift surfaces when those plans progress.
    source_relative = relative(path)
    is_deferred_plan = False
    if source_relative.startswith("docs/superpowers/plans/"):
        plan_status = re.search(r"^status:\s*(\S+)\s*$", content, re.MULTILINE)
        if plan_status and plan_status.group(1) in ("planned", "blocked"):
            is_deferred_plan = True
    for target in HTML_LINK.findall(content):
        validate_link(path, target, anchor_cache)
    for reference in DOC_REFERENCE.findall(content):
        if is_deferred_plan:
            continue
        clean_reference = reference.rstrip(".,;:)")
        if not (ROOT / clean_reference).is_file():
            add_error(f"missing document reference in {relative(path)}: {clean_reference}")

module_catalog = DOCS / "architecture/module-catalog.md"
if not module_catalog.is_file():
    add_error("module catalog is missing: docs/architecture/module-catalog.md")
elif re.search(
    r"^\|\s*`?Requests`?\s*\|",
    module_catalog.read_text(encoding="utf-8"),
    re.MULTILINE,
):
    add_error("module catalog declares Requests")

for path in DOCS.rglob("*"):
    if path.is_file() and path.suffix.lower() in {".svg", ".png"}:
        add_error(f"manual rendered diagram is not allowed: {relative(path)}")
for path in DOCS.rglob(".DS_Store"):
    add_error(f"macOS metadata file is not allowed: {relative(path)}")

if errors:
    for error in errors:
        print(f"ERROR: {error}", file=sys.stderr)
    print(f"Documentation validation failed with {len(errors)} error(s).", file=sys.stderr)
    raise SystemExit(1)

print("Documentation validation passed.")
PY
