#!/usr/bin/env python3
"""Regenerate the OpenAPI split bundles via redocly and verify post-conditions.

Sibling module of inventory-routes.py. Provides the bundle pipeline used by
``--mode reconcile --write --bundle`` to run ``npm run api:bundle`` and assert
that:

  * ``docs/contracts/api/w1-1.openapi.yaml`` is FROZEN (no git diff);
  * ``w1-2.openapi.yaml`` and ``r1-screens.openapi.yaml`` are append-only
    ``$ref`` additions (no deletions, no inline bodies);
  * ``apps/web/.orval/cluster.openapi.yaml`` (the W1.1 bundle) is deterministic;
  * ``cluster-w1-2.openapi.yaml`` and ``cluster-r1-screens.openapi.yaml`` are
    refreshed bundles.

The summary output is written to ``.minimax-flow/bundle-summary.json``.
"""

import dataclasses
import datetime
import hashlib
import json
import pathlib
import re
import subprocess
import sys
from typing import Iterable, List, Tuple


WEB_DIR = "apps/web"
SUMMARY_PATH = pathlib.Path(".minimax-flow/bundle-summary.json")

SPLIT_SOURCES = (
    "docs/contracts/api/w1-1.openapi.yaml",
    "docs/contracts/api/w1-2.openapi.yaml",
    "docs/contracts/api/r1-screens.openapi.yaml",
)

FROZEN_SPLIT = SPLIT_SOURCES[0]
APPEND_ONLY_SPLITS = (SPLIT_SOURCES[1], SPLIT_SOURCES[2])

BUNDLE_TARGETS = (
    {
        "source": SPLIT_SOURCES[0],
        "output": "apps/web/.orval/cluster.openapi.yaml",
        "frozen": True,
    },
    {
        "source": SPLIT_SOURCES[1],
        "output": "apps/web/.orval/cluster-w1-2.openapi.yaml",
        "frozen": False,
    },
    {
        "source": SPLIT_SOURCES[2],
        "output": "apps/web/.orval/cluster-r1-screens.openapi.yaml",
        "frozen": False,
    },
)

PATH_KEY_RE = re.compile(r"^  (/[^:\n]+):")
REF_LINE_RE = re.compile(r"^\s*/[^:\n]+:\s*\{\$ref:")


@dataclasses.dataclass(frozen=True)
class FileSnapshot:
    size: int
    sha256: str
    mtime: float
    path_count: int

    def to_dict(self) -> dict:
        return dataclasses.asdict(self)


@dataclasses.dataclass(frozen=True)
class BundleObservation:
    source: str
    output: str
    frozen: bool
    pre: FileSnapshot
    post: FileSnapshot

    def to_dict(self) -> dict:
        return {
            "source": self.source,
            "output": self.output,
            "frozen": self.frozen,
            "pre_size": self.pre.size,
            "post_size": self.post.size,
            "pre_sha256": self.pre.sha256,
            "post_sha256": self.post.sha256,
            "pre_path_count": self.pre.path_count,
            "post_path_count": self.post.path_count,
            "path_count": self.post.path_count,
        }


def _now_iso() -> str:
    return datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _run(cmd: Iterable[str], cwd: pathlib.Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        list(cmd),
        cwd=cwd,
        capture_output=True,
        text=True,
        check=False,
    )


def _read_text(path: pathlib.Path) -> str:
    return path.read_text(encoding="utf-8") if path.exists() else ""


def sha256_of(path: pathlib.Path) -> str:
    h = hashlib.sha256()
    h.update(path.read_bytes())
    return h.hexdigest()


def path_count_of(yaml_path: pathlib.Path) -> int:
    if not yaml_path.exists():
        return 0
    return sum(1 for line in _read_text(yaml_path).splitlines() if PATH_KEY_RE.match(line))


def snapshot(path: pathlib.Path) -> FileSnapshot:
    if not path.exists():
        return FileSnapshot(size=0, sha256="", mtime=0.0, path_count=0)
    st = path.stat()
    return FileSnapshot(
        size=st.st_size,
        sha256=sha256_of(path),
        mtime=st.st_mtime,
        path_count=path_count_of(path),
    )


def capture_bundle_snapshots(repo_root: pathlib.Path) -> dict[str, FileSnapshot]:
    return {target["output"]: snapshot(repo_root / target["output"]) for target in BUNDLE_TARGETS}


def run_npm_bundle(repo_root: pathlib.Path) -> subprocess.CompletedProcess[str]:
    web = repo_root / WEB_DIR
    if not web.is_dir():
        raise FileNotFoundError(f"missing web dir: {web}")
    return _run(["npm", "run", "api:bundle"], web)


def git_diff(repo_root: pathlib.Path, rel_path: str) -> str:
    proc = _run(["git", "diff", "--no-color", "--", rel_path], repo_root)
    if proc.returncode not in (0, 1):
        raise RuntimeError(f"git diff {rel_path} failed: {proc.stderr or proc.stdout}")
    return proc.stdout


def _classify_diff_line(line: str) -> str:
    if line.startswith("---") or line.startswith("+++") or line.startswith("@@"):
        return "header"
    if line.startswith("-"):
        return "deletion"
    if line.startswith("+"):
        return "addition"
    return "context"


