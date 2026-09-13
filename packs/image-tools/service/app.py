from __future__ import annotations

import gc
import io
import math
import os
import sys
import threading
import time
from pathlib import Path
from contextlib import asynccontextmanager

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import JSONResponse, Response
from PIL import Image
import numpy as np
import requests

import colorize_runtime
import model_runtime
import image_contract
from image_contract import ImageToolsError, decode_image, resolve_backend, resolve_outscale, select_model, validate_output_pixels
from model_runtime import DEFAULT_MODEL_ROOT, ModelRuntimeError, verify_ready


RUNTIME_LEVEL = "L5-benchmark-ready"
_INFERENCE_LOCK = threading.Lock()
_UPSAMPLER_CACHE = None
_COLORIZER_CACHE = None
_RESIDENT_UPSAMPLERS = {}
_RESIDENT_COLORIZER = None
_RESIDENT_READY = False
_RESIDENT_REASON = "runtime_not_ready"


def runtime_role() -> str:
    mode = os.getenv("IMAGE_TOOLS_EXECUTION_MODE", "on_demand")
    if mode not in {"on_demand", "resident_dual"}:
        raise ValueError("invalid IMAGE_TOOLS_EXECUTION_MODE")
    role = os.getenv("IMAGE_TOOLS_ROLE", "api" if mode == "resident_dual" else "on_demand")
    if role not in ({"api", "upscale", "colorize"} if mode == "resident_dual" else {"on_demand"}):
        raise ValueError("invalid IMAGE_TOOLS_ROLE for IMAGE_TOOLS_EXECUTION_MODE")
    return role


def resident_budget(role: str) -> int:
    value = os.getenv(f"IMAGE_TOOLS_{role.upper()}_RESIDENT_VRAM_MB", "3072" if role == "upscale" else "1024")
    if not value.isascii() or not value.isdigit():
        raise ValueError("invalid IMAGE_TOOLS resident VRAM budget")
    return int(value)


def _preload(role: str) -> None:
    global _RESIDENT_READY, _RESIDENT_REASON, _RESIDENT_COLORIZER
    try:
        if not cuda_available():
            raise ModelRuntimeError("backend_unavailable")
        if role == "upscale":
            root = model_dir()
            verify_ready(root)
            root = root.resolve()
            for alias in image_contract._MODELS:
                selected = select_model(alias)
                _RESIDENT_UPSAMPLERS[alias] = model_runtime.build_upsampler(alias, "cuda", root / selected.filename)
        else:
            root = colorize_model_dir()
            colorize_runtime.verify_ready(root)
            _RESIDENT_COLORIZER = colorize_runtime.build_colorizer("cuda", root.resolve())
        _RESIDENT_READY = True
    except (ModelRuntimeError, colorize_runtime.ColorizeRuntimeError) as exc:
        _RESIDENT_REASON = exc.code if exc.code in {"model_not_present", "model_load_failed", "backend_unavailable"} else "model_load_failed"
    except Exception:
        _RESIDENT_REASON = "model_load_failed"


@asynccontextmanager
async def lifespan(_app):
    global _RESIDENT_READY, _RESIDENT_REASON, _RESIDENT_COLORIZER
    role = runtime_role()
    if role != "on_demand":
        for worker in ("upscale", "colorize"):
            resident_budget(worker)
    _RESIDENT_READY = False
    _RESIDENT_REASON = "runtime_not_ready"
    _RESIDENT_UPSAMPLERS.clear()
    _RESIDENT_COLORIZER = None
    if role in {"upscale", "colorize"}:
        await run_in_threadpool(_preload, role)
    yield


app = FastAPI(title="3waAIHub Image Tools", lifespan=lifespan)


def _release_model_caches() -> None:
    global _UPSAMPLER_CACHE, _COLORIZER_CACHE
    if runtime_role() in {"upscale", "colorize"}:
        return
    # Explicit CPU requests in ingress retain the on-demand import boundary.
    _UPSAMPLER_CACHE = None
    _COLORIZER_CACHE = None
    source_root = str(Path(os.getenv("DDCOLOR_SOURCE_DIR", "/opt/ddcolor")))
    sys.path[:] = [entry for entry in sys.path if str(entry) != source_root]
    for name in tuple(sys.modules):
        if name == "basicsr" or name.startswith("basicsr.") or name == "ddcolor" or name.startswith("ddcolor.") or name == "realesrgan" or name.startswith("realesrgan."):
            sys.modules.pop(name, None)
    gc.collect()
    try:
        import torch
        if torch.cuda.is_available():
            torch.cuda.empty_cache()
    except Exception:
        pass


