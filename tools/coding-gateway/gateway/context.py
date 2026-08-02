from __future__ import annotations

import json
from pathlib import Path
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field, ValidationError

from gateway.settings import Settings

CONTEXT_MARKER = "CLUSTER_GATEWAY_CONTEXT_V1"
STATIC_CONTEXT_FILES = (
    "AGENTS.md",
    "PRODUCT.md",
    ".ai/NORTH_STAR.md",
    ".ai/CURRENT_STATE.md",
    ".ai/CONSTRAINTS.md",
    ".ai/DEFINITION_OF_DONE.md",
)


class TaskContract(BaseModel):
    model_config = ConfigDict(extra="forbid")

    id: str = Field(min_length=1, max_length=100)
    title: str = Field(min_length=1, max_length=300)
    mode: Literal["plan", "implement", "review"]
    objective: str = Field(min_length=1)
    acceptance_criteria: list[str] = Field(default_factory=list)
    allowed_paths: list[str] = Field(default_factory=list)
    forbidden_paths: list[str] = Field(default_factory=list)
    required_context: list[str] = Field(default_factory=list)
    verification_commands: list[str] = Field(default_factory=list)
    risk: Literal["low", "medium", "high", "critical"] = "medium"
    notes: str = ""


def _safe_path(repo_root: Path, relative_path: str) -> Path:
    root = repo_root.resolve()
    candidate = (root / relative_path).resolve()
    if candidate != root and root not in candidate.parents:
        raise ValueError(f"Path escapes repository root: {relative_path}")
    return candidate


def _read_text(repo_root: Path, relative_path: str, limit: int) -> str:
    path = _safe_path(repo_root, relative_path)
    if not path.is_file() or limit <= 0:
        return ""
    return path.read_text(encoding="utf-8")[:limit]


def load_task_contract(settings: Settings) -> TaskContract | None:
    path = _safe_path(settings.repo_root, settings.task_contract_path)
    if not path.is_file():
        if settings.require_task_contract:
            raise RuntimeError(f"Required task contract is missing: {settings.task_contract_path}")
        return None

    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
        return TaskContract.model_validate(raw)
    except (json.JSONDecodeError, ValidationError) as exc:
        raise RuntimeError(f"Invalid task contract {settings.task_contract_path}: {exc}") from exc


def _bullet_lines(values: list[str]) -> str:
    if not values:
        return "- None declared"
    return "\n".join(f"- {value}" for value in values)


def render_task_contract(contract: TaskContract) -> str:
    return f"""## Current Task Contract

- ID: {contract.id}
- Title: {contract.title}
- Mode: {contract.mode}
- Risk: {contract.risk}

### Objective

{contract.objective}

### Acceptance Criteria

{_bullet_lines(contract.acceptance_criteria)}

### Allowed Paths

{_bullet_lines(contract.allowed_paths)}

### Forbidden Paths

{_bullet_lines(contract.forbidden_paths)}

### Verification Commands

{_bullet_lines(contract.verification_commands)}

### Notes

{contract.notes or 'None'}
"""


def build_project_context(settings: Settings) -> str:
    sections: list[str] = []
    remaining = settings.max_context_chars

    task_contract = load_task_contract(settings)
    if task_contract is not None:
        rendered = render_task_contract(task_contract)[:remaining]
        sections.append(rendered)
        remaining -= len(rendered)

    paths = list(STATIC_CONTEXT_FILES)
    if task_contract is not None:
        paths.extend(task_contract.required_context)

    seen: set[str] = set()
    for relative_path in paths:
        if relative_path in seen or remaining <= 0:
            continue
        seen.add(relative_path)
        content = _read_text(settings.repo_root, relative_path, remaining)
        if not content:
            continue
        sections.append(f"## {relative_path}\n\n{content}")
        remaining -= len(content)

    task_rule = (
        "A current task contract is present. Its mode, objective, allowed paths, forbidden paths, "
        "acceptance criteria, and verification commands are hard boundaries."
        if task_contract is not None
        else "No current task contract is present. Keep changes minimal and ask for a contract before broad or high-risk implementation."
    )

    return f"""[{CONTEXT_MARKER}]
You are coding inside the Cluster repository. This gateway context is authoritative and applies in addition to the user's request.

Binding behavior:
1. Preserve the product north star, module ownership, API contracts, security invariants, and design rules.
2. Do not broaden the task, rewrite adjacent systems, add dependencies, or modify unrelated files.
3. Before implementation, state the objective, intended files, explicit non-goals, risk, and verification commands.
4. In plan mode, do not edit files or run destructive commands.
5. If a requested change conflicts with a binding constraint, surface the conflict instead of guessing.
6. Completion requires reviewing the full diff and reporting what changed, what did not change, verification performed, and remaining risk.

{task_rule}

""" + "\n\n".join(sections)


def inject_chat_context(payload: dict[str, Any], settings: Settings) -> dict[str, Any]:
    messages = payload.get("messages")
    if not isinstance(messages, list):
        raise ValueError("messages must be a list")

    for message in messages:
        if isinstance(message, dict) and CONTEXT_MARKER in str(message.get("content", "")):
            return dict(payload)

    output = dict(payload)
    output["messages"] = [
        {"role": "system", "content": build_project_context(settings)},
        *messages,
    ]
    return output


def inject_responses_context(payload: dict[str, Any], settings: Settings) -> dict[str, Any]:
    existing = payload.get("instructions")
    if isinstance(existing, str) and CONTEXT_MARKER in existing:
        return dict(payload)

    output = dict(payload)
    context = build_project_context(settings)
    output["instructions"] = f"{context}\n\n{existing}" if isinstance(existing, str) and existing else context
    return output
