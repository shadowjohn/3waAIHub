from __future__ import annotations

import hashlib
import importlib.util
import json
import math
import os
import random
import gc
import re
import secrets
import struct
import tempfile
import threading
import time
import wave
from contextlib import contextmanager
from pathlib import Path
from typing import Any

from fastapi import FastAPI, Header
from fastapi.exceptions import RequestValidationError
from fastapi.responses import FileResponse, JSONResponse
from pydantic import BaseModel, Field

app = FastAPI(title="3waAIHub VoxCPM2 Experimental TTS")
_MODEL: Any | None = None
_MODEL_WORK_LOCK = threading.RLock()
_MODEL_WORK_DEPTH = 0
_IDLE_TIMER: threading.Timer | None = None
_ACTIVE_JOBS: dict[str, threading.Event] = {}
_ACTIVE_JOBS_LOCK = threading.RLock()
RUN_ID = re.compile(r"^[a-z0-9][a-z0-9_.-]{0,95}$")
TERMINAL_JOB_STATES = {"succeeded", "failed", "cancelled"}


def runtime_level() -> str:
    return "L5-benchmark-ready"


def env_enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


def env_int(name: str, fallback: int) -> int:
    try:
        return int(os.getenv(name, str(fallback)))
    except ValueError:
        return fallback


def configure_env() -> None:
    model_dir = os.getenv("VOXCPM2_MODEL_DIR", "/models/voxcpm2")
    cache_dir = os.getenv("VOXCPM2_CACHE_DIR", "/cache/voxcpm2")
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
        os.getenv("VOXCPM2_SERVICE_DATA_DIR", "/data/service"),
        env["HF_HOME"],
        env["XDG_CACHE_HOME"],
        env["HOME"],
        "/data/voice_profiles",
    ]:
        Path(path).mkdir(parents=True, exist_ok=True)


def resident_idle_seconds() -> int:
    return max(0, env_int("VOXCPM2_IDLE_UNLOAD_SECONDS", 0))


def _cancel_idle_timer() -> None:
    global _IDLE_TIMER
    if _IDLE_TIMER is not None:
        _IDLE_TIMER.cancel()
        _IDLE_TIMER = None


def _unload_idle_model() -> None:
    global _IDLE_TIMER, _MODEL
    with _MODEL_WORK_LOCK:
        _IDLE_TIMER = None
        if _MODEL_WORK_DEPTH or _MODEL is None:
            return
        _MODEL = None
        gc.collect()
        try:
            import torch

            if torch.cuda.is_available():
                torch.cuda.empty_cache()
        except Exception:
            pass


def _schedule_idle_unload() -> None:
    global _IDLE_TIMER
    seconds = resident_idle_seconds()
    if seconds <= 0 or _MODEL is None:
        return
    _cancel_idle_timer()
    _IDLE_TIMER = threading.Timer(seconds, _unload_idle_model)
    _IDLE_TIMER.daemon = True
    _IDLE_TIMER.start()


@contextmanager
def model_work() -> Any:
    global _MODEL_WORK_DEPTH
    with _MODEL_WORK_LOCK:
        _cancel_idle_timer()
        _MODEL_WORK_DEPTH += 1
        try:
            yield
        finally:
            _MODEL_WORK_DEPTH -= 1
            if _MODEL_WORK_DEPTH == 0:
                _schedule_idle_unload()


def internal_authorized(token: str | None) -> bool:
    expected = os.getenv("VOXCPM2_INTERNAL_JOB_TOKEN", "")
    return bool(expected and token and secrets.compare_digest(expected, token))


def resident_jobs_root() -> Path:
    root = Path(os.getenv("VOXCPM2_SERVICE_DATA_DIR", "/data/service")) / "resident_jobs"
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
        state_path = resident_regular(stage, "terminal.json")
        payload = json.loads(state_path.read_text(encoding="utf-8"))
    except (OSError, ValueError, RuntimeError):
        return "unknown"
    return str(payload.get("state")) if isinstance(payload, dict) and set(payload) == {"state"} and payload.get("state") in TERMINAL_JOB_STATES else "unknown"