def model_dir() -> Path:
    return Path(os.getenv("IMAGE_TOOLS_MODEL_DIR", str(DEFAULT_MODEL_ROOT)))


def colorize_model_dir() -> Path:
    return Path(os.getenv("IMAGE_TOOLS_COLORIZE_MODEL_DIR", str(colorize_runtime.DEFAULT_COLORIZE_MODEL_ROOT)))


def cuda_available() -> bool:
    try:
        import torch
        return bool(torch.cuda.is_available())
    except Exception:
        return False


def error_response(status: int, code: str) -> JSONResponse:
    return JSONResponse(status_code=status, content={"ok": False, "error": code})


def _worker_url(role: str) -> str:
    return os.getenv(f"IMAGE_TOOLS_{role.upper()}_WORKER_URL", f"http://image-tools-{role}:8000").rstrip("/")


def _worker_row(role: str) -> dict[str, object]:
    return {"role": role, "ready": False, "loaded_models": [], "resident_vram_mb": resident_budget(role),
            "torch_allocated_mb": 0, "torch_reserved_mb": 0}


def _probe_worker(role: str) -> dict[str, object]:
    row = _worker_row(role)
    row["reason"] = "runtime_not_ready"
    try:
        response = requests.get(_worker_url(role) + "/internal/health", timeout=(1, 2), allow_redirects=False)
        payload = response.json()
        if response.status_code != 200 or not isinstance(payload, dict) or payload.get("role") != role:
            return row
        allowed = list(image_contract._MODELS) if role == "upscale" else ["ddcolor-modelscope"]
        models = payload.get("loaded_models")
        if isinstance(models, list):
            row["loaded_models"] = [model for model in allowed if model in models]
        for key in ("torch_allocated_mb", "torch_reserved_mb"):
            value = payload.get(key)
            if type(value) in {int, float} and math.isfinite(value) and value >= 0:
                row[key] = value
        row["ready"] = payload.get("ready") is True and row["loaded_models"] == allowed
        if row["ready"]:
            row.pop("reason")
        elif payload.get("reason") in {"model_not_present", "model_load_failed", "backend_unavailable"}:
            row["reason"] = payload["reason"]
    except (requests.RequestException, ValueError, TypeError, OverflowError):
        pass
    return row


@app.get("/internal/health", include_in_schema=False)
def internal_health():
    role = runtime_role()
    if role not in {"upscale", "colorize"}:
        return error_response(404, "not_found")
    row = _worker_row(role)
    row["ready"] = _RESIDENT_READY
    row["loaded_models"] = list(_RESIDENT_UPSAMPLERS) if role == "upscale" else (["ddcolor-modelscope"] if _RESIDENT_COLORIZER is not None else [])
    if not _RESIDENT_READY:
        row["reason"] = _RESIDENT_REASON
    try:
        import torch
        if torch.cuda.is_available():
            row["torch_allocated_mb"] = torch.cuda.memory_allocated() / (1024 * 1024)
            row["torch_reserved_mb"] = torch.cuda.memory_reserved() / (1024 * 1024)
    except Exception:
        pass
    return row


@app.get("/health")
def health() -> dict[str, object]:
    role = runtime_role()
    if role in {"upscale", "colorize"}:
        return internal_health()
    if role == "api":
        workers = {worker: _probe_worker(worker) for worker in ("upscale", "colorize")}
        ready = all(worker["ready"] for worker in workers.values())
        return {"ok": True, "service": "image-tools", "role": role, "ready": ready, "runtime_ready": ready,
                "runtime_level": RUNTIME_LEVEL if ready else "L1-contract", "workers": workers,
                "resident_vram_mb": sum(worker["resident_vram_mb"] for worker in workers.values())}
    try:
        verify_ready(model_dir())
    except ModelRuntimeError:
        return {"ok": True, "service": "image-tools", "ready": False, "runtime_level": "L1-contract", "runtime_ready": False}
    return {"ok": True, "service": "image-tools", "ready": True, "runtime_level": RUNTIME_LEVEL, "runtime_ready": True}


