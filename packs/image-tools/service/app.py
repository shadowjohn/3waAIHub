from __future__ import annotations

import io
import os
import threading
import time
from pathlib import Path

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import JSONResponse, Response
from PIL import Image
import numpy as np

import colorize_runtime
import model_runtime
from image_contract import ImageToolsError, decode_image, resolve_backend, resolve_outscale, select_model, validate_output_pixels
from model_runtime import DEFAULT_MODEL_ROOT, ModelRuntimeError, verify_ready


app = FastAPI(title="3waAIHub Image Tools")
RUNTIME_LEVEL = "L5-benchmark-ready"
_INFERENCE_LOCK = threading.Lock()
_UPSAMPLER_CACHE = None
_COLORIZER_CACHE = None


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


@app.get("/health")
def health() -> dict[str, object]:
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
        if configured_root.is_symlink():
            verify_ready(configured_root)
        try:
            root = configured_root.resolve()
        except (OSError, RuntimeError):
            verify_ready(configured_root)
            raise
        key = (root, selected.alias, backend)
        if _UPSAMPLER_CACHE is None or _UPSAMPLER_CACHE[0] != key:
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
        if configured_root.is_symlink():
            colorize_runtime.verify_ready(configured_root)
        try:
            root = configured_root.resolve()
        except (OSError, RuntimeError):
            colorize_runtime.verify_ready(configured_root)
            raise
        key = (root, backend)
        if _COLORIZER_CACHE is None or _COLORIZER_CACHE[0] != key:
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
        effective = resolve_backend(backend, cuda_available=cuda_available() if backend != "cpu" else False)
        source_bytes = await image.read(50 * 1024 * 1024 + 1)
        if operation == "colorize":
            if model is not None or outscale is not None:
                return error_response(400, "invalid_request")
            png, report = await run_in_threadpool(_run_colorize, source_bytes, backend=effective)
        else:
            model = model or "realesrgan-x4plus"
            select_model(model)
            selected_outscale = resolve_outscale(outscale, model=model)
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
