import os
import time
from typing import Any

import requests
from fastapi import FastAPI
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field


RUNTIME_LEVEL = "L5-benchmark-ready"
SERVICE = "llm-gemma4-12b"


def env_bool(name: str, default: str = "0") -> bool:
    return os.getenv(name, default).strip().lower() in {"1", "true", "yes", "on"}


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


def served_model() -> str:
    return os.getenv("VLLM_SERVED_MODEL_NAME", "gemma4-12b")


def vllm_base_url() -> str:
    return os.getenv("VLLM_BASE_URL", "http://vllm:8000").rstrip("/")


def error_response(status: int, code: str, message: str, detail: str = "") -> JSONResponse:
    payload: dict[str, Any] = {"ok": False, "error": code, "message": message}
    if detail:
        payload["detail"] = detail[:500]
    return JSONResponse(status_code=status, content=payload)


class ChatRequest(BaseModel):
    text: str = Field(default="")
    system_prompt: str = Field(default="你是 3waAIHub 本地 AI 助手，請使用正體中文回答。")
    temperature: float = Field(default=0.2, ge=0.0, le=2.0)
    max_tokens: int = Field(default=1024, ge=1, le=8192)
    enable_thinking: bool = False
    real_inference: bool = False


app = FastAPI(title="3waAIHub Gemma 4 Chat Adapter")


@app.get("/health")
def health() -> dict[str, Any]:
    return {
        "ok": True,
        "service": SERVICE,
        "ready": True,
        "runtime_level": RUNTIME_LEVEL,
        "model": {
            "name": served_model(),
            "provider": "gemma4",
            "backend": "vllm",
        },
        "adapter": {
            "endpoint": "/chat",
            "streaming_supported": False,
            "openai_compatible_gateway": False,
        },
    }


@app.post("/chat")
def chat(request: ChatRequest) -> JSONResponse:
    started = time.monotonic()
    text = request.text.strip()
    if not text:
        return error_response(400, "bad_request", "text is required.")

    max_chars = env_int("MAX_INPUT_CHARS", 12000)
    if len(text) > max_chars:
        return error_response(413, "input_too_long", f"text exceeds {max_chars} characters.")

    real = request.real_inference or env_bool("GEMMA4_REAL_INFERENCE", "0")
    if not real:
        elapsed = int((time.monotonic() - started) * 1000)
        return JSONResponse(content={
            "ok": True,
            "mock": True,
            "runtime_level": RUNTIME_LEVEL,
            "model": served_model(),
            "text": "3waAIHub Gemma 4 mock response",
            "usage": {"prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0},
            "elapsed_ms": elapsed,
        })

    payload = {
        "model": served_model(),
        "messages": [
            {"role": "system", "content": request.system_prompt.strip() or "你是 3waAIHub 本地 AI 助手，請使用正體中文回答。"},
            {"role": "user", "content": text},
        ],
        "temperature": request.temperature,
        "max_tokens": request.max_tokens,
        "stream": False,
        "chat_template_kwargs": {
            "enable_thinking": bool(request.enable_thinking or env_bool("GEMMA_DEFAULT_THINKING", "0")),
        },
    }

    try:
        response = requests.post(
            f"{vllm_base_url()}/v1/chat/completions",
            json=payload,
            timeout=env_float("VLLM_TIMEOUT_SEC", 600.0),
        )
    except requests.Timeout:
        return error_response(504, "vllm_timeout", "vLLM request timed out.")
    except requests.RequestException as exc:
        return error_response(503, "vllm_unavailable", "vLLM is unavailable.", str(exc))

    if response.status_code == 404:
        return error_response(503, "model_not_present", "Gemma 4 model is not present or vLLM model name is unavailable.")
    if response.status_code < 200 or response.status_code >= 300:
        return error_response(response.status_code if response.status_code < 500 else 502, "vllm_bad_response", "vLLM returned an error.", response.text)

    try:
        data = response.json()
        choices = data.get("choices") if isinstance(data, dict) else None
        message = choices[0].get("message", {}) if isinstance(choices, list) and choices else {}
        output_text = str(message.get("content") or "").strip()
        usage = data.get("usage") if isinstance(data.get("usage"), dict) else {}
    except Exception as exc:
        return error_response(502, "vllm_bad_response", "vLLM response is not valid JSON.", str(exc))

    if not output_text:
        return error_response(502, "chat_failed", "vLLM response did not include text.")

    elapsed = int((time.monotonic() - started) * 1000)
    return JSONResponse(content={
        "ok": True,
        "mock": False,
        "runtime_level": RUNTIME_LEVEL,
        "model": served_model(),
        "text": output_text,
        "usage": {
            "prompt_tokens": int(usage.get("prompt_tokens", 0) or 0),
            "completion_tokens": int(usage.get("completion_tokens", 0) or 0),
            "total_tokens": int(usage.get("total_tokens", 0) or 0),
        },
        "elapsed_ms": elapsed,
    })
