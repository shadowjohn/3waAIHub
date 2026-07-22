from __future__ import annotations

import importlib.util
import os
import tempfile
import time
from pathlib import Path

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse, Response

from image_pipeline import PipelineError, decode_image, parse_options, postprocess_mask, prediction_to_mask, render_png
from model_runtime import ModelRuntimeError, load_model, model_health, reset_model
from provision_offline_assets import MODEL_REPOSITORY, MODEL_REVISION


app = FastAPI(title="3waAIHub BiRefNet Adapter", version="0.1.0")
MODEL_HEADER = f"{MODEL_REPOSITORY}@{MODEL_REVISION}"


@app.get("/health")
def health() -> dict[str, object]:
    return {
        "ok": True,
        "runtime_level": "L4b-real-inference",
        "runtime_ready": True,
        "dependencies": {
            name: importlib.util.find_spec(name) is not None
            for name in ("torch", "transformers", "PIL", "numpy")
        },
        **model_health(),
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
        service_data = Path(os.getenv("BIREFNET_SERVICE_DATA_DIR", "/data/service"))
        temporary_root = service_data / "tmp"
        temporary_root.mkdir(parents=True, exist_ok=True, mode=0o700)
        temporary_root.chmod(0o700)
        with tempfile.TemporaryDirectory(prefix="request-", dir=temporary_root) as workspace_name:
            workspace = Path(workspace_name)
            workspace.chmod(0o700)
            byte_limit = max_upload_bytes()
            source_bytes = await bounded_upload(image, byte_limit)
            (workspace / "source.upload").write_bytes(source_bytes)
            source = decode_image(source_bytes, max_bytes=byte_limit, max_axis=8192, max_pixels=40_000_000)
            decoded_background = None
            if background_image is not None:
                background_bytes = await bounded_upload(background_image, byte_limit)
                (workspace / "background.upload").write_bytes(background_bytes)
                decoded_background = decode_image(
                    background_bytes,
                    max_bytes=byte_limit,
                    max_axis=8192,
                    max_pixels=40_000_000,
                )

            model, device = load_model()
            try:
                alpha = infer_alpha(source.convert("RGB"), model, device)
            except TimeoutError:
                raise
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
            encoded = render_png(source, alpha, options, decoded_background)
            output_path = workspace / "output.png"
            output_path.write_bytes(encoded)
            response_bytes = output_path.read_bytes()
    except ModelRuntimeError as exc:
        status = 404 if exc.code == "model_not_present" else 503
        return error_response(status, exc.code, "BiRefNet model is unavailable")
    except TimeoutError:
        return error_response(504, "inference_timeout", "BiRefNet inference timed out")
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
            "X-3waAIHub-Width": str(source.width),
            "X-3waAIHub-Height": str(source.height),
        },
    )
