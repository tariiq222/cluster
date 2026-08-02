from __future__ import annotations

import os
from pathlib import Path
from typing import Any

import httpx
import uvicorn
from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse, StreamingResponse
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="CLUSTER_GATEWAY_", extra="ignore")

    repo_root: Path = Path(__file__).resolve().parents[3]
    upstream_base_url: str = "http://127.0.0.1:4000"
    upstream_api_key: str = ""
    timeout_seconds: float = 180.0
    max_context_chars: int = 50000


settings = Settings()
app = FastAPI(title="Cluster Local Coding Gateway", version="0.1.0")

CONTEXT_FILES = (
    "AGENTS.md",
    ".ai/NORTH_STAR.md",
    ".ai/CURRENT_STATE.md",
    ".ai/CONSTRAINTS.md",
    ".ai/DEFINITION_OF_DONE.md",
)


def build_project_context() -> str:
    sections: list[str] = []
    remaining = settings.max_context_chars

    for relative_path in CONTEXT_FILES:
        path = settings.repo_root / relative_path
        if not path.is_file() or remaining <= 0:
            continue

        content = path.read_text(encoding="utf-8")[:remaining]
        sections.append(f"## {relative_path}\n\n{content}")
        remaining -= len(content)

    return (
        "You are coding inside the Cluster repository. The following project context is authoritative. "
        "Respect scope, architecture, constraints, and definition of done. Do not broaden the task. "
        "Before editing, identify the intended outcome, affected files, forbidden changes, and verification commands.\n\n"
        + "\n\n".join(sections)
    )


def inject_context(payload: dict[str, Any]) -> dict[str, Any]:
    messages = list(payload.get("messages") or [])
    output = dict(payload)
    output["messages"] = [
        {"role": "system", "content": build_project_context()},
        *messages,
    ]
    return output


def upstream_headers(request: Request) -> dict[str, str]:
    headers = {"content-type": "application/json"}
    authorization = request.headers.get("authorization")
    if settings.upstream_api_key:
        headers["authorization"] = f"Bearer {settings.upstream_api_key}"
    elif authorization:
        headers["authorization"] = authorization
    return headers


@app.get("/health")
async def health() -> dict[str, Any]:
    return {
        "status": "ok",
        "repo_root": str(settings.repo_root),
        "upstream": settings.upstream_base_url,
        "context_files": [path for path in CONTEXT_FILES if (settings.repo_root / path).is_file()],
    }


@app.post("/v1/chat/completions")
async def chat_completions(request: Request):
    try:
        payload = await request.json()
    except Exception as exc:
        raise HTTPException(status_code=400, detail="Invalid JSON payload") from exc

    payload = inject_context(payload)
    target = settings.upstream_base_url.rstrip("/") + "/v1/chat/completions"
    stream = bool(payload.get("stream"))

    client = httpx.AsyncClient(timeout=settings.timeout_seconds)
    try:
        upstream_request = client.build_request(
            "POST",
            target,
            headers=upstream_headers(request),
            json=payload,
        )
        response = await client.send(upstream_request, stream=stream)
    except httpx.HTTPError as exc:
        await client.aclose()
        raise HTTPException(status_code=502, detail=f"Upstream model gateway unavailable: {exc}") from exc

    if stream:
        async def iterator():
            try:
                async for chunk in response.aiter_bytes():
                    yield chunk
            finally:
                await response.aclose()
                await client.aclose()

        return StreamingResponse(
            iterator(),
            status_code=response.status_code,
            media_type=response.headers.get("content-type", "text/event-stream"),
        )

    body = await response.aread()
    content_type = response.headers.get("content-type", "application/json")
    await response.aclose()
    await client.aclose()

    if content_type.startswith("application/json"):
        try:
            return JSONResponse(status_code=response.status_code, content=response.json())
        except Exception:
            pass

    return JSONResponse(
        status_code=response.status_code,
        content={"upstream_body": body.decode("utf-8", errors="replace")},
    )


def run() -> None:
    uvicorn.run(
        "gateway.main:app",
        host=os.getenv("CLUSTER_GATEWAY_HOST", "127.0.0.1"),
        port=int(os.getenv("CLUSTER_GATEWAY_PORT", "9000")),
        reload=False,
    )


if __name__ == "__main__":
    run()
