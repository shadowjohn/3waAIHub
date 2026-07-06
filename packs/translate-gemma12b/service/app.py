from __future__ import annotations

import json
import os
import urllib.error
import urllib.request
from typing import Any

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

app = FastAPI(title="3waAIHub TranslateGemma Adapter")


class TranslateRequest(BaseModel):
    text: str = Field(min_length=1)
    source_lang: str = "auto"
    target_lang: str = "zh-TW"


def ollama_base_url() -> str:
    host = os.getenv("OLLAMA_HOST", "127.0.0.1:11434")
    if host.startswith("http://") or host.startswith("https://"):
        return host.rstrip("/")
    return "http://" + host.rstrip("/")


def ollama_json(path: str, payload: dict[str, Any] | None = None, timeout: int = 180) -> dict[str, Any]:
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    request = urllib.request.Request(
        ollama_base_url() + path,
        data=data,
        headers={"Content-Type": "application/json"},
        method="GET" if payload is None else "POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return json.loads(response.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        raise HTTPException(status_code=502, detail=exc.read().decode("utf-8", errors="replace")) from exc
    except OSError as exc:
        raise HTTPException(status_code=503, detail="ollama is not available") from exc


def model_available(model: str) -> bool:
    tags = ollama_json("/api/tags", timeout=5).get("models", [])
    return any(item.get("name") == model for item in tags if isinstance(item, dict))


def language_label(code: str) -> str:
    labels = {
        "zh-TW": "Traditional Chinese (Taiwan), using Traditional Chinese characters and Taiwan wording",
        "zh-Hant": "Traditional Chinese, using Traditional Chinese characters",
        "zh-CN": "Simplified Chinese",
        "en": "English",
        "ja": "Japanese",
        "ko": "Korean",
    }
    return labels.get(code, code)


@app.get("/health")
def health() -> dict[str, Any]:
    model = os.getenv("OLLAMA_MODEL", "translategemma:12b-it-q4_K_M")
    try:
        available = model_available(model)
    except HTTPException as exc:
        return {"ok": False, "service": "translate-gemma12b", "ready": False, "model": model, "error": exc.detail}

    return {"ok": True, "service": "translate-gemma12b", "ready": available, "model": model}


@app.post("/translate")
def translate(request: TranslateRequest) -> dict[str, Any]:
    max_chars = int(os.getenv("TRANSLATE_MAX_CHARS", "8000"))
    if len(request.text) > max_chars:
        raise HTTPException(status_code=413, detail="text is too large")

    model = os.getenv("OLLAMA_MODEL", "translategemma:12b-it-q4_K_M")
    if not model_available(model):
        raise HTTPException(status_code=503, detail=f"model not installed: {model}")

    prompt = (
        "Translate the following text from "
        f"{language_label(request.source_lang)} to {language_label(request.target_lang)}. "
        "Return only the translated text.\n\n"
        f"{request.text}"
    )
    result = ollama_json(
        "/api/generate",
        {
            "model": model,
            "prompt": prompt,
            "stream": False,
            "keep_alive": os.getenv("OLLAMA_KEEP_ALIVE", "10m"),
        },
    )
    return {
        "ok": True,
        "text": result.get("response", "").strip(),
        "model": model,
        "source_lang": request.source_lang,
        "target_lang": request.target_lang,
    }