def _classify_addition(added_line: str) -> str:
    stripped = added_line.lstrip("+").lstrip()
    if REF_LINE_RE.match(stripped):
        return "ref"
    return "non-ref"


def inspect_split_diff(diff_text: str) -> dict:
    additions = 0
    deletions = 0
    ref_additions = 0
    non_ref_additions = 0
    violations: list[str] = []

    for line in diff_text.splitlines():
        kind = _classify_diff_line(line)
        if kind == "header":
            continue
        if kind == "deletion":
            deletions += 1
            violations.append(f"DEL: {line}")
            continue
        if kind == "addition":
            additions += 1
            classification = _classify_addition(line)
            if classification == "ref":
                ref_additions += 1
            else:
                non_ref_additions += 1
                violations.append(f"NON-REF-ADD: {line}")

    return {
        "ok": deletions == 0 and non_ref_additions == 0,
        "additions": additions,
        "deletions": deletions,
        "ref_additions": ref_additions,
        "non_ref_additions": non_ref_additions,
        "all_ref_additions": non_ref_additions == 0 and additions > 0,
        "violations": violations,
    }


def inspect_frozen_diff(diff_text: str) -> dict:
    additions = 0
    deletions = 0
    for line in diff_text.splitlines():
        kind = _classify_diff_line(line)
        if kind == "header":
            continue
        if kind == "deletion":
            deletions += 1
        elif kind == "addition":
            additions += 1

    return {
        "ok": additions == 0 and deletions == 0,
        "additions": additions,
        "deletions": deletions,
        "lines": additions + deletions,
    }


def reconcile(repo_root: pathlib.Path) -> dict:
    pre = capture_bundle_snapshots(repo_root)
    proc = run_npm_bundle(repo_root)
    if proc.returncode != 0:
        raise RuntimeError(
            f"npm run api:bundle failed (exit={proc.returncode}): "
            f"{proc.stderr.strip() or proc.stdout.strip()}"
        )
    post = capture_bundle_snapshots(repo_root)

    observations: list[BundleObservation] = []
    for target in BUNDLE_TARGETS:
        observations.append(
            BundleObservation(
                source=target["source"],
                output=target["output"],
                frozen=target["frozen"],
                pre=pre[target["output"]],
                post=post[target["output"]],
            )
        )

    split_diffs = {}
    for rel in SPLIT_SOURCES:
        diff_text = git_diff(repo_root, rel)
        if rel == FROZEN_SPLIT:
            split_diffs[rel] = inspect_frozen_diff(diff_text)
        else:
            split_diffs[rel] = inspect_split_diff(diff_text)

    summary = {
        "timestamp": _now_iso(),
        "command": "npm run api:bundle",
        "split_source_diffs": split_diffs,
        "bundles": {
            obs.output.split("/")[-1]: obs.to_dict() for obs in observations
        },
    }

    write_summary(repo_root, summary)

    violations = _collect_violations(observations, split_diffs)
    if violations:
        raise RuntimeError("bundle reconciliation produced violations: " + "; ".join(violations))

    return summary


def _collect_violations(
    observations: list[BundleObservation],
    split_diffs: dict[str, dict],
) -> list[str]:
    problems: list[str] = []

    for obs in observations:
        if obs.frozen and obs.pre.sha256 and obs.pre.sha256 != obs.post.sha256:
            problems.append(
                f"{obs.output} SHA changed despite frozen source ({obs.pre.sha256[:12]}->{obs.post.sha256[:12]})"
            )
        if obs.pre.mtime > 0 and obs.post.mtime < obs.pre.mtime:
            problems.append(f"{obs.output} mtime regressed ({obs.pre.mtime}->{obs.post.mtime})")

    if not split_diffs[FROZEN_SPLIT]["ok"]:
        problems.append(f"{FROZEN_SPLIT} must remain frozen: {split_diffs[FROZEN_SPLIT]}")

    for rel in APPEND_ONLY_SPLITS:
        info = split_diffs[rel]
        if info["deletions"] > 0 or info["non_ref_additions"] > 0:
            problems.append(f"{rel} is not append-only ref-only: {info}")

    return problems


def write_summary(repo_root: pathlib.Path, summary: dict) -> pathlib.Path:
    out_path = repo_root / SUMMARY_PATH
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text(json.dumps(summary, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    return out_path


def main(argv: Iterable[str] = None) -> int:
    import argparse
    import sys

    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--repo-root",
        default=str(pathlib.Path(__file__).resolve().parent.parent),
        help="Repository root (default: parent of this script)",
    )
    args = parser.parse_args(list(argv) if argv is not None else None)

    repo_root = pathlib.Path(args.repo_root).resolve()

    try:
        summary = reconcile(repo_root)
    except RuntimeError as exc:
        print(str(exc), file=sys.stderr)
        return 1

    bundles = summary["bundles"]
    print(f"bundled={len(bundles)}")
    for name in sorted(bundles):
        info = bundles[name]
        print(f"  {name}: {info['pre_size']}->{info['post_size']}B, paths={info['path_count']}")
    print(f"summary={SUMMARY_PATH}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
