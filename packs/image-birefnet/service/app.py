from __future__ import annotations

import importlib.util
import os
import threading
import time

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import JSONResponse, Response

from image_pipeline import PipelineError, decode_image, parse_options, postprocess_mask, prediction_to_mask, render_png
from model_runtime import ModelRuntimeError, load_model, model_health, reset_model
from provision_offline_assets import MODEL_REPOSITORY, MODEL_REVISION


app = FastAPI(title="3waAIHub BiRefNet Adapter", version="0.1.0")
MODEL_HEADER = f"{MODEL_REPOSITORY}@{MODEL_REVISION}"
MAX_AXIS_PX = 8192
MAX_DECODED_PIXELS = 10_000_000
_INFERENCE_LOCK = threading.Lock()


@app.get("/health")
def health() -> dict[str, object]:
    dependencies = {
        name: importlib.util.find_spec(name) is not None
        for name in ("torch", "transformers", "PIL", "numpy")
    }
    model = model_health()
    ready = bool(model["model_present"]) and all(dependencies.values())
    return {
        "ok": ready,
        "ready": ready,
        "runtime_level": "L5-benchmark-ready",
        "runtime_ready": ready,
        "dependencies": dependencies,
        **model,
    }


def error_response(status: int, code: str, message: str) -> JSONResponse:
    return JSONResponse(status_code=status, content={"ok": False, "error": code, "message": message})


def max_upload_bytes() -> int:
    try:
        megabytes = int(os.getenv("BIREFNET_MAX_UPLOAD_MB", "50"))
    except ValueError:
        megabytes = 50
    return min(50, max(1, megabytes)) * 1024 * 1024


async def bounded_upload(upload: UploadFile, limit: int) -> bytes:
    data = await upload.read(limit + 1)
    if len(data) > limit:
        raise PipelineError("payload_too_large", "image exceeds the byte limit")
    return data


def infer_alpha(source_rgb, model, device: str):
    import torch
    from torchvision import transforms

    transform = transforms.Compose([
        transforms.Resize((1024, 1024)),
        transforms.ToTensor(),
        transforms.Normalize([0.485, 0.456, 0.406], [0.229, 0.224, 0.225]),
    ])
    tensor = transform(source_rgb).unsqueeze(0).to(device)
    if device == "cuda":
        tensor = tensor.half()
    with torch.inference_mode():
        prediction = model(tensor)[-1].sigmoid().float().cpu()[0].squeeze()
    return prediction_to_mask(prediction.numpy(), source_rgb.size)


def is_cuda_out_of_memory(error: Exception) -> bool:
    import torch

    return isinstance(error, torch.cuda.OutOfMemoryError)


def cpu_fallback_enabled() -> bool:
    return os.getenv("BIREFNET_CPU_FALLBACK", "1").strip().lower() not in {"0", "false", "no", "off"}


def process_request(source_bytes: bytes, background_bytes: bytes | None, options, byte_limit: int):
    with _INFERENCE_LOCK:
        source = decode_image(
            source_bytes,
            max_bytes=byte_limit,
            max_axis=MAX_AXIS_PX,
            max_pixels=MAX_DECODED_PIXELS,
        )
        decoded_background = None if background_bytes is None else decode_image(
            background_bytes,
            max_bytes=byte_limit,
            max_axis=MAX_AXIS_PX,
            max_pixels=MAX_DECODED_PIXELS,
        )
        model, device = load_model()
        try:
            alpha = infer_alpha(source.convert("RGB"), model, device)
        except Exception as exc:
            if device != "cuda" or not cpu_fallback_enabled() or not is_cuda_out_of_memory(exc):
                raise
            reset_model()
            cpu_environment = dict(os.environ)
            cpu_environment["BIREFNET_USE_GPU"] = "0"
            cpu_environment["BIREFNET_DEVICE"] = "cpu"
            model, device = load_model(environment=cpu_environment)
            alpha = infer_alpha(source.convert("RGB"), model, device)

        alpha = postprocess_mask(
            alpha,
            size=source.size,
            feather_px=options.feather_px,
            edge_offset_px=options.edge_offset_px,
        )
        return render_png(source, alpha, options, decoded_background), source.size, device


@app.post("/remove-background/image")
async def remove_background(
    image: UploadFile | None = File(default=None),
    output: str | None = Form(default=None),
    feather_px: str | None = Form(default=None),
    edge_offset_px: str | None = Form(default=None),
    defringe: str | None = Form(default=None),
    background: str | None = Form(default=None),
    background_color: str | None = Form(default=None),
    background_image: UploadFile | None = File(default=None),
) -> Response:
    started = time.perf_counter()
    if image is None:
        return error_response(400, "file_required", "image is required")

    form = {
        name: value
        for name, value in {
            "output": output,
            "feather_px": feather_px,
            "edge_offset_px": edge_offset_px,
            "defringe": defringe,
            "background": background,
            "background_color": background_color,
        }.items()
        if value is not None
    }
    try:
        options = parse_options(form, background_image is not None)
    except PipelineError as exc:
        return error_response(400, exc.code, exc.message)

    try:
        byte_limit = max_upload_bytes()
        source_bytes = await bounded_upload(image, byte_limit)
        background_bytes = await bounded_upload(background_image, byte_limit) if background_image is not None else None
        response_bytes, source_size, device = await run_in_threadpool(
            process_request,
            source_bytes,
            background_bytes,
            options,
            byte_limit,
        )
    except ModelRuntimeError as exc:
        status = 404 if exc.code == "model_not_present" else 503
        return error_response(status, exc.code, "BiRefNet model is unavailable")
    except PipelineError as exc:
        if exc.code == "payload_too_large":
            return error_response(413, exc.code, exc.message)
        if exc.code == "unsupported_media_type":
            return error_response(415, exc.code, exc.message)
        if exc.code == "invalid_image":
            return error_response(400, exc.code, exc.message)
        return error_response(500, "inference_failed", "BiRefNet inference failed")
    except Exception:
        return error_response(500, "inference_failed", "BiRefNet inference failed")

    elapsed_ms = max(1, int(round((time.perf_counter() - started) * 1000)))
    return Response(
        content=response_bytes,
        media_type="image/png",
        headers={
            "X-3waAIHub-Model": MODEL_HEADER,
            "X-3waAIHub-Device": device,
            "X-3waAIHub-Elapsed-Ms": str(elapsed_ms),
            "X-3waAIHub-Width": str(source_size[0]),
            "X-3waAIHub-Height": str(source_size[1]),
        },
    )