def write_resident_terminal_state(stage: Path, state: str) -> None:
    if state not in TERMINAL_JOB_STATES:
        raise RuntimeError("resident_job_invalid")
    target = stage / "terminal.json"
    if target.exists() and (target.is_symlink() or not target.is_file()):
        raise RuntimeError("resident_job_invalid")
    temporary = stage / ".terminal.json.tmp"
    if temporary.exists() and (temporary.is_symlink() or not temporary.is_file()):
        raise RuntimeError("resident_job_invalid")
    with temporary.open("w", encoding="utf-8") as handle:
        json.dump({"state": state}, handle, separators=(",", ":"))
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    temporary.replace(target)


def storage_path_status(path: str, writable_expected: bool = True) -> dict[str, Any]:
    target = Path(path)
    exists = target.is_dir()
    readable = exists and os.access(target, os.R_OK)
    writable = False
    error = ""
    if exists and readable and writable_expected:
        try:
            with tempfile.NamedTemporaryFile(prefix=".3waaihub-write-", dir=target, delete=False) as handle:
                test_path = Path(handle.name)
            test_path.unlink(missing_ok=True)
            writable = True
        except OSError as exc:
            error = str(exc)
    elif exists and readable:
        writable = os.access(target, os.W_OK)
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
    configure_env()
    storage = {
        "models": storage_path_status(os.getenv("VOXCPM2_MODEL_DIR", "/models/voxcpm2")),
        "cache": storage_path_status(os.getenv("VOXCPM2_CACHE_DIR", "/cache/voxcpm2")),
        "service_data": storage_path_status(os.getenv("VOXCPM2_SERVICE_DATA_DIR", "/data/service")),
        "voice_profiles": storage_path_status("/data/voice_profiles", writable_expected=False),
    }
    errors = [
        f"{name} {key} failed: {status['path']}"
        for name, status in storage.items()
        for key in ("exists", "readable")
        if not status[key]
    ]
    for name, status in storage.items():
        if name != "voice_profiles" and not status["writable"]:
            errors.append(f"{name} writable failed: {status['path']}")
    return storage, errors


def split_text(text: str, chunk_chars: int | None = None) -> list[str]:
    limit = max(80, chunk_chars or env_int("VOXCPM2_CHUNK_CHARS", 260))
    chunks: list[str] = []
    current = ""
    for char in text.strip():
        current += char
        if len(current) >= limit and char in "。！？!?；;，,\n":
            chunks.append(current.strip())
            current = ""
    if current.strip():
        chunks.append(current.strip())
    result: list[str] = []
    for chunk in chunks or [text.strip()]:
        while len(chunk) > limit:
            result.append(chunk[:limit].strip())
            chunk = chunk[limit:]
        if chunk.strip():
            result.append(chunk.strip())
    return result


class TtsRequest(BaseModel):
    text: str = Field(min_length=1)
    mode: str = "design"
    real_inference: bool | int | str | None = None
    voice_prompt: str | None = None
    control: str | None = None
    seed: int | None = None
    format: str = "wav"
    reference_wav_path: str | None = None
    prompt_wav_path: str | None = None
    prompt_text: str | None = None
    voice_profile_id: int | None = None
    reference_audio_sha256: str | None = None


class VoiceDesignRequest(BaseModel):
    voice_prompt: str = Field(min_length=1)
    seed: int | None = None


def response_error(status: int, error: str, message: str) -> JSONResponse:
    return JSONResponse(status_code=status, content={"success": False, "error": error, "message": message})


@app.exception_handler(RequestValidationError)
async def request_validation_error(_: Any, __: RequestValidationError) -> JSONResponse:
    return response_error(400, "bad_request", "Invalid TTS request.")


def tts_text(request: TtsRequest) -> str:
    prompt = request.control if request.mode == "clone" else request.voice_prompt
    prompt = (prompt or os.getenv("VOXCPM2_DEFAULT_VOICE_PROMPT", "")).strip()
    return f"({prompt}){request.text}" if prompt else request.text


