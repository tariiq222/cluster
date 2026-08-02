from __future__ import annotations

import os
from collections.abc import AsyncIterator
from typing import Any

import httpx
import uvicorn
from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import Response, StreamingResponse

from gateway.context import inject_chat_context, inject_responses_context, load_task_contract
from gateway.settings import Settings

settings = Settings()
app = FastAPI(title="Cluster Local Coding Gateway", version="0.2.0")

HOP_BY_HOP_HEADERS = {
    "connection",
    "keep-alive",
    "proxy-authenticate",
    "proxy-authorization",
    "te",
    "trailers",
    "transfer-encoding",
    "upgrade",
    "host",
    "content-length",
}


def _upstream_headers(request: Request) -> dict[str, str]:
    headers = {
        key: value
        for key, value in request.headers.items()
        if key.lower() not in HOP_BY_HOP_HEADERS
    }
    if settings.upstream_api_key:
        headers["authorization"] = f"Bearer {settings.upstream_api_key}"
    return headers


def _response_headers(response: httpx.Response) -> dict[str, str]:
    return {
        key: value
        for key, value in response.headers.items()
        if key.lower() not in HOP_BY_HOP_HEADERS
    }


@app.get("/health")
async def health() -> dict[str, Any]:
    contract = load_task_contract(settings)
    return {
        "status": "ok",
        "repo_root": str(settings.repo_root),
        "upstream": settings.upstream_base_url,
        "task_contract": contract.id if contract else None,
        "task_mode": contract.mode if contract else None,
    }


async def _proxy(request: Request, upstream_path: str, payload: dict[str, Any] | None = None) -> Response:
    target = settings.upstream_v1 + upstream_path
    if request.url.query:
        target += "?" + request.url.query

    client = httpx.AsyncClient(timeout=settings.timeout_seconds)
    try:
        upstream_request = client.build_request(
            request.method,
            target,
            headers=_upstream_headers(request),
            json=payload,
            content=None if payload is not None else await request.body(),
        )
        response = await client.send(upstream_request, stream=True)
    except httpx.HTTPError as exc:
        await client.aclose()
        raise HTTPException(status_code=502, detail=f"Upstream model gateway unavailable: {exc}") from exc

    content_type = response.headers.get("content-type", "")
    is_stream = "text/event-stream" in content_type or bool(payload and payload.get("stream"))

    if is_stream:
        async def iterator() -> AsyncIterator[bytes]:
            try:
                async for chunk in response.aiter_bytes():
                    yield chunk
            finally:
                await response.aclose()
                await client.aclose()

        return StreamingResponse(
            iterator(),
            status_code=response.status_code,
            headers=_response_headers(response),
            media_type=None,
        )

    body = await response.aread()
    headers = _response_headers(response)
    await response.aclose()
    await client.aclose()
    return Response(content=body, status_code=response.status_code, headers=headers)


@app.get("/v1/models")
async def models(request: Request) -> Response:
    return await _proxy(request, "/models")


@app.post("/v1/chat/completions")
async def chat_completions(request: Request) -> Response:
    try:
        payload = await request.json()
        if not isinstance(payload, dict):
            raise ValueError("payload must be an object")
        payload = inject_chat_context(payload, settings)
    except (ValueError, RuntimeError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    return await _proxy(request, "/chat/completions", payload)


@app.post("/v1/responses")
async def responses(request: Request) -> Response:
    try:
        payload = await request.json()
        if not isinstance(payload, dict):
            raise ValueError("payload must be an object")
        payload = inject_responses_context(payload, settings)
    except (ValueError, RuntimeError) as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc
    return await _proxy(request, "/responses", payload)


def run() -> None:
    uvicorn.run(
        "gateway.main:app",
        host=os.getenv("CLUSTER_GATEWAY_HOST", "127.0.0.1"),
        port=int(os.getenv("CLUSTER_GATEWAY_PORT", "9000")),
        reload=False,
    )


if __name__ == "__main__":
    run()
