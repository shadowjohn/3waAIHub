from __future__ import annotations

import os
import tempfile
from pathlib import Path
from typing import Any

import requests
from fastapi import FastAPI, HTTPException
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

app = FastAPI(title="3waAIHub TranslateGemma Adapter")


class TranslateRequest(BaseModel):
    text: str = Field(min_length=1)
    source_lang: str = "auto"
    target_lang: str = "zh-TW"
    real_inference: bool = False


def runtime_level() -> str:
    return "L4a-model-present-smoke"


def env_enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


def ollama_base_url() -> str:
    return os.getenv("OLLAMA_BASE_URL", "http://ollama:11434").rstrip("/")


def ollama_model_name() -> str:
    return os.getenv("OLLAMA_MODEL", "translategemma:12b-it-q4_K_M").strip()


def ensure_runtime_dirs() -> None:
    for path in [
        os.getenv("TRANSLATE_CACHE_DIR", "/cache/translate"),
        os.getenv("TRANSLATE_SERVICE_DATA_DIR", "/data/service"),
    ]:
        Path(path).mkdir(parents=True, exist_ok=True)


def storage_path_status(path: str) -> dict[str, Any]:
    target = Path(path)
    exists = target.is_dir()
    readable = exists and os.access(target, os.R_OK)
    writable = False
    error = ""

    if exists and readable:
        try:
            with tempfile.NamedTemporaryFile(prefix=".3waaihub-write-", dir=target, delete=False) as handle:
                test_path = Path(handle.name)
            test_path.unlink(missing_ok=True)
            writable = True
        except OSError as exc:
            error = str(exc)
    elif not exists:
        error = "directory missing"
    else:
        error = "directory not readable"

    status: dict[str, Any] = {
        "path": path,
        "exists": exists,
        "readable": readable,
        "writable": writable,
    }
    if error:
        status["error"] = error
    return status


def storage_status() -> tuple[dict[str, Any], list[str]]:
    ensure_runtime_dirs()
    storage = {
        "cache": storage_path_status(os.getenv("TRANSLATE_CACHE_DIR", "/cache/translate")),
        "service_data": storage_path_status(os.getenv("TRANSLATE_SERVICE_DATA_DIR", "/data/service")),
        "ollama_models": {
            "path": "/root/.ollama",
            "target_service": "ollama",
            "checked_by": "ollama",
        },
    }
    errors = []
    for name in ("cache", "service_data"):
        status = storage[name]
        for key in ("exists", "readable", "writable"):
            if not status[key]:
                errors.append(f"{name} {key} failed: {status['path']}")
    return storage, errors


def ollama_status() -> tuple[dict[str, Any], dict[str, Any], list[str]]:
    base_url = ollama_base_url()
    status: dict[str, Any] = {
        "base_url": base_url,
        "available": False,
    }
    model = {
        "name": ollama_model_name(),
        "present": False,
        "source": "ollama_tags",
    }
    try:
        response = requests.get(f"{base_url}/api/tags", timeout=2)
        status["status_code"] = response.status_code
        status["available"] = response.ok
        if response.ok:
            payload = response.json()
            models = payload.get("models", []) if isinstance(payload, dict) else []
            status["model_count"] = len(models) if isinstance(models, list) else 0
            if isinstance(models, list):
                names = [
                    str(item.get("name", ""))
                    for item in models
                    if isinstance(item, dict)
                ]
                model["present"] = model["name"] in names
    except requests.RequestException as exc:
        status["error"] = str(exc).splitlines()[0][:240]

    errors = [] if status["available"] else ["ollama unavailable"]
    return status, model, errors


@app.get("/health")
def health() -> dict[str, Any]:
    storage, storage_errors = storage_status()
    ollama, model, ollama_errors = ollama_status()
    errors = storage_errors + ollama_errors
    warnings = []
    if ollama.get("available") and not model["present"]:
        warnings.append("model_not_present")
    return {
        "ok": True,
        "service": "translate-gemma12b",
        "ready": not errors and model["present"],
        "runtime_level": runtime_level(),
        "model": model,
        "ollama": ollama,
        "storage": storage,
        "errors": errors,
        "warnings": warnings,
    }


@app.post("/translate")
def translate(request: TranslateRequest) -> Any:
    try:
        max_chars = int(os.getenv("MAX_INPUT_CHARS", "12000"))
    except ValueError as exc:
        raise HTTPException(status_code=500, detail="MAX_INPUT_CHARS must be an integer") from exc

    if len(request.text) > max_chars:
        raise HTTPException(status_code=413, detail="text is too large")

    if env_enabled(os.getenv("TRANSLATE_REAL_INFERENCE")) or request.real_inference:
        return JSONResponse(
            status_code=503,
            content={
                "ok": False,
                "error": "runtime_not_ready",
                "message": "real translation is not implemented in this runtime level",
                "runtime_level": runtime_level(),
            },
        )

    return {
        "ok": True,
        "mock": True,
        "runtime_level": runtime_level(),
        "source_lang": request.source_lang,
        "target_lang": request.target_lang,
        "text": "mock translation",
        "model": ollama_model_name(),
    }
