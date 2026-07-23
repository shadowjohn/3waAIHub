import base64
import io
import json
import os
import re
import time
import wave
from pathlib import Path
from typing import Any

import requests
from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field


RUNTIME_LEVEL = "L5-benchmark-ready"
SERVICE = "llm-gemma4-12b"
PHOTO_ROOT = Path("/data/photo").resolve()
AUDIO_MAX_BYTES = 16 * 1024 * 1024
AUDIO_MAX_DURATION_SEC = 30.0


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


def vllm_backend_status() -> dict[str, Any]:
    try:
        response = requests.get(f"{vllm_base_url()}/v1/models", timeout=2.0)
        if response.status_code >= 200 and response.status_code < 300:
            return {"ready": True, "status_code": response.status_code}
        return {
            "ready": False,
            "status_code": response.status_code,
            "error": "vllm_bad_response",
        }
    except requests.RequestException as exc:
        return {
            "ready": False,
            "error": "vllm_unavailable",
            "detail": str(exc)[:300],
        }


class ChatRequest(BaseModel):
    text: str = Field(default="")
    system_prompt: str = Field(default="你是 3waAIHub 本地 AI 助手，請使用正體中文回答。")
    temperature: float = Field(default=0.2, ge=0.0, le=2.0)
    max_tokens: int = Field(default=1024, ge=1, le=8192)
    enable_thinking: bool = False
    real_inference: bool = False


class PhotoRequest(BaseModel):
    image_id: str
    image_internal_path: str
    text: str = Field(default="")
    max_tokens: int = Field(default=256, ge=32, le=2048)
    real_inference: bool = False


def parse_form_bool(value: str | bool | int | None) -> bool:
    if isinstance(value, bool):
        return value
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}


def safe_photo_path(path: str) -> Path | None:
    try:
        resolved = Path(path).resolve()
    except OSError:
        return None
    if not str(resolved).startswith(str(PHOTO_ROOT) + os.sep):
        return None
    return resolved if resolved.is_file() else None


def image_data_url(path: Path) -> str:
    data = path.read_bytes()
    mime = "image/png"
    if data.startswith(b"\xff\xd8"):
        mime = "image/jpeg"
    elif data.startswith(b"RIFF") and b"WEBP" in data[:16]:
        mime = "image/webp"
    return f"data:{mime};base64," + base64.b64encode(data).decode("ascii")


def parse_model_json(text: str) -> dict[str, Any] | None:
    stripped = text.strip()
    match = re.search(r"```(?:json)?\s*(\{.*?\})\s*```", stripped, re.S)
    if match:
        stripped = match.group(1)
    try:
        value = json.loads(stripped)
    except json.JSONDecodeError:
        return None
    return value if isinstance(value, dict) else None


def validate_wav_bytes(data: bytes) -> dict[str, Any]:
    if not data:
        raise ValueError("file_required")
    if len(data) > AUDIO_MAX_BYTES:
        raise ValueError("payload_too_large")
    try:
        with wave.open(io.BytesIO(data), "rb") as wav:
            channels = wav.getnchannels()
            rate = wav.getframerate()
            frames = wav.getnframes()
            sample_width = wav.getsampwidth()
    except wave.Error as exc:
        raise ValueError("invalid_audio") from exc
    duration = frames / rate if rate else 0
    if channels != 1 or rate != 16000:
        raise ValueError("unsupported_audio_format")
    if duration <= 0 or duration > AUDIO_MAX_DURATION_SEC:
        raise ValueError("audio_too_long")

    return {
        "mime": "audio/wav",
        "duration_ms": int(round(duration * 1000)),
        "sample_rate": rate,
        "channels": channels,
        "sample_width": sample_width,
        "size": len(data),
    }


def audio_prompt(operation: str, text: str) -> str:
    if operation == "transcribe":
        return (
            "Chinese voice. Transcribe exactly what the speaker said into Traditional Chinese. "
            "Return transcript only. Do not repeat this instruction. Do not guess. "
            "If most of the audio is unclear, return ［聽不清楚］."
        )
    if operation == "summarize":
        return (
            "Chinese voice. Listen to the audio and return only valid JSON in Traditional Chinese: "
            "{\"summary\":\"音訊摘要\",\"answer\":null,\"transcript\":null,\"tags\":[\"最多八個短標籤\"]}. "
            "Do not guess unclear speech. If unclear, set summary to 聽不清楚."
        )
    question = text.strip() or "這段音訊的重點是什麼？"
    return (
        "Chinese voice. Answer the user's question based only on the audio. "
        "Return only valid JSON in Traditional Chinese: "
        "{\"answer\":\"針對問題的完整回答\",\"summary\":\"一句摘要\",\"transcript\":null,\"tags\":[\"最多八個短標籤\"]}. "
        "Do not guess unclear speech. If unclear, set answer to 聽不清楚. "
        f"User question: {question}"
    )


