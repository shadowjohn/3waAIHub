from __future__ import annotations

import os
import time
import tempfile
from pathlib import Path
from typing import Any

import requests
from fastapi import FastAPI
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

app = FastAPI(title="3waAIHub TranslateGemma Adapter")


class TranslateRequest(BaseModel):
    text: str = Field(min_length=1)
    source_lang: str = "auto"
    target_lang: str = "zh-TW"
    real_inference: bool = False


def runtime_level() -> str:
    return "L4b-real-translation"


def env_enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


def ollama_base_url() -> str:
    return os.getenv("OLLAMA_BASE_URL", "http://ollama:11434").rstrip("/")


def ollama_model_name() -> str:
    return os.getenv("OLLAMA_MODEL", "translategemma:12b-it-q4_K_M").strip()


def json_error(status_code: int, code: str, message: str) -> JSONResponse:
    return JSONResponse(
        status_code=status_code,
        content={
            "ok": False,
            "error": code,
            "message": message,
            "runtime_level": runtime_level(),
        },
    )


def env_int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except ValueError:
        return default


def env_float(name: str, default: float) -> float:
    try:
        return float(os.getenv(name, str(default)))
    except ValueError:
        return default


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


def build_prompt(request: TranslateRequest) -> str:
    target_note = (
        "\nWhen target_lang is zh-TW, use Traditional Chinese used in Taiwan."
        if request.target_lang == "zh-TW"
        else ""
    )
    return (
        "You are a professional translation engine.\n\n"
        f"Translate the following text from {request.source_lang} to {request.target_lang}.\n"
        "Preserve meaning, tone, paragraph breaks, numbers, technical terms, and names.\n"
        "Do not add explanations.\n"
        "Return only the translated text."
        f"{target_note}\n\n"
        "Text:\n"
        f"{request.text}"
    )


def translate_with_ollama(request: TranslateRequest) -> Any:
    ollama, model, errors = ollama_status()
    if errors:
        return json_error(503, "ollama_unavailable", "Ollama is not available.")
    if not model["present"]:
        return json_error(503, "model_not_present", "Ollama model is not present.")

    started = time.monotonic()
    payload = {
        "model": model["name"],
        "prompt": build_prompt(request),
        "stream": False,
        "options": {
            "temperature": env_float("TEMPERATURE", 0.0),
            "num_ctx": env_int("OLLAMA_NUM_CTX", 4096),
        },
        "keep_alive": os.getenv("OLLAMA_KEEP_ALIVE", "5m"),
    }
    try:
        response = requests.post(
            f"{ollama_base_url()}/api/generate",
            json=payload,
            timeout=env_int("OLLAMA_TIMEOUT_SEC", 180),
        )
    except requests.Timeout:
        return json_error(504, "ollama_timeout", "Ollama generate request timed out.")
    except requests.RequestException as exc:
        return json_error(502, "translation_failed", str(exc).splitlines()[0][:240])

    if not response.ok:
        return json_error(502, "translation_failed", f"Ollama returned HTTP {response.status_code}.")
    try:
        payload = response.json()
    except ValueError:
        return json_error(502, "ollama_bad_response", "Ollama response was not valid JSON.")
    if not isinstance(payload, dict):
        return json_error(502, "ollama_bad_response", "Ollama response payload was invalid.")

    text = str(payload.get("response", "")).strip()
    if text == "":
        return json_error(502, "ollama_bad_response", "Ollama returned an empty translation.")

    return {
        "ok": True,
        "mock": False,
        "runtime_level": runtime_level(),
        "model": model["name"],
        "source_lang": request.source_lang,
        "target_lang": request.target_lang,
        "text": text,
        "elapsed_ms": int((time.monotonic() - started) * 1000),
    }


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
        return json_error(413, "input_too_long", "Text is too large.")

    if env_enabled(os.getenv("TRANSLATE_REAL_INFERENCE")) or request.real_inference:
        return translate_with_ollama(request)

    return {
        "ok": True,
        "mock": True,
        "runtime_level": runtime_level(),
        "source_lang": request.source_lang,
        "target_lang": request.target_lang,
        "text": "mock translation",
        "model": ollama_model_name(),
    }
