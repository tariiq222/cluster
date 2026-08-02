from __future__ import annotations

import json
from pathlib import Path

import pytest

from gateway.context import (
    CONTEXT_MARKER,
    build_project_context,
    inject_chat_context,
    inject_responses_context,
)
from gateway.settings import Settings


def make_repo(tmp_path: Path) -> Path:
    (tmp_path / ".ai/runtime").mkdir(parents=True)
    (tmp_path / "AGENTS.md").write_text("# Rules\nKeep changes small.", encoding="utf-8")
    (tmp_path / "PRODUCT.md").write_text("# Product\nGoverned work.", encoding="utf-8")
    for name in ("NORTH_STAR", "CURRENT_STATE", "CONSTRAINTS", "DEFINITION_OF_DONE"):
        (tmp_path / f".ai/{name}.md").write_text(f"# {name}\nBinding.", encoding="utf-8")
    return tmp_path


def test_injects_static_context_and_task_contract(tmp_path: Path) -> None:
    repo = make_repo(tmp_path)
    task = {
        "id": "TASK-1",
        "title": "Adjust one screen",
        "mode": "implement",
        "objective": "Change only the target screen.",
        "acceptance_criteria": ["The screen renders"],
        "allowed_paths": ["apps/web/src/features/example/**"],
        "forbidden_paths": ["apps/api/**"],
        "required_context": [],
        "verification_commands": ["npm test"],
        "risk": "medium",
        "notes": "No API changes.",
    }
    (repo / ".ai/runtime/current-task.json").write_text(json.dumps(task), encoding="utf-8")
    settings = Settings(repo_root=repo)

    output = inject_chat_context(
        {"model": "test", "messages": [{"role": "user", "content": "Do it"}]},
        settings,
    )

    system = output["messages"][0]["content"]
    assert CONTEXT_MARKER in system
    assert "TASK-1" in system
    assert "apps/api/**" in system
    assert "# Rules" in system
    assert output["messages"][1]["role"] == "user"


def test_context_is_not_injected_twice(tmp_path: Path) -> None:
    repo = make_repo(tmp_path)
    settings = Settings(repo_root=repo)
    once = inject_chat_context({"messages": [{"role": "user", "content": "Hi"}]}, settings)
    twice = inject_chat_context(once, settings)
    assert twice == once
    assert sum(CONTEXT_MARKER in str(message.get("content", "")) for message in twice["messages"]) == 1


def test_responses_instructions_preserve_existing_text(tmp_path: Path) -> None:
    repo = make_repo(tmp_path)
    settings = Settings(repo_root=repo)
    output = inject_responses_context({"instructions": "Existing system rule"}, settings)
    assert CONTEXT_MARKER in output["instructions"]
    assert output["instructions"].endswith("Existing system rule")


def test_required_task_contract_can_be_enforced(tmp_path: Path) -> None:
    repo = make_repo(tmp_path)
    settings = Settings(repo_root=repo, require_task_contract=True)
    with pytest.raises(RuntimeError, match="Required task contract is missing"):
        build_project_context(settings)


def test_required_context_cannot_escape_repo(tmp_path: Path) -> None:
    repo = make_repo(tmp_path)
    task = {
        "id": "TASK-2",
        "title": "Unsafe context",
        "mode": "plan",
        "objective": "Read outside repo",
        "required_context": ["../secret.txt"],
    }
    (repo / ".ai/runtime/current-task.json").write_text(json.dumps(task), encoding="utf-8")
    settings = Settings(repo_root=repo)
    with pytest.raises(ValueError, match="escapes repository root"):
        build_project_context(settings)