def artifact_dir() -> Path:
    path = Path(os.getenv("VOXCPM2_SERVICE_DATA_DIR", "/data/service")) / "artifacts"
    path.mkdir(parents=True, exist_ok=True)
    return path


def write_mock_wav(path: Path, text: str, seed: int, sample_rate: int) -> int:
    rng = random.Random(seed + int(hashlib.sha256(text.encode("utf-8")).hexdigest()[:8], 16))
    duration_ms = max(900, min(30000, 650 + len(text) * 95))
    frames = int(sample_rate * duration_ms / 1000)
    base_freq = 180 + rng.randint(0, 80)
    with wave.open(str(path), "wb") as handle:
        handle.setnchannels(1)
        handle.setsampwidth(2)
        handle.setframerate(sample_rate)
        for index in range(frames):
            envelope = min(1.0, index / max(1, sample_rate // 20), (frames - index) / max(1, sample_rate // 20))
            freq = base_freq + 18 * math.sin(index / sample_rate * 2 * math.pi * 1.7)
            sample = int(11000 * envelope * math.sin(2 * math.pi * freq * index / sample_rate))
            handle.writeframesraw(struct.pack("<h", sample))
    return duration_ms


def set_runtime_seed(seed: int) -> None:
    random.seed(seed)
    try:
        import numpy as np

        np.random.seed(seed % (2**32 - 1))
    except Exception:
        pass
    try:
        import torch

        torch.manual_seed(seed)
        if torch.cuda.is_available():
            torch.cuda.manual_seed_all(seed)
    except Exception:
        pass


def validate_reference_path(path: str | None) -> Path | None:
    if not path:
        return None
    reference = Path(path)
    root = Path("/data/voice_profiles").resolve()
    try:
        real = reference.resolve(strict=True)
    except OSError:
        raise ValueError("voice_profile_required") from None
    if not real.is_file() or root not in [real, *real.parents]:
        raise ValueError("voice_profile_forbidden")
    return real


def validate_clone_inputs(request: TtsRequest) -> tuple[Path, Path | None]:
    reference = validate_reference_path(request.reference_wav_path)
    if reference is None:
        raise ValueError("voice_profile_required")
    if request.mode != "ultimate_clone":
        return reference, None
    prompt = validate_reference_path(request.prompt_wav_path)
    if prompt is None or prompt != reference:
        raise ValueError("ultimate_clone_prompt_wav_required")
    if not (request.prompt_text or "").strip():
        raise ValueError("ultimate_clone_prompt_text_required")
    return reference, prompt


def voxcpm_model() -> Any:
    global _MODEL
    if _MODEL is not None:
        return _MODEL
    if importlib.util.find_spec("voxcpm") is None:
        raise RuntimeError("runtime_dependency_missing")
    from voxcpm import VoxCPM

    _MODEL = VoxCPM.from_pretrained(
        os.getenv("VOXCPM2_MODEL_ID", "openbmb/VoxCPM2"),
        load_denoiser=False,
        optimize=env_enabled(os.getenv("VOXCPM2_TORCH_COMPILE")),
    )
    return _MODEL


def write_real_wav(path: Path, request: TtsRequest, seed: int) -> int:
    if importlib.util.find_spec("soundfile") is None:
        raise RuntimeError("runtime_dependency_missing")
    import soundfile as sf

    model = voxcpm_model()
    set_runtime_seed(seed)
    kwargs: dict[str, Any] = {
        "text": tts_text(request),
        "cfg_value": 2.0,
        "inference_timesteps": 10,
    }
    if request.mode in {"clone", "ultimate_clone"}:
        reference, prompt = validate_clone_inputs(request)
        kwargs["reference_wav_path"] = str(reference)
        if request.mode == "ultimate_clone":
            kwargs["prompt_wav_path"] = str(prompt)
            kwargs["prompt_text"] = request.prompt_text.strip()
    wav = model.generate(**kwargs)
    sample_rate = int(getattr(getattr(model, "tts_model", None), "sample_rate", env_int("VOXCPM2_SAMPLE_RATE", 48000)))
    sf.write(str(path), wav, sample_rate)
    return int(round(len(wav) / sample_rate * 1000)) if hasattr(wav, "__len__") else 0


def request_real_inference(request: TtsRequest) -> bool:
    if isinstance(request.real_inference, bool):
        return request.real_inference
    if isinstance(request.real_inference, int):
        return request.real_inference == 1
    return env_enabled(str(request.real_inference)) if request.real_inference is not None else False


def manifest_payload(request: TtsRequest, filename: str, sample_rate: int, duration_ms: int, seed: int, mock: bool, chunks: list[str], real_requested: bool) -> dict[str, Any]:
    return {
        "ai_generated": True,
        "service": "tts-voxcpm2",
        "model": "VoxCPM2",
        "model_id": os.getenv("VOXCPM2_MODEL_ID", "openbmb/VoxCPM2"),
        "runtime_level": runtime_level(),
        "mode": request.mode,
        "seed": seed,
        "format": "wav",
        "sample_rate": sample_rate,
        "duration_ms": duration_ms,
        "text_chars": len(request.text),
        "chunks": len(chunks),
        "voice_profile_id": request.voice_profile_id,
        "reference_audio_sha256": request.reference_audio_sha256,
        "artifact": filename,
        "mock": mock,
        "real_inference_requested": real_requested,
        "notice": "AI 合成語音；clone modes require a managed voice profile.",
    }


@app.get("/health")
def health() -> dict[str, Any]:
    storage, errors = storage_status()
    return {
        "ok": True,
        "service": "tts-voxcpm2",
        "ready": not errors,
        "runtime_level": runtime_level(),
        "model": os.getenv("VOXCPM2_MODEL_ID", "openbmb/VoxCPM2"),
        "real_inference": env_enabled(os.getenv("VOXCPM2_REAL_INFERENCE")),
        "torch_compile": env_enabled(os.getenv("VOXCPM2_TORCH_COMPILE")),
        "dependency_available": importlib.util.find_spec("voxcpm") is not None and importlib.util.find_spec("soundfile") is not None,
        "dependencies": {
            "voxcpm": importlib.util.find_spec("voxcpm") is not None,
            "soundfile": importlib.util.find_spec("soundfile") is not None,
        },
        "sample_rate": env_int("VOXCPM2_SAMPLE_RATE", 48000),
        "modes": ["design", "clone", "ultimate_clone"],
        "lifecycle": os.getenv("VOXCPM2_GPU_POLICY", "exclusive_gpu"),
        "storage": storage,
        "errors": errors,
    }


@app.get("/v1/models")
def models() -> dict[str, Any]:
    return {
        "success": True,
        "models": [
            {
                "id": os.getenv("VOXCPM2_MODEL_ID", "openbmb/VoxCPM2"),
                "name": "VoxCPM2",
                "capability": "text_to_speech",
                "runtime_level": runtime_level(),
                "sample_rate": env_int("VOXCPM2_SAMPLE_RATE", 48000),
                "modes": ["design", "clone", "ultimate_clone"],
            }
        ],
    }


@app.post("/v1/voice-design")
def voice_design(request: VoiceDesignRequest) -> dict[str, Any]:
    return {
        "success": True,
        "mode": "design",
        "voice_prompt": request.voice_prompt,
        "seed": request.seed if request.seed is not None else env_int("VOXCPM2_DEFAULT_SEED", 42),
        "model": "VoxCPM2",
        "message": "Use this prompt in /v1/tts voice_prompt for voice design.",
    }


@app.get("/artifacts/{filename}", response_model=None)
def artifact(filename: str) -> FileResponse | JSONResponse:
    path = Path(filename)
    if path.name != filename or path.suffix not in {".wav", ".json"}:
        return response_error(404, "artifact_not_found", "Artifact not found.")
    target = artifact_dir() / filename
    if not target.is_file():
        return response_error(404, "artifact_not_found", "Artifact not found.")
    media_type = "audio/wav" if path.suffix == ".wav" else "application/json"
    return FileResponse(target, media_type=media_type)


def internal_error(status: int, error: str) -> JSONResponse:
    return response_error(status, error, "Internal resident job request was rejected.")


@app.get("/internal/capacity", response_model=None)
def internal_capacity(x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, Any] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return internal_error(403, "internal_auth_failed")
    with _ACTIVE_JOBS_LOCK, _MODEL_WORK_LOCK:
        state = "running" if _ACTIVE_JOBS or _MODEL_WORK_DEPTH else "ready" if _MODEL is not None else "cold"
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
        request_path = resident_regular(stage, "input", "request.json")
        runner_config_path = resident_regular(stage, "input", "runner_config.json")
        managed_reference_path = resident_regular(stage, "input", "source") if (stage / "input" / "source").exists() else None
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
        import job

        with model_work():
            job.run_job(
                stage,
                request_path.parent,
                stage / "output",
                runner_config_path,
                cancelled=cancelled.is_set,
                managed_reference_path=managed_reference_path,
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


@app.post("/v1/tts")
def tts(request: TtsRequest) -> JSONResponse:
    started = time.perf_counter()
    if request.format.lower() != "wav":
        return response_error(400, "format_not_supported", "Only wav output is supported in this phase.")
    if request.mode not in {"design", "clone", "ultimate_clone"}:
        return response_error(400, "bad_request", "mode must be design, clone, or ultimate_clone.")
    if len(request.text) > env_int("VOXCPM2_MAX_INPUT_CHARS", 6000):
        return response_error(413, "input_too_long", "Input text is too long.")
    if request.mode in {"clone", "ultimate_clone"}:
        try:
            validate_clone_inputs(request)
        except ValueError as exc:
            code = str(exc)
            message = "Ultimate clone requires a confirmed managed voice profile." if request.mode == "ultimate_clone" else "Clone mode requires a managed voice profile."
            return response_error(403 if code == "voice_profile_forbidden" else 400, code, message)

    configure_env()
    seed = request.seed if request.seed is not None else env_int("VOXCPM2_DEFAULT_SEED", 42)
    sample_rate = env_int("VOXCPM2_SAMPLE_RATE", 48000)
    chunks = split_text(request.text)
    name_hash = hashlib.sha256(f"{time.time_ns()}:{seed}:{request.text}".encode("utf-8")).hexdigest()[:12]
    filename = f"tts_{name_hash}.wav"
    path = artifact_dir() / filename
    real_requested = request_real_inference(request) or env_enabled(os.getenv("VOXCPM2_REAL_INFERENCE"))
    mock = not real_requested

    try:
        if mock:
            duration_ms = write_mock_wav(path, "\n".join(chunks), seed, sample_rate)
        else:
            with model_work():
                duration_ms = write_real_wav(path, request, seed)
    except RuntimeError as exc:
        code = str(exc) if str(exc) in {"runtime_dependency_missing", "model_load_failed"} else "tts_failed"
        return response_error(501 if code == "runtime_dependency_missing" else 500, code, "VoxCPM2 runtime is not ready.")
    except Exception:
        return response_error(500, "tts_failed", "TTS inference failed.")

    manifest = manifest_payload(request, filename, sample_rate, duration_ms, seed, mock, chunks, real_requested)
    manifest["elapsed_ms"] = int(round((time.perf_counter() - started) * 1000))
    manifest_path = path.with_suffix(".json")
    try:
        manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    except OSError:
        return response_error(500, "artifact_write_failed", "Cannot write TTS manifest.")

    return JSONResponse(content={
        "success": True,
        "mock": mock,
        "real_inference_requested": real_requested,
        "mode": request.mode,
        "artifact_url": f"/artifacts/{filename}",
        "sample_rate": sample_rate,
        "duration_ms": duration_ms,
        "model": "VoxCPM2",
        "seed": seed,
        "runtime_level": runtime_level(),
        "chunks": len(chunks),
        "manifest": f"/artifacts/{manifest_path.name}",
        "elapsed_ms": manifest["elapsed_ms"],
    })