def _exit_stalled_inference() -> None:
    os._exit(1)


def _run(source_bytes: bytes, *, model: str, backend: str, outscale: int) -> tuple[bytes, dict[str, object]]:
    global _UPSAMPLER_CACHE
    started = time.perf_counter()
    source = decode_image(source_bytes, operation="upscale")
    validate_output_pixels(source.width * source.height, outscale)
    selected = select_model(model)
    configured_root = model_dir()
    with _INFERENCE_LOCK:
        if runtime_role() in {"upscale", "colorize"}:
            if runtime_role() != "upscale" or backend != "cuda" or not _RESIDENT_READY or selected.alias not in _RESIDENT_UPSAMPLERS:
                raise ModelRuntimeError("runtime_not_ready")
            upsampler = _RESIDENT_UPSAMPLERS[selected.alias]
        else:
            if configured_root.is_symlink():
                verify_ready(configured_root)
            try:
                root = configured_root.resolve()
            except (OSError, RuntimeError):
                verify_ready(configured_root)
                raise
            key = (root, selected.alias, backend)
            if _UPSAMPLER_CACHE is None or _UPSAMPLER_CACHE[0] != key:
                _release_model_caches()
                verify_ready(root)
                upsampler = model_runtime.build_upsampler(selected.alias, backend, root / selected.filename)
                _UPSAMPLER_CACHE = (key, upsampler)
            else:
                upsampler = _UPSAMPLER_CACHE[1]
        # ponytail: direct GPU calls cannot be cancelled safely in-process; container restart is the recovery boundary.
        timer = threading.Timer(900, _exit_stalled_inference)
        timer.daemon = True
        timer.start()
        try:
            output, _ = upsampler.enhance(np.asarray(source)[:, :, ::-1].copy(), outscale=outscale)
            width, height = source.width * outscale, source.height * outscale
            if getattr(output, "shape", None) != (height, width, 3):
                raise ValueError
            encoded = io.BytesIO()
            Image.fromarray(output[:, :, ::-1], "RGB").save(encoded, format="PNG")
        except Exception as exc:
            raise RuntimeError("inference_failed") from exc
        finally:
            timer.cancel()
    return encoded.getvalue(), {
        "model": selected.alias,
        "backend": backend,
        "width": width,
        "height": height,
        "elapsed_ms": max(1, int(round((time.perf_counter() - started) * 1000))),
    }


def _run_colorize(source_bytes: bytes, *, backend: str) -> tuple[bytes, dict[str, object]]:
    global _COLORIZER_CACHE
    started = time.perf_counter()
    source = decode_image(source_bytes, operation="colorize")
    configured_root = colorize_model_dir()
    with _INFERENCE_LOCK:
        if runtime_role() in {"upscale", "colorize"}:
            if runtime_role() != "colorize" or backend != "cuda" or not _RESIDENT_READY or _RESIDENT_COLORIZER is None:
                raise ModelRuntimeError("runtime_not_ready")
            colorizer = _RESIDENT_COLORIZER
        else:
            if configured_root.is_symlink():
                colorize_runtime.verify_ready(configured_root)
            try:
                root = configured_root.resolve()
            except (OSError, RuntimeError):
                colorize_runtime.verify_ready(configured_root)
                raise
            key = (root, backend)
            if _COLORIZER_CACHE is None or _COLORIZER_CACHE[0] != key:
                _release_model_caches()
                colorizer = colorize_runtime.build_colorizer(backend, root)
                _COLORIZER_CACHE = (key, colorizer)
            else:
                colorizer = _COLORIZER_CACHE[1]
        timer = threading.Timer(900, _exit_stalled_inference)
        timer.daemon = True
        timer.start()
        try:
            output = colorize_runtime.colorize(colorizer, source)
            encoded = io.BytesIO()
            output.save(encoded, format="PNG")
        finally:
            timer.cancel()
    return encoded.getvalue(), {
        "model": "ddcolor-modelscope",
        "backend": backend,
        "width": source.width,
        "height": source.height,
        "elapsed_ms": max(1, int(round((time.perf_counter() - started) * 1000))),
    }


