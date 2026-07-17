#!/usr/bin/env bash
set -euo pipefail

readonly root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

for script in scripts/*.sh; do
  bash -n "$script"
done

if ! command -v python3 >/dev/null 2>&1; then
  printf '%s\n' 'ERROR: python3 is required for documentation validation.' >&2
  exit 2
fi

python3 scripts/validate-notifications-openapi.py
python3 scripts/validate-work-records-openapi.py

python3 - <<'PY'
from __future__ import annotations

import datetime as dt
import json
import os
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
        "ERROR: PyYAML is required; install requirements-docs.txt before validation.",
        file=sys.stderr,
    )
    raise SystemExit(2)


ROOT = Path.cwd().resolve()
DOCS = ROOT / "docs"
CATALOG = DOCS / "catalog.yaml"
REQUIRED_FIELDS = (
    "doc_id",
    "title",
    "type",
    "status",
    "version",
    "date",
    "owner",
    "reviewers",
    "classification",
    "review_cycle",
    "sources",
    "references",
)
ALLOWED_TYPES = {
    "governance",
    "product",
    "architecture",
    "domain",
    "data-security",
    "operations",
    "engineering",
    "contracts",
    "plans",
    "adr",
}
ALLOWED_STATUSES = {"draft", "proposed", "accepted", "rejected", "superseded"}
ALLOWED_CLASSIFICATIONS = {"public", "internal", "confidential", "top_secret"}
SEMVER = re.compile(
    r"^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)"
    r"(?:-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)"
    r"(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?"
    r"(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$"
)
DOC_ID = re.compile(r"^[A-Z][A-Z0-9]*(?:-[A-Z0-9]+)+$")
UNFINISHED = re.compile(r"\b(?:TODO|TBD|FIXME)\b", re.IGNORECASE)
MARKDOWN_LINK = re.compile(
    r"!?\[[^\]]*\]\((?:<([^>]+)>|([^\s)]+))(?:\s+[\"'][^\"']*[\"'])?\)"
)
HTML_LINK = re.compile(r"<(?:a|img)\b[^>]+(?:href|src)=[\"']([^\"']+)[\"']", re.IGNORECASE)
DOC_REFERENCE = re.compile(
    r"(?<![A-Za-z0-9_.-])(docs/[A-Za-z0-9_.\-/]+\.(?:md|yaml|yml|json|mmd))"
)
errors: list[str] = []


def add_error(message: str) -> None:
    errors.append(message)


def load_yaml(path: Path):
    try:
        return yaml.safe_load(path.read_text(encoding="utf-8"))
    except (OSError, yaml.YAMLError) as error:
        add_error(f"invalid YAML: {path.relative_to(ROOT)}: {error}")
        return None


def relative(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def parse_frontmatter(path: Path) -> tuple[dict, str] | None:
    content = path.read_text(encoding="utf-8")
    lines = content.splitlines(keepends=True)
    if not lines or lines[0].strip() != "---":
        add_error(f"missing front matter: {relative(path)}")
        return None
    closing = next((index for index, line in enumerate(lines[1:], 1) if line.strip() == "---"), None)
    if closing is None:
        add_error(f"unterminated front matter: {relative(path)}")
        return None
    try:
        metadata = yaml.safe_load("".join(lines[1:closing])) or {}
    except yaml.YAMLError as error:
        add_error(f"invalid front matter: {relative(path)}: {error}")
        return None
    if not isinstance(metadata, dict):
        add_error(f"front matter must be a mapping: {relative(path)}")
        return None
    return metadata, "".join(lines[closing + 1 :])


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
            parsed_frontmatter = parse_frontmatter(resolved)
            anchors = markdown_anchors(parsed_frontmatter[1] if parsed_frontmatter else "")
            anchor_cache[resolved] = anchors
        if fragment not in anchors:
            add_error(f"broken fragment in {relative(source)}: {raw_target}")


if not DOCS.is_dir():
    add_error("docs/ directory is missing")
if (ROOT / "doc").exists():
    add_error("deprecated doc/ path exists; use docs/ only")

for directory in sorted(path for path in DOCS.rglob("*") if path.is_dir()):
    try:
        next(directory.iterdir())
    except StopIteration:
        add_error(f"empty documentation directory: {relative(directory)}/")

yaml_paths = [ROOT / ".github/workflows/ci.yml", ROOT / "mkdocs.yml"]
yaml_paths.extend(sorted(DOCS.rglob("*.yaml")))
yaml_paths.extend(sorted(DOCS.rglob("*.yml")))
yaml_data = {path: load_yaml(path) for path in dict.fromkeys(yaml_paths) if path.is_file()}

EXCLUDED_JSON_PARTS = {
    ".git",
    ".opencode",
    ".pi",
    ".pi-subagents",
    ".planning",
    "node_modules",
}

for path in sorted(ROOT.rglob("*.json")):
    if any(part in EXCLUDED_JSON_PARTS for part in path.parts):
        continue
    try:
        json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        add_error(f"invalid JSON: {relative(path)}: {error}")

markdown_paths = sorted(DOCS.rglob("*.md"))
checked_markdown_paths = [ROOT / "README.md", *markdown_paths]
frontmatter_by_catalog_path: dict[str, dict] = {}
doc_ids: dict[str, str] = {}
bodies: dict[Path, str] = {}
frontmatter_fragments: list[tuple[Path, str, str, Path]] = []

for path in checked_markdown_paths:
    parsed = parse_frontmatter(path)
    if parsed is None:
        continue
    metadata, body = parsed
    bodies[path.resolve()] = body
    missing = [field for field in REQUIRED_FIELDS if field not in metadata]
    if missing:
        add_error(f"missing required front matter fields in {relative(path)}: {', '.join(missing)}")
    if metadata.get("type") != "adr":
        extra = sorted(set(metadata) - set(REQUIRED_FIELDS))
        if extra:
            add_error(f"unsupported front matter fields in {relative(path)}: {', '.join(extra)}")
    for field in ("doc_id", "title", "owner", "review_cycle"):
        if not isinstance(metadata.get(field), str) or not metadata.get(field, "").strip():
            add_error(f"front matter {field} must be a non-empty string: {relative(path)}")
    doc_id = metadata.get("doc_id")
    if isinstance(doc_id, str):
        if not DOC_ID.fullmatch(doc_id):
            add_error(f"invalid doc_id in {relative(path)}: {doc_id}")
        elif doc_id in doc_ids:
            add_error(f"duplicate doc_id {doc_id}: {doc_ids[doc_id]} and {relative(path)}")
        else:
            doc_ids[doc_id] = relative(path)
    if metadata.get("type") not in ALLOWED_TYPES:
        add_error(f"invalid type in {relative(path)}: {metadata.get('type')!r}")
    if metadata.get("status") not in ALLOWED_STATUSES:
        add_error(f"invalid status in {relative(path)}: {metadata.get('status')!r}")
    if metadata.get("classification") not in ALLOWED_CLASSIFICATIONS:
        add_error(f"invalid classification in {relative(path)}: {metadata.get('classification')!r}")
    version = metadata.get("version")
    if not isinstance(version, str) or not SEMVER.fullmatch(version):
        add_error(f"invalid SemVer in {relative(path)}: {version!r}")
    date = metadata.get("date")
    if isinstance(date, dt.datetime):
        valid_date = False
    elif isinstance(date, dt.date):
        valid_date = True
    elif isinstance(date, str):
        try:
            dt.date.fromisoformat(date)
            valid_date = bool(re.fullmatch(r"\d{4}-\d{2}-\d{2}", date))
        except ValueError:
            valid_date = False
    else:
        valid_date = False
    if not valid_date:
        add_error(f"invalid ISO date in {relative(path)}: {date!r}")
    reviewers = metadata.get("reviewers")
    if (
        not isinstance(reviewers, list)
        or len(reviewers) < 2
        or len(set(reviewers)) != len(reviewers)
        or any(not isinstance(item, str) or not item.strip() for item in reviewers)
    ):
        add_error(f"reviewers must contain at least two distinct roles: {relative(path)}")
    for field in ("sources", "references"):
        values = metadata.get(field)
        if not isinstance(values, list) or any(not isinstance(item, str) for item in values):
            add_error(f"front matter {field} must be a list of paths: {relative(path)}")
            continue
        for value in values:
            parsed_value = urlsplit(value)
            target_path = unquote(parsed_value.path)
            candidate = (ROOT / target_path).resolve()
            if parsed_value.scheme or parsed_value.netloc or not target_path.startswith("docs/") or not candidate.is_file():
                add_error(f"invalid {field} path in {relative(path)}: {value}")
            else:
                try:
                    candidate.relative_to(DOCS.resolve())
                except ValueError:
                    add_error(f"{field} path escapes docs/ in {relative(path)}: {value}")
                if parsed_value.fragment:
                    frontmatter_fragments.append((path, field, unquote(parsed_value.fragment), candidate))
    if path.is_relative_to(DOCS):
        frontmatter_by_catalog_path[path.relative_to(DOCS).as_posix()] = metadata

anchor_cache = {path: markdown_anchors(body) for path, body in bodies.items()}
for source, field, fragment, target in frontmatter_fragments:
    if target.suffix.lower() != ".md" or fragment not in anchor_cache.get(target, set()):
        add_error(f"broken {field} fragment in {relative(source)}: {relative(target)}#{fragment}")
for path in checked_markdown_paths:
    content = path.read_text(encoding="utf-8")
    body = bodies.get(path.resolve(), content)
    if UNFINISHED.search(content):
        add_error(f"unfinished marker in {relative(path)}")
    if re.search(r"(?<![A-Za-z0-9_.-])doc/", content):
        add_error(f"deprecated doc/ reference in {relative(path)}; use docs/")
    for match in MARKDOWN_LINK.finditer(body):
        validate_link(path, match.group(1) or match.group(2), anchor_cache)
    for target in HTML_LINK.findall(body):
        validate_link(path, target, anchor_cache)
    for reference in DOC_REFERENCE.findall(body):
        clean_reference = reference.rstrip(".,;:)")
        if not (ROOT / clean_reference).is_file():
            add_error(f"missing document reference in {relative(path)}: {clean_reference}")
    declares_requests = any(
        (re.search(r"\bmodule\s+Requests\b|موديول\s+Requests\b", line) and "لا يوجد" not in line)
        for line in body.splitlines()
    )
    if declares_requests:
        add_error(f"forbidden Requests module reference in {relative(path)}; use WorkRecords")

mkdocs = yaml_data.get(ROOT / "mkdocs.yml")
nav_paths: list[str] = []


def walk_nav(node) -> None:
    if isinstance(node, str):
        nav_paths.append(node)
    elif isinstance(node, list):
        for item in node:
            walk_nav(item)
    elif isinstance(node, dict):
        for item in node.values():
            walk_nav(item)


if not isinstance(mkdocs, dict):
    add_error("mkdocs.yml must contain a mapping")
else:
    walk_nav(mkdocs.get("nav", []))
expected_nav = [path.relative_to(DOCS).as_posix() for path in markdown_paths]
nav_counts = Counter(nav_paths)
for path in sorted(set(expected_nav) | set(nav_paths)):
    count = nav_counts[path]
    if path in expected_nav and count != 1:
        add_error(f"Markdown document must appear in nav exactly once: {path} (found {count})")
    elif path not in expected_nav:
        add_error(f"nav target is not a Markdown document: {path}")
for path in nav_paths:
    if not (DOCS / path).is_file():
        add_error(f"nav target is missing: docs/{path}")

catalog_data = yaml_data.get(CATALOG)
catalog_documents = catalog_data.get("documents") if isinstance(catalog_data, dict) else None
if not isinstance(catalog_documents, list):
    add_error("docs/catalog.yaml documents must be a list")
    catalog_documents = []
catalog_paths: list[str] = []
for index, document in enumerate(catalog_documents, 1):
    if not isinstance(document, dict) or not isinstance(document.get("path"), str):
        add_error(f"invalid catalog entry at documents[{index}]")
        continue
    catalog_paths.append(document["path"])
catalog_counts = Counter(catalog_paths)
document_paths = sorted(
    path.relative_to(DOCS).as_posix() for path in DOCS.rglob("*") if path.is_file()
)
for path in sorted(set(document_paths) | set(catalog_paths)):
    count = catalog_counts[path]
    if path in document_paths and count != 1:
        add_error(f"docs file must appear in catalog exactly once: {path} (found {count})")
    elif path not in document_paths:
        add_error(f"catalog entry is orphaned: {path}")
if catalog_counts["catalog.yaml"] != 1:
    add_error(f"catalog must contain its own entry exactly once (found {catalog_counts['catalog.yaml']})")

for document in catalog_documents:
    if not isinstance(document, dict):
        continue
    path = document.get("path")
    metadata = frontmatter_by_catalog_path.get(path)
    if metadata is None:
        continue
    for field in ("title", "status", "owner"):
        if document.get(field) != metadata.get(field):
            add_error(
                f"catalog/front matter mismatch for {path} {field}: "
                f"{document.get(field)!r} != {metadata.get(field)!r}"
            )

module_catalog = DOCS / "architecture/module-catalog.md"
if module_catalog.is_file():
    if re.search(r"^\|\s*`?Requests`?\s*\|", module_catalog.read_text(encoding="utf-8"), re.MULTILINE):
        add_error("module catalog declares Requests")
else:
    add_error("module catalog is missing: docs/architecture/module-catalog.md")

for path in DOCS.rglob("*"):
    if path.is_file() and path.suffix.lower() in {".svg", ".png"}:
        add_error(f"manual rendered diagram is not allowed: {relative(path)}")
for path in ROOT.rglob(".DS_Store"):
    add_error(f"macOS metadata file is not allowed: {relative(path)}")

if errors:
    for error in errors:
        print(f"ERROR: {error}", file=sys.stderr)
    print(f"Documentation validation failed with {len(errors)} error(s).", file=sys.stderr)
    raise SystemExit(1)

print("Documentation validation passed.")
PY
