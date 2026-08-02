from __future__ import annotations

from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="CLUSTER_GATEWAY_", extra="ignore")

    repo_root: Path = Path(__file__).resolve().parents[3]
    upstream_base_url: str = "http://127.0.0.1:4000"
    upstream_api_key: str = ""
    timeout_seconds: float = 180.0
    max_context_chars: int = 60_000
    task_contract_path: str = ".ai/runtime/current-task.json"
    require_task_contract: bool = False

    @property
    def upstream_v1(self) -> str:
        return self.upstream_base_url.rstrip("/") + "/v1"
