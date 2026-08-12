from __future__ import annotations

import asyncio
import json
import os
import subprocess
import threading
import time
from pathlib import Path
from typing import Any

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import JSONResponse, Response
from PIL import Image

from image_contract import ImageToolsError, build_upscale_argv, decode_image, private_job_directory, resolve_backend, select_model, validate_output_pixels
from model_runtime import DEFAULT_MODEL_ROOT, ModelRuntimeError, verify_ready


app = FastAPI(title="3waAIHub Image Tools")
_INFERENCE_LOCK = threading.Lock()
_CLI_ERROR_CODES = frozenset({"backend_unavailable", "model_not_present", "model_load_failed", "inference_failed"})


def model_dir() -> Path:
    return Path(os.getenv("IMAGE_TOOLS_MODEL_DIR", str(DEFAULT_MODEL_ROOT)))


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
    return {"ok": True, "service": "image-tools", "ready": True, "runtime_level": "L4a-model-init-smoke", "runtime_ready": True}


def _report(stdout: str, *, model: str, backend: str, output: Path) -> dict[str, object]:
    try:
        report = json.loads(stdout)
        if not isinstance(report, dict) or set(report) != {"model", "backend", "width", "height", "elapsed_ms"}:
            raise ValueError
        with Image.open(output) as rendered:
            rendered.load()
            width, height = rendered.size
            if rendered.format != "PNG" or report["model"] != model or report["backend"] != backend or report["width"] != width or report["height"] != height:
                raise ValueError
        if any(not isinstance(report[key], int) or isinstance(report[key], bool) or report[key] < 1 for key in ("width", "height", "elapsed_ms")):
            raise ValueError
        return report
    except (OSError, ValueError, TypeError, json.JSONDecodeError) as exc:
        raise RuntimeError("inference_failed") from exc


def _cli_error(stdout: str) -> str:
    try:
        payload = json.loads(stdout)
        code = payload.get("error") if isinstance(payload, dict) and set(payload) == {"error"} else None
        return code if isinstance(code, str) and code in _CLI_ERROR_CODES else "inference_failed"
    except (TypeError, ValueError, json.JSONDecodeError):
        return "inference_failed"


def _run(source_bytes: bytes, *, model: str, backend: str) -> tuple[bytes, dict[str, object]]:
    selection = select_model(model)
    source = decode_image(source_bytes, operation="upscale")
    validate_output_pixels(source.width * source.height, selection.scale)
    verify_ready(model_dir())
    with _INFERENCE_LOCK, private_job_directory() as workspace:
        source_path = workspace / "source.bin"
        output_path = workspace / "output.png"
        source_path.write_bytes(source_bytes)
        source_path.chmod(0o600)
        argv = build_upscale_argv(workspace=workspace, source=source_path, output=output_path, model=model, backend=backend, model_dir=model_dir())
        if backend == "cpu":
            argv.append("--fp32")
        result = subprocess.run(argv, shell=False, check=False, capture_output=True, text=True, timeout=900)
        if result.returncode != 0:
            code = _cli_error(result.stdout)
            if code in {"model_not_present", "model_load_failed"}:
                raise ModelRuntimeError(code)
            if code == "backend_unavailable":
                raise ImageToolsError(code)
            raise RuntimeError("inference_failed")
        report = _report(result.stdout, model=model, backend=backend, output=output_path)
        return output_path.read_bytes(), report


@app.post("/process/image", response_model=None)
async def process_image(
    image: UploadFile | None = File(default=None),
    operation: str = Form(default="upscale"),
    model: str = Form(default="realesrgan-x4plus"),
    backend: str = Form(default="auto"),
) -> Response:
    started = time.perf_counter()
    if image is None:
        return error_response(400, "file_required")
    if operation != "upscale":
        return error_response(400, "invalid_operation")
    try:
        select_model(model)
        effective = resolve_backend(backend, cuda_available=cuda_available())
        source_bytes = await image.read(50 * 1024 * 1024 + 1)
        png, report = await run_in_threadpool(_run, source_bytes, model=model, backend=effective)
    except ImageToolsError as exc:
        status = 415 if exc.code == "unsupported_media_type" else 413 if exc.code == "payload_too_large" else 503 if exc.code == "backend_unavailable" else 400
        return error_response(status, exc.code)
    except ModelRuntimeError as exc:
        return error_response(404 if exc.code == "model_not_present" else 503, exc.code)
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
