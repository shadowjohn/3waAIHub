from __future__ import annotations

import hashlib
import importlib.util
import json
import math
import os
import random
import struct
import tempfile
import time
import wave
from pathlib import Path
from typing import Any

from fastapi import FastAPI
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

app = FastAPI(title="3waAIHub VoxCPM2 Experimental TTS")
_MODEL: Any | None = None


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

    _MODEL = VoxCPM.from_pretrained(os.getenv("VOXCPM2_MODEL_ID", "openbmb/VoxCPM2"), load_denoiser=False)
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