def audio_runtime_warnings(operation: str) -> list[str]:
    warnings = ["gemma4_audio_experimental"]
    if operation == "transcribe":
        warnings.append("gemma4_audio_not_reliable_asr")
    return warnings


app = FastAPI(title="3waAIHub Gemma 4 Chat Adapter")


@app.get("/health")
def health() -> dict[str, Any]:
    backend = vllm_backend_status()
    return {
        "ok": True,
        "service": SERVICE,
        "ready": bool(backend.get("ready")),
        "runtime_level": RUNTIME_LEVEL,
        "model": {
            "name": served_model(),
            "provider": "gemma4",
            "backend": "vllm",
        },
        "backend": backend,
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


@app.post("/photo")
def photo(request: PhotoRequest) -> JSONResponse:
    started = time.monotonic()
    question = request.text.strip()
    if not question:
        return error_response(400, "text_required", "text is required.")
    if not re.match(r"^img_[A-Za-z0-9_-]{20,64}$", request.image_id):
        return error_response(400, "image_id_required", "valid image_id is required.")

    path = safe_photo_path(request.image_internal_path)
    if path is None:
        return error_response(403, "photo_forbidden", "image path is not allowed.")

    if not request.real_inference:
        elapsed = int((time.monotonic() - started) * 1000)
        return JSONResponse(content={
            "ok": True,
            "mock": True,
            "runtime_level": RUNTIME_LEVEL,
            "model": served_model(),
            "image_id": request.image_id,
            "answer": "這是一個 Gemma 4 Photo Vision mock answer。",
            "caption": "一張上傳到 3waAIHub 的圖片。",
            "tags": [],
            "usage": {"prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0},
            "elapsed_ms": elapsed,
        })

    prompt = (
        "請使用正體中文回答。請根據圖片與使用者問題輸出 JSON："
        "{\"answer\":\"針對問題的完整回答\",\"caption\":\"一句客觀圖片描述\",\"tags\":[\"最多八個短標籤\"]}"
        "不得猜測圖片中不可見的資訊。無法確認時必須明確說明不確定。"
        f"\n使用者問題：{question}"
    )
    try:
        image_url = image_data_url(path)
    except OSError as exc:
        return error_response(403, "photo_forbidden", "image path is not readable.", str(exc))

    payload = {
        "model": served_model(),
        "messages": [{
            "role": "user",
            "content": [
                {"type": "text", "text": prompt},
                {"type": "image_url", "image_url": {"url": image_url}},
            ],
        }],
        "temperature": 0.1,
        "max_tokens": request.max_tokens,
        "stream": False,
    }
    try:
        response = requests.post(
            f"{vllm_base_url()}/v1/chat/completions",
            json=payload,
            timeout=env_float("VLLM_TIMEOUT_SEC", 600.0),
        )
    except requests.Timeout:
        return error_response(504, "vision_timeout", "Gemma 4 vision request timed out.")
    except requests.RequestException as exc:
        return error_response(503, "model_not_ready", "Gemma 4 vision backend is unavailable.", str(exc))
    if response.status_code < 200 or response.status_code >= 300:
        return error_response(502, "vision_bad_response", "Gemma 4 vision returned an error.", response.text)

    try:
        data = response.json()
    except ValueError as exc:
        return error_response(502, "vision_bad_response", "Gemma 4 vision response was not valid JSON.", str(exc))
    choices = data.get("choices") if isinstance(data, dict) else None
    message = choices[0].get("message", {}) if isinstance(choices, list) and choices else {}
    parsed = parse_model_json(str(message.get("content") or ""))
    if parsed is None:
        return error_response(502, "vision_failed", "Gemma 4 vision response was not valid JSON.")
    answer = str(parsed.get("answer") or "").strip()
    caption = str(parsed.get("caption") or "").strip()
    tags = parsed.get("tags") if isinstance(parsed.get("tags"), list) else []
    tags = [str(tag).strip() for tag in tags if str(tag).strip()][:8]
    if not answer:
        return error_response(502, "vision_failed", "Gemma 4 vision answer was empty.")
    usage = data.get("usage") if isinstance(data.get("usage"), dict) else {}
    elapsed = int((time.monotonic() - started) * 1000)
    return JSONResponse(content={
        "ok": True,
        "mock": False,
        "runtime_level": RUNTIME_LEVEL,
        "model": served_model(),
        "image_id": request.image_id,
        "answer": answer,
        "caption": caption,
        "tags": tags,
        "usage": {
            "prompt_tokens": int(usage.get("prompt_tokens", 0) or 0),
            "completion_tokens": int(usage.get("completion_tokens", 0) or 0),
            "total_tokens": int(usage.get("total_tokens", 0) or 0),
        },
        "elapsed_ms": elapsed,
    })


@app.post("/audio")
async def audio(
    audio: UploadFile = File(...),
    operation: str = Form("understand"),
    text: str = Form(""),
    max_tokens: int = Form(512),
    real_inference: str = Form("0"),
) -> JSONResponse:
    started = time.monotonic()
    operation = operation.strip().lower() or "understand"
    if operation not in {"understand", "transcribe", "summarize"}:
        return error_response(400, "bad_request", "operation must be understand, transcribe or summarize.")

    data = await audio.read()
    try:
        audio_meta = validate_wav_bytes(data)
    except ValueError as exc:
        code = str(exc)
        return error_response({
            "file_required": 400,
            "payload_too_large": 413,
            "invalid_audio": 400,
            "unsupported_audio_format": 415,
            "audio_too_long": 413,
        }.get(code, 400), code, code)

    max_tokens = max(32, min(int(max_tokens or 512), 2048))
    real = parse_form_bool(real_inference)
    if not real:
        elapsed = int((time.monotonic() - started) * 1000)
        return JSONResponse(content={
            "ok": True,
            "mock": True,
            "runtime_level": RUNTIME_LEVEL,
            "model": served_model(),
            "operation": operation,
            "answer": "這是一個 Gemma 4 Audio mock answer。" if operation == "understand" else None,
            "transcript": "mock transcription" if operation == "transcribe" else None,
            "summary": "Gemma 4 Audio mock summary" if operation == "summarize" else None,
            "tags": [],
            "warnings": audio_runtime_warnings(operation),
            "audio": audio_meta,
            "usage": {"prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0},
            "elapsed_ms": elapsed,
        })

    payload = {
        "model": served_model(),
        "messages": [
            {
                "role": "system",
                "content": "You are a precise audio analysis assistant. Follow the user instruction and analyze the audio.",
            },
            {
                "role": "user",
                "content": [
                    {"type": "text", "text": audio_prompt(operation, text)},
                    {
                        "type": "input_audio",
                        "input_audio": {
                            "data": base64.b64encode(data).decode("ascii"),
                            "format": "wav",
                        },
                    },
                ],
            },
        ],
        "temperature": env_float("GEMMA4_AUDIO_TEMPERATURE", 0.0),
        "max_tokens": max_tokens,
        "stream": False,
    }
    try:
        response = requests.post(
            f"{vllm_base_url()}/v1/chat/completions",
            json=payload,
            timeout=env_float("VLLM_TIMEOUT_SEC", 600.0),
        )
    except requests.Timeout:
        return error_response(504, "audio_timeout", "Gemma 4 audio request timed out.")
    except requests.RequestException as exc:
        return error_response(503, "model_not_ready", "Gemma 4 audio backend is unavailable.", str(exc))
    if response.status_code < 200 or response.status_code >= 300:
        return error_response(502, "audio_bad_response", "Gemma 4 audio returned an error.", response.text)

    try:
        body = response.json()
    except ValueError as exc:
        return error_response(502, "audio_bad_response", "Gemma 4 audio response was not valid JSON.", str(exc))
    choices = body.get("choices") if isinstance(body, dict) else None
    message = choices[0].get("message", {}) if isinstance(choices, list) and choices else {}
    output_text = str(message.get("content") or "").strip()
    if not output_text:
        return error_response(502, "audio_failed", "Gemma 4 audio output was empty.")

    answer: str | None = None
    transcript: str | None = None
    summary: str | None = None
    tags: list[str] = []
    warnings = audio_runtime_warnings(operation)
    if operation == "transcribe":
        transcript = output_text
    else:
        parsed = parse_model_json(output_text)
        if parsed is None:
            warnings.append("audio_output_not_json")
            if operation == "summarize":
                summary = output_text
            else:
                answer = output_text
        else:
            answer = str(parsed.get("answer") or "").strip() or None
            transcript_value = parsed.get("transcript")
            summary_value = parsed.get("summary")
            transcript = str(transcript_value).strip() if transcript_value is not None and str(transcript_value).strip() != "" else None
            summary = str(summary_value).strip() if summary_value is not None and str(summary_value).strip() != "" else None
            raw_tags = parsed.get("tags") if isinstance(parsed.get("tags"), list) else []
            tags = [str(tag).strip() for tag in raw_tags if str(tag).strip()][:8]

    usage = body.get("usage") if isinstance(body.get("usage"), dict) else {}
    elapsed = int((time.monotonic() - started) * 1000)
    return JSONResponse(content={
        "ok": True,
        "mock": False,
        "runtime_level": RUNTIME_LEVEL,
        "model": served_model(),
        "operation": operation,
        "answer": answer,
        "transcript": transcript,
        "summary": summary,
        "tags": tags,
        "warnings": warnings,
        "audio": audio_meta,
        "usage": {
            "prompt_tokens": int(usage.get("prompt_tokens", 0) or 0),
            "completion_tokens": int(usage.get("completion_tokens", 0) or 0),
            "total_tokens": int(usage.get("total_tokens", 0) or 0),
        },
        "elapsed_ms": elapsed,
    })
