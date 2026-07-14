import hashlib
import os
import re
import time
from typing import Any

import requests
from fastapi import FastAPI
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field


RUNTIME_LEVEL = "L3-adapter"
SERVICE = "rag-nemotron"


def env_bool(name: str, default: str = "0") -> bool:
    return os.getenv(name, default).strip().lower() in {"1", "true", "yes", "on"}


def env_int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except ValueError:
        return default


def setting(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


def device_status() -> dict[str, Any]:
    device = setting("NEMOTRON_DEVICE", "auto").lower()
    if device not in {"auto", "cpu", "gpu"}:
        device = "auto"
    return {
        "requested": device,
        "use_gpu": env_bool("NEMOTRON_USE_GPU", "1"),
        "visible_devices": setting("GPU_VISIBLE_DEVICES", "all"),
        "fallback_to_cpu": env_bool("NEMOTRON_GPU_FALLBACK_TO_CPU", "1"),
    }


def error(status: int, code: str, message: str, detail: str = "") -> JSONResponse:
    payload: dict[str, Any] = {"ok": False, "error": code, "message": message}
    if detail:
        payload["detail"] = detail[:500]
    return JSONResponse(status_code=status, content=payload)


class RagRequest(BaseModel):
    operation: str = Field(default="rerank")
    texts: list[str] = Field(default_factory=list)
    query: str = ""
    passages: list[str] = Field(default_factory=list)
    top_k: int = Field(default=5, ge=1, le=100)
    real_inference: bool = False


app = FastAPI(title="3waAIHub Nemotron RAG Adapter")


@app.get("/health")
def health() -> dict[str, Any]:
    return {
        "ok": True,
        "service": SERVICE,
        "ready": True,
        "runtime_level": RUNTIME_LEVEL,
        "models": {
            "embed": setting("NEMOTRON_EMBED_MODEL", "nvidia/llama-nemotron-embed-300m-v2"),
            "rerank": setting("NEMOTRON_RERANK_MODEL", "nvidia/llama-nemotron-rerank-500m-v2"),
        },
        "backend": {
            "embed_configured": bool(setting("NEMOTRON_EMBED_URL")),
            "rerank_configured": bool(setting("NEMOTRON_RERANK_URL")),
        },
        "device": device_status(),
    }


def validate_texts(texts: list[str]) -> list[str]:
    max_texts = env_int("NEMOTRON_MAX_TEXTS", 64)
    cleaned = [str(text).strip() for text in texts if str(text).strip()]
    if not cleaned:
        raise ValueError("texts are required")
    if len(cleaned) > max_texts:
        raise OverflowError(f"too many texts, max {max_texts}")
    max_chars = 4096
    if any(len(text) > max_chars for text in cleaned):
        raise OverflowError(f"text exceeds {max_chars} characters")
    return cleaned


def mock_embedding(text: str) -> list[float]:
    dim = env_int("NEMOTRON_MOCK_DIM", 8)
    digest = hashlib.sha256(text.encode("utf-8")).digest()
    return [round((digest[i % len(digest)] / 127.5) - 1.0, 6) for i in range(dim)]


def auth_headers() -> dict[str, str]:
    token = setting("NEMOTRON_API_KEY")
    return {"Authorization": f"Bearer {token}"} if token else {}


def post_json(url: str, payload: dict[str, Any]) -> dict[str, Any] | JSONResponse:
    if not url:
        return error(501, "runtime_not_configured", "Nemotron backend URL is not configured.")
    try:
        response = requests.post(url, json=payload, headers=auth_headers(), timeout=env_int("NEMOTRON_TIMEOUT_SEC", 120))
    except requests.Timeout:
        return error(504, "backend_timeout", "Nemotron backend timed out.")
    except requests.RequestException as exc:
        return error(503, "backend_unavailable", "Nemotron backend is unavailable.", str(exc))
    if response.status_code < 200 or response.status_code >= 300:
        return error(response.status_code if response.status_code < 500 else 502, "backend_bad_response", "Nemotron backend returned an error.", response.text)
    try:
        data = response.json()
    except ValueError as exc:
        return error(502, "backend_bad_response", "Nemotron backend did not return JSON.", str(exc))
    return data if isinstance(data, dict) else {}


def real_embed(texts: list[str]) -> dict[str, Any] | JSONResponse:
    data = post_json(setting("NEMOTRON_EMBED_URL"), {
        "model": setting("NEMOTRON_EMBED_MODEL", "nvidia/llama-nemotron-embed-300m-v2"),
        "input": texts,
    })
    if isinstance(data, JSONResponse):
        return data
    items = data.get("data") if isinstance(data.get("data"), list) else []
    embeddings = [item.get("embedding") for item in items if isinstance(item, dict) and isinstance(item.get("embedding"), list)]
    if len(embeddings) != len(texts):
        return error(502, "backend_bad_response", "Embedding response did not match input count.")
    return {"embeddings": embeddings}


def overlap_score(query: str, passage: str) -> float:
    words = set(re.findall(r"[\w]+", query.lower()))
    if not words:
        return 0.0
    found = set(re.findall(r"[\w]+", passage.lower()))
    # ponytail: naive lexical score; replace with Nemotron reranker when backend URL is configured.
    return round(len(words & found) / len(words), 6)


def real_rerank(query: str, passages: list[str], top_k: int) -> dict[str, Any] | JSONResponse:
    data = post_json(setting("NEMOTRON_RERANK_URL"), {
        "model": setting("NEMOTRON_RERANK_MODEL", "nvidia/llama-nemotron-rerank-500m-v2"),
        "query": query,
        "passages": passages,
        "top_k": top_k,
    })
    if isinstance(data, JSONResponse):
        return data
    rows = data.get("results") if isinstance(data.get("results"), list) else data.get("rankings")
    if not isinstance(rows, list):
        return error(502, "backend_bad_response", "Rerank response missing results.")
    results = []
    for row in rows[:top_k]:
        if not isinstance(row, dict):
            continue
        index = int(row.get("index", row.get("passage_index", len(results))) or 0)
        score = float(row.get("relevance_score", row.get("score", 0)) or 0)
        results.append({"index": index, "score": score, "text": passages[index] if 0 <= index < len(passages) else ""})
    return {"results": results}


def embed_response(texts: list[str], real: bool, started: float) -> JSONResponse:
    model = setting("NEMOTRON_EMBED_MODEL", "nvidia/llama-nemotron-embed-300m-v2")
    if real:
        result = real_embed(texts)
        if isinstance(result, JSONResponse):
            return result
        embeddings = result["embeddings"]
    else:
        embeddings = [mock_embedding(text) for text in texts]
    return JSONResponse(content={
        "ok": True,
        "mock": not real,
        "runtime_level": RUNTIME_LEVEL,
        "operation": "embed",
        "model": model,
        "embeddings": embeddings,
        "result_count": len(embeddings),
        "elapsed_ms": int((time.monotonic() - started) * 1000),
    })


def rerank_response(query: str, passages: list[str], top_k: int, real: bool, started: float) -> JSONResponse:
    model = setting("NEMOTRON_RERANK_MODEL", "nvidia/llama-nemotron-rerank-500m-v2")
    if real:
        result = real_rerank(query, passages, top_k)
        if isinstance(result, JSONResponse):
            return result
        results = result["results"]
    else:
        scored = [{"index": i, "score": overlap_score(query, text), "text": text} for i, text in enumerate(passages)]
        results = sorted(scored, key=lambda item: item["score"], reverse=True)[:top_k]
    return JSONResponse(content={
        "ok": True,
        "mock": not real,
        "runtime_level": RUNTIME_LEVEL,
        "operation": "rerank",
        "model": model,
        "results": results,
        "result_count": len(results),
        "elapsed_ms": int((time.monotonic() - started) * 1000),
    })


@app.post("/rag")
def rag(request: RagRequest) -> JSONResponse:
    started = time.monotonic()
    real = request.real_inference or env_bool("NEMOTRON_REAL_INFERENCE", "0")
    operation = request.operation.strip().lower()
    try:
        if operation == "embed":
            return embed_response(validate_texts(request.texts), real, started)
        if operation == "rerank":
            query = request.query.strip()
            if not query:
                return error(400, "bad_request", "query is required.")
            return rerank_response(query, validate_texts(request.passages), request.top_k, real, started)
    except OverflowError as exc:
        return error(413, "input_too_long", str(exc))
    except ValueError as exc:
        return error(400, "bad_request", str(exc))
    return error(400, "bad_request", "operation must be embed or rerank.")


@app.post("/embed")
def embed(request: RagRequest) -> JSONResponse:
    request.operation = "embed"
    return rag(request)


@app.post("/rerank")
def rerank(request: RagRequest) -> JSONResponse:
    request.operation = "rerank"
    return rag(request)
