from __future__ import annotations

import os
import tempfile
from pathlib import Path
from typing import Any, Callable

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse

app = FastAPI(title="3waAIHub Whisper ASR")

MODEL_CACHE: dict[tuple[str, str, str], Any] = {}
ModelFactory = Callable[[str, str, str, str], Any]


def runtime_level() -> str:
    return "L5-benchmark-ready"


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

    status: dict[str, Any] = {"path": path, "exists": exists, "readable": readable, "writable": writable}
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


def normalize_device(value: str | None = None) -> str:
    device = str(value if value is not None else os.getenv("WHISPER_DEVICE", "auto")).lower()
    return device if device in {"auto", "cuda", "cpu"} else "auto"


def normalize_compute_type(value: str | None = None) -> str:
    compute_type = str(value if value is not None else os.getenv("WHISPER_COMPUTE_TYPE", "auto")).lower()
    return compute_type if compute_type in {"auto", "int8", "float16", "float32"} else "auto"


def inference_candidates(device: str, compute_type: str) -> list[tuple[str, str]]:
    if device == "cuda":
        return [("cuda", "float16" if compute_type == "auto" else compute_type)]
    if device == "cpu":
        return [("cpu", "int8")]
    return [("cuda", "float16"), ("cpu", "int8")]


def default_model_factory(model_name: str, device: str, compute_type: str, download_root: str) -> Any:
    from faster_whisper import WhisperModel

    return WhisperModel(model_name, device=device, compute_type=compute_type, download_root=download_root)


def load_model(model_name: str, device: str, compute_type: str, model_factory: ModelFactory | None = None) -> Any:
    key = (model_name, device, compute_type)
    if key not in MODEL_CACHE:
        factory = model_factory or default_model_factory
        MODEL_CACHE[key] = factory(model_name, device, compute_type, os.getenv("WHISPER_MODEL_DIR", "/models/whisper"))
    return MODEL_CACHE[key]


def run_real_inference(
    audio_path: str,
    language: str,
    *,
    model_factory: ModelFactory | None = None,
    model_name: str | None = None,
    requested_device: str | None = None,
    requested_compute_type: str | None = None,
) -> dict[str, Any]:
    name = model_name or os.getenv("WHISPER_MODEL", "small")
    device = normalize_device(requested_device)
    compute_type = normalize_compute_type(requested_compute_type)
    attempts = []
    for index, (effective_device, effective_compute_type) in enumerate(inference_candidates(device, compute_type)):
        try:
            model = load_model(name, effective_device, effective_compute_type, model_factory)
            options = {} if language in {"", "auto"} else {"language": language}
            raw_segments, info = model.transcribe(audio_path, **options)
            segments = [
                {"start": float(segment.start), "end": float(segment.end), "text": str(segment.text).strip()}
                for segment in raw_segments
            ]
            return {
                "ok": True,
                "mock": False,
                "runtime_level": runtime_level(),
                "language": str(getattr(info, "language", "") or language or "auto"),
                "text": " ".join(segment["text"] for segment in segments).strip(),
                "segments": segments,
                "device": {
                    "requested": device,
                    "effective": effective_device,
                    "compute_type": effective_compute_type,
                    "fallback_used": index > 0,
                },
            }
        except Exception as exc:
            attempts.append({"device": effective_device, "compute_type": effective_compute_type, "error": type(exc).__name__})
    return {
        "ok": False,
        "mock": False,
        "error": "real_inference_failed",
        "message": "Whisper could not run on the available inference devices.",
        "attempts": attempts,
        "status_code": 503,
    }


@app.get("/health")
def health() -> dict[str, Any]:
    storage, errors = storage_status()
    return {
        "ok": True,
        "service": "whisper-asr",
        "ready": not errors,
        "runtime_level": runtime_level(),
        "real_inference": env_enabled(os.getenv("WHISPER_REAL_INFERENCE", "1")),
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
    if env_enabled(real_inference) or env_enabled(os.getenv("WHISPER_REAL_INFERENCE", "1")):
        suffix = Path(audio.filename or "audio").suffix or ".audio"
        path = ""
        try:
            with tempfile.NamedTemporaryFile(prefix="whisper-", suffix=suffix, delete=False) as handle:
                handle.write(data)
                path = handle.name
            response = run_real_inference(path, language)
        finally:
            if path:
                Path(path).unlink(missing_ok=True)
        status_code = int(response.pop("status_code", 200))
        response.update({"filename": audio.filename, "bytes": len(data)})
        return JSONResponse(status_code=status_code, content=response)

    return JSONResponse(content={
        "ok": True,
        "mock": True,
        "runtime_level": runtime_level(),
        "language": language or "auto",
        "text": "mock transcription",
        "segments": [],
        "device": {"requested": normalize_device(), "effective": "mock", "compute_type": "mock", "fallback_used": False},
        "filename": audio.filename,
        "bytes": len(data),
    })
