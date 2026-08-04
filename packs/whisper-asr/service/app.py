from __future__ import annotations

import json
import os
import re
import secrets
import tempfile
import threading
from contextlib import contextmanager
from pathlib import Path
from typing import Any, Callable

from fastapi import FastAPI, File, Form, Header, UploadFile
from fastapi.responses import JSONResponse

import job

app = FastAPI(title="3waAIHub Whisper ASR")

MODEL_CACHE: dict[tuple[str, str, str], Any] = {}
ModelFactory = Callable[[str, str, str, str], Any]
_MODEL_WORK_LOCK = threading.RLock()
_MODEL_WORK_DEPTH = 0
_ACTIVE_JOBS: dict[str, threading.Event] = {}
_ACTIVE_JOBS_LOCK = threading.RLock()
RUN_ID = re.compile(r"^[a-z0-9][a-z0-9_.-]{0,95}$")
TERMINAL_JOB_STATES = {"succeeded", "failed", "cancelled"}


def runtime_level() -> str:
    return "L5-benchmark-ready"


def env_enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


@contextmanager
def model_work() -> Any:
    global _MODEL_WORK_DEPTH
    with _MODEL_WORK_LOCK:
        _MODEL_WORK_DEPTH += 1
        try:
            yield
        finally:
            _MODEL_WORK_DEPTH -= 1


def internal_authorized(token: str | None) -> bool:
    expected = os.getenv("WHISPER_INTERNAL_JOB_TOKEN", "")
    return bool(expected and token and secrets.compare_digest(expected, token))


def resident_jobs_root() -> Path:
    root = Path(os.getenv("WHISPER_SERVICE_DATA_DIR", "/data/service")) / "resident_jobs"
    if root.exists() and (root.is_symlink() or not root.is_dir()):
        raise RuntimeError("resident_job_invalid")
    root.mkdir(parents=True, exist_ok=True)
    if root.is_symlink():
        raise RuntimeError("resident_job_invalid")
    return root.resolve(strict=True)


def resident_stage(run_id: str) -> Path:
    if not isinstance(run_id, str) or not RUN_ID.fullmatch(run_id):
        raise RuntimeError("resident_job_invalid")
    root = resident_jobs_root()
    stage = root / run_id
    if not stage.is_dir() or stage.is_symlink():
        raise RuntimeError("resident_job_invalid")
    resolved = stage.resolve(strict=True)
    if root not in resolved.parents:
        raise RuntimeError("resident_job_invalid")
    return resolved


def resident_regular(stage: Path, *parts: str) -> Path:
    target = stage.joinpath(*parts)
    if not target.is_file() or target.is_symlink():
        raise RuntimeError("resident_job_invalid")
    resolved = target.resolve(strict=True)
    if stage not in resolved.parents:
        raise RuntimeError("resident_job_invalid")
    return resolved


def resident_terminal_state(stage: Path) -> str:
    try:
        payload = json.loads(resident_regular(stage, "terminal.json").read_text(encoding="utf-8"))
    except (OSError, ValueError, RuntimeError):
        return "unknown"
    return str(payload.get("state")) if isinstance(payload, dict) and set(payload) == {"state"} and payload.get("state") in TERMINAL_JOB_STATES else "unknown"


def write_resident_terminal_state(stage: Path, state: str) -> None:
    if state not in TERMINAL_JOB_STATES:
        raise RuntimeError("resident_job_invalid")
    target = stage / "terminal.json"
    temporary = stage / ".terminal.json.tmp"
    for path in (target, temporary):
        if path.exists() and (path.is_symlink() or not path.is_file()):
            raise RuntimeError("resident_job_invalid")
    with temporary.open("w", encoding="utf-8") as handle:
        json.dump({"state": state}, handle, separators=(",", ":"))
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    temporary.replace(target)


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
    return compute_type if compute_type in {"auto", "int8", "int8_float32", "float16", "float32"} else "auto"


def resident_cpu_policy_enabled() -> bool:
    return os.getenv("WHISPER_GPU_SHORTAGE_POLICY", "wait").lower() == "cpu"


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