def _proxy_image(source_bytes: bytes, *, operation: str, model: str | None, outscale: int | None) -> Response:
    source = decode_image(source_bytes, operation=operation)
    if operation == "upscale":
        validate_output_pixels(source.width * source.height, outscale)
    if not _probe_worker(operation)["ready"]:
        raise ModelRuntimeError("runtime_not_ready")
    data = {"operation": operation, "backend": "cuda"}
    if operation == "upscale":
        data.update(model=model, outscale=str(outscale))
    try:
        upstream = requests.post(_worker_url(operation) + "/internal/process/image",
                                 files={"image": ("image", source_bytes, "application/octet-stream")},
                                 data=data, timeout=(2, 910), allow_redirects=False)
    except requests.RequestException as exc:
        raise ModelRuntimeError("runtime_not_ready") from exc
    allowed_headers = {"content-type", "x-3waaihub-model", "x-3waaihub-backend", "x-3waaihub-elapsed-ms", "x-3waaihub-width", "x-3waaihub-height"}
    return Response(content=upstream.content, status_code=upstream.status_code,
                    headers={key: value for key, value in upstream.headers.items() if key.lower() in allowed_headers})


@app.post("/internal/process/image", response_model=None, include_in_schema=False)
async def internal_process_image(
    image: UploadFile | None = File(default=None),
    operation: str = Form(default="upscale"),
    model: str | None = Form(default=None),
    backend: str = Form(default="auto"),
    outscale: str | None = Form(default=None),
) -> Response:
    if runtime_role() not in {"upscale", "colorize"}:
        return error_response(404, "not_found")
    return await process_image(image, operation, model, backend, outscale)


@app.post("/process/image", response_model=None)
async def process_image(
    image: UploadFile | None = File(default=None),
    operation: str = Form(default="upscale"),
    model: str | None = Form(default=None),
    backend: str = Form(default="auto"),
    outscale: str | None = Form(default=None),
) -> Response:
    started = time.perf_counter()
    if image is None:
        return error_response(400, "file_required")
    if operation not in {"upscale", "colorize"}:
        return error_response(400, "invalid_operation")
    try:
        role = runtime_role()
        if role in {"upscale", "colorize"} and (operation != role or backend == "cpu"):
            return error_response(400, "invalid_request")
        if role in {"upscale", "colorize"} and not _RESIDENT_READY:
            raise ModelRuntimeError("runtime_not_ready")
        proxy = role == "api" and backend != "cpu"
        available = role != "on_demand" or (backend != "cpu" and cuda_available())
        effective = resolve_backend(backend, cuda_available=available)
        source_bytes = await image.read(50 * 1024 * 1024 + 1)
        if operation == "colorize":
            if model is not None or outscale is not None:
                return error_response(400, "invalid_request")
            if proxy:
                return await run_in_threadpool(_proxy_image, source_bytes, operation=operation, model=None, outscale=None)
            png, report = await run_in_threadpool(_run_colorize, source_bytes, backend=effective)
        else:
            model = model or "realesrgan-x4plus"
            select_model(model)
            selected_outscale = resolve_outscale(outscale, model=model)
            if proxy:
                return await run_in_threadpool(_proxy_image, source_bytes, operation=operation, model=model, outscale=selected_outscale)
            png, report = await run_in_threadpool(_run, source_bytes, model=model, backend=effective, outscale=selected_outscale)
    except ImageToolsError as exc:
        status = 415 if exc.code == "unsupported_media_type" else 413 if exc.code == "payload_too_large" else 503 if exc.code == "backend_unavailable" else 400
        return error_response(status, exc.code)
    except ModelRuntimeError as exc:
        return error_response(404 if exc.code == "model_not_present" else 503, exc.code)
    except colorize_runtime.ColorizeRuntimeError as exc:
        return error_response(404 if exc.code == "model_not_present" else 503 if exc.code == "model_load_failed" else 500, exc.code)
    except Exception:
        return error_response(500, "inference_failed")
    elapsed = max(1, int(round((time.perf_counter() - started) * 1000)))
    return Response(content=png, media_type="image/png", headers={
        "X-3waAIHub-Model": str(report["model"]),
        "X-3waAIHub-Backend": str(report["backend"]),
        "X-3waAIHub-Elapsed-Ms": str(elapsed),
        "X-3waAIHub-Width": str(report["width"]),
        "X-3waAIHub-Height": str(report["height"]),
    })
