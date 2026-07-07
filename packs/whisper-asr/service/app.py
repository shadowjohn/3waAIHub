from __future__ import annotations

import os
import tempfile
from pathlib import Path
from typing import Any

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse

app = FastAPI(title="3waAIHub Whisper ASR")


def runtime_level() -> str:
    return "L3-storage-mount"


def env_enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


def configure_whisper_env() -> None:
    model_dir = os.getenv("WHISPER_MODEL_DIR", "/models/whisper")
    cache_dir = os.getenv("WHISPER_CACHE_DIR", "/cache/whisper")
    env = {
        "HF_HOME": os.getenv("HF_HOME", f"{model_dir}/huggingface"),
        "XDG_CACHE_HOME": os.getenv("XDG_CACHE_HOME", f"{cache_dir}/xdg"),
        "HOME": os.getenv("HOME", f"{cache_dir}/home"),
        "PYTHONUNBUFFERED": os.getenv("PYTHONUNBUFFERED", "1"),
    }
    os.environ.update(env)
    for path in [
        model_dir,
        cache_dir,
        os.getenv("WHISPER_SERVICE_DATA_DIR", "/data/service"),
        env["HF_HOME"],
        env["XDG_CACHE_HOME"],
        env["HOME"],
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
    configure_whisper_env()
    storage = {
        "models": storage_path_status(os.getenv("WHISPER_MODEL_DIR", "/models/whisper")),
        "cache": storage_path_status(os.getenv("WHISPER_CACHE_DIR", "/cache/whisper")),
        "service_data": storage_path_status(os.getenv("WHISPER_SERVICE_DATA_DIR", "/data/service")),
    }
    errors = [
        f"{name} {key} failed: {status['path']}"
        for name, status in storage.items()
        for key in ("exists", "readable", "writable")
        if not status[key]
    ]
    return storage, errors


@app.get("/health")
def health() -> dict[str, Any]:
    storage, errors = storage_status()
    return {
        "ok": True,
        "service": "whisper-asr",
        "ready": not errors,
        "runtime_level": runtime_level(),
        "real_inference": env_enabled(os.getenv("WHISPER_REAL_INFERENCE")),
        "model": os.getenv("WHISPER_MODEL", "small"),
        "storage": storage,
        "errors": errors,
    }


@app.post("/asr/audio")
async def asr_audio(
    audio: UploadFile = File(...),
    language: str = Form("auto"),
    real_inference: str = Form("0"),
) -> JSONResponse:
    data = await audio.read()
    max_bytes = int(os.getenv("WHISPER_MAX_UPLOAD_MB", "100")) * 1024 * 1024
    if not data:
        return JSONResponse(status_code=400, content={"ok": False, "error": "bad_request", "message": "audio is required"})
    if len(data) > max_bytes:
        return JSONResponse(status_code=413, content={"ok": False, "error": "file_too_large", "message": "audio is too large"})
    if env_enabled(real_inference) or env_enabled(os.getenv("WHISPER_REAL_INFERENCE")):
        return JSONResponse(status_code=501, content={
            "ok": False,
            "error": "runtime_not_ready",
            "message": "real ASR inference is not implemented in this runtime level",
        })

    return JSONResponse(content={
        "ok": True,
        "mock": True,
        "runtime_level": runtime_level(),
        "language": language or "auto",
        "text": "mock transcription",
        "segments": [],
        "device": {
            "requested": os.getenv("WHISPER_DEVICE", "auto"),
            "effective": "mock",
        },
        "filename": audio.filename,
        "bytes": len(data),
    })