def resident_asr_loader(model_name: str) -> Any:
    device = "cpu" if resident_cpu_policy_enabled() else normalize_device()
    effective_device = "cuda" if device == "auto" else device
    compute_type = normalize_compute_type()
    effective_compute_type = ("float16" if effective_device == "cuda" else "int8") if compute_type == "auto" else compute_type
    return load_model(model_name, effective_device, effective_compute_type)


def resident_cuda_guard() -> None:
    if not resident_cpu_policy_enabled() and normalize_device() != "cpu":
        job.require_cuda()


def internal_error(status: int, error: str) -> JSONResponse:
    return JSONResponse(status_code=status, content={"ok": False, "error": error, "message": "Internal resident job request was rejected."})


@app.get("/internal/capacity", response_model=None)
def internal_capacity(x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, Any] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return internal_error(403, "internal_auth_failed")
    with _ACTIVE_JOBS_LOCK, _MODEL_WORK_LOCK:
        state = "running" if _ACTIVE_JOBS or _MODEL_WORK_DEPTH else "ready" if MODEL_CACHE else "cold"
        return {"model_state": state, "active_runs": len(_ACTIVE_JOBS)}


@app.get("/internal/jobs/{run_id}", response_model=None)
def internal_job_status(run_id: str, x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, str] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return internal_error(403, "internal_auth_failed")
    try:
        stage = resident_stage(run_id)
    except RuntimeError:
        return internal_error(404, "resident_job_not_found")
    with _ACTIVE_JOBS_LOCK:
        state = "running" if run_id in _ACTIVE_JOBS else resident_terminal_state(stage)
    return {"run_id": run_id, "state": state}


@app.post("/internal/jobs/{run_id}/cancel", response_model=None)
def internal_job_cancel(run_id: str, x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, str] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return internal_error(403, "internal_auth_failed")
    if not RUN_ID.fullmatch(run_id):
        return internal_error(404, "resident_job_not_found")
    with _ACTIVE_JOBS_LOCK:
        cancelled = _ACTIVE_JOBS.get(run_id)
        if cancelled is None:
            return internal_error(404, "resident_job_not_found")
        cancelled.set()
    return {"run_id": run_id, "state": "running"}


@app.post("/internal/jobs", response_model=None)
def internal_job_start(payload: dict[str, Any], x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, str] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return internal_error(403, "internal_auth_failed")
    if set(payload) != {"run_id"} or not isinstance(payload.get("run_id"), str):
        return internal_error(400, "resident_job_invalid")
    run_id = payload["run_id"]
    try:
        stage = resident_stage(run_id)
        resident_regular(stage, "input", "source")
        request_path = resident_regular(stage, "input", "request.json")
        runner_config_path = resident_regular(stage, "input", "runner_config.json")
    except RuntimeError:
        return internal_error(400, "resident_job_invalid")
    terminal = resident_terminal_state(stage)
    if terminal != "unknown":
        return {"run_id": run_id, "state": terminal}
    cancelled = threading.Event()
    with _ACTIVE_JOBS_LOCK:
        if run_id in _ACTIVE_JOBS:
            return internal_error(409, "resident_job_active")
        _ACTIVE_JOBS[run_id] = cancelled
    state = "failed"
    try:
        with model_work():
            job.run_job(
                stage,
                request_path.parent,
                stage / "output",
                runner_config_path,
                asr_loader=resident_asr_loader,
                cuda_guard=resident_cuda_guard,
                cancelled=cancelled.is_set,
            )
        state = "cancelled" if cancelled.is_set() else "succeeded"
    except RuntimeError as exc:
        state = "cancelled" if str(exc) == "job_cancelled" else "failed"
    except Exception:
        state = "failed"
    try:
        write_resident_terminal_state(stage, state)
    except Exception:
        return internal_error(500, "resident_job_state_failed")
    finally:
        with _ACTIVE_JOBS_LOCK:
            _ACTIVE_JOBS.pop(run_id, None)
    return {"run_id": run_id, "state": state}


@app.get("/health")
def health() -> dict[str, Any]:
    storage, errors = storage_status()
    return {
        "ok": True,
        "service": "whisper-asr",
        "ready": not errors,
        "runtime_level": runtime_level(),
        "real_inference": env_enabled(os.getenv("WHISPER_REAL_INFERENCE", "1")),
        "async_routing": "cpu" if resident_cpu_policy_enabled() else "gpu_wait",
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
