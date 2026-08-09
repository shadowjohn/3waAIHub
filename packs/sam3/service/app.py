from __future__ import annotations

import json
import os
import re
import secrets
import tempfile
import threading
import time
from contextlib import contextmanager
from io import BytesIO
from pathlib import Path
from typing import Any

import numpy as np
from fastapi import FastAPI, File, Form, Header, UploadFile
from fastapi.responses import JSONResponse, Response
from PIL import Image, UnidentifiedImageError

from geometry import polygon_from_mask, polygons_from_mask, rle_from_mask
from model_smoke import CHECKPOINT_NAME, model_status as verified_model_status
from sam31 import Sam31Error, load_predictor, segment_single_image

app = FastAPI(title="3waAIHub SAM3")
_SAM_LOCK = threading.Lock()  # ponytail: one GPU request per service; use job scheduling only if throughput requires it.
_MODEL_CACHE: dict[str, Any] = {}
_MODEL_WORK_LOCK = threading.RLock()
_MODEL_WORK_DEPTH = 0
_ACTIVE_RESIDENT_JOBS: dict[str, threading.Event] = {}
_ACTIVE_RESIDENT_JOBS_LOCK = threading.RLock()
RUN_ID = re.compile(r"^[a-z0-9][a-z0-9_.-]{0,95}$")
TERMINAL_RESIDENT_STATES = {"succeeded", "failed", "cancelled"}
OUTPUT_FORMATS = {"metadata", "polygon", "rle", "both", "png"}
PROMPT_TYPES = {"auto", "points", "boxes", "text", "guidance_mask"}


class Sam3Error(Exception):
    def __init__(self, code: str, message: str, status_code: int = 400) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
        self.status_code = status_code


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


def configure_sam3_env() -> None:
    cache_dir = os.getenv("SAM3_CACHE_DIR", "/cache/sam3")
    env = {
        "HF_HOME": os.getenv("HF_HOME", f"{cache_dir}/huggingface"),
        "TORCH_HOME": os.getenv("TORCH_HOME", f"{cache_dir}/torch"),
        "XDG_CACHE_HOME": os.getenv("XDG_CACHE_HOME", f"{cache_dir}/xdg"),
        "HOME": os.getenv("HOME", f"{cache_dir}/home"),
        "HF_HUB_OFFLINE": "1",
        "TRANSFORMERS_OFFLINE": "1",
        "PYTHONUNBUFFERED": os.getenv("PYTHONUNBUFFERED", "1"),
    }
    os.environ.update(env)
    for path in [
        cache_dir,
        os.getenv("SAM3_SERVICE_DATA_DIR", "/data/service"),
        env["HF_HOME"],
        env["TORCH_HOME"],
        env["XDG_CACHE_HOME"],
        env["HOME"],
    ]:
        Path(path).mkdir(parents=True, exist_ok=True)


def storage_path_status(path: str, require_writable: bool) -> dict[str, Any]:
    target = Path(path)
    exists = target.is_dir()
    readable = exists and os.access(target, os.R_OK)
    writable = False
    error = ""
    if exists and readable and require_writable:
        try:
            with tempfile.NamedTemporaryFile(prefix=".3waaihub-write-", dir=target, delete=False) as handle:
                test_path = Path(handle.name)
            test_path.unlink(missing_ok=True)
            writable = True
        except OSError as exc:
            error = exc.__class__.__name__
    elif exists and readable:
        writable = os.access(target, os.W_OK)
    elif not exists:
        error = "directory missing"
    else:
        error = "directory not readable"

    status: dict[str, Any] = {"exists": exists, "readable": readable, "writable": writable}
    if error:
        status["error"] = error
    return status


def storage_status() -> tuple[dict[str, Any], list[str]]:
    configure_sam3_env()
    storage = {
        "models": storage_path_status(os.getenv("SAM3_MODEL_DIR", "/models/sam3"), False),
        "cache": storage_path_status(os.getenv("SAM3_CACHE_DIR", "/cache/sam3"), True),
        "service_data": storage_path_status(os.getenv("SAM3_SERVICE_DATA_DIR", "/data/service"), True),
    }
    errors = [
        f"{name} {key} failed"
        for name, status in storage.items()
        for key in ("exists", "readable") + (("writable",) if name != "models" else ())
        if not bool(status[key])
    ]
    return storage, errors


def model_status() -> dict[str, Any]:
    verified = verified_model_status()
    return {
        "present": bool(verified.get("present")),
        "checkpoint": CHECKPOINT_NAME,
        "loadable": bool(verified.get("ok")),
        "required_for_real_inference": True,
        "error": verified.get("error", ""),
    }


def runtime_status(model: dict[str, Any] | None = None) -> dict[str, Any]:
    try:
        from sam3.model_builder import build_sam3_multiplex_video_predictor  # noqa: F401

        dependency_available = True
    except Exception:
        dependency_available = False

    model = model if model is not None else model_status()
    runtime = {
        "dependency_available": dependency_available,
        "backend": "sam3",
        "can_load_model": dependency_available and bool(model.get("present")) and bool(model.get("loadable")),
    }
    return runtime


def health() -> dict[str, Any]:
    storage, errors = storage_status()
    model = model_status()
    runtime = runtime_status(model)
    warnings = []
    if not model["present"]:
        warnings.append("model_not_present")
    elif not model.get("loadable"):
        warnings.append("model_load_failed")
    if "error" in model:
        warnings.append(str(model["error"]))
    if not runtime["dependency_available"]:
        warnings.append("runtime_dependency_missing")

    return {
        "ok": True,
        "service": "sam3",
        "ready": not errors and bool(runtime["can_load_model"]),
        "runtime_level": runtime_level(),
        "real_inference": env_enabled(os.getenv("SAM3_REAL_INFERENCE")),
        "storage": storage,
        "gpu": {"checked": False},
        "model": model,
        "runtime": runtime,
        "warnings": warnings,
        "errors": errors,
    }


app.get("/health")(health)


def safe_message(exc: Exception) -> str:
    del exc
    return "SAM3 inference failed."


def error_response(status_code: int, code: str, message: str) -> JSONResponse:
    return JSONResponse(status_code=status_code, content={"ok": False, "error": code, "message": message})


def internal_authorized(token: str | None) -> bool:
    expected = os.getenv("SAM3_INTERNAL_JOB_TOKEN", "")
    return bool(expected and token and secrets.compare_digest(expected, token))


def resident_jobs_root() -> Path:
    root = Path(os.getenv("SAM3_SERVICE_DATA_DIR", "/data/service")) / "resident_jobs"
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
    except (OSError, RuntimeError, ValueError):
        return "unknown"
    return str(payload.get("state")) if isinstance(payload, dict) and set(payload) == {"state"} and payload.get("state") in TERMINAL_RESIDENT_STATES else "unknown"


def write_resident_terminal_state(stage: Path, state: str) -> None:
    if state not in TERMINAL_RESIDENT_STATES:
        raise RuntimeError("resident_job_invalid")
    target = stage / "terminal.json"
    temporary = stage / ".terminal.json.tmp"
    if any(path.exists() and (path.is_symlink() or not path.is_file()) for path in (target, temporary)):
        raise RuntimeError("resident_job_invalid")
    with temporary.open("w", encoding="utf-8") as handle:
        json.dump({"state": state}, handle, separators=(",", ":"))
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    temporary.replace(target)


def current_checkpoint() -> Path:
    model = model_status()
    if not model.get("present"):
        raise Sam3Error("model_not_present", "SAM3 checkpoint is not present.", 503)
    if not model.get("loadable"):
        raise Sam3Error("model_load_failed", "SAM3 checkpoint verification failed.", 503)
    return Path(os.getenv("SAM3_MODEL_DIR", "/models/sam3")) / CHECKPOINT_NAME


def resident_sam3_loader() -> Any:
    configure_sam3_env()
    checkpoint = current_checkpoint()
    key = str(checkpoint)
    predictor = _MODEL_CACHE.get(key)
    if predictor is None:
        predictor = load_predictor(checkpoint, effective_device())
        _MODEL_CACHE[key] = predictor
    return predictor


def effective_device() -> str:
    try:
        import torch

        if torch.cuda.is_available():
            return "cuda"
    except Exception as exc:
        raise Sam3Error("runtime_dependency_missing", "SAM3 runtime dependency is not available.", 503) from exc
    raise Sam3Error("gpu_unavailable", "SAM3 GPU runtime is not available.", 503)


def image_info(data: bytes) -> tuple[int, int]:
    try:
        with Image.open(BytesIO(data)) as image:
            image.verify()
        with Image.open(BytesIO(data)) as image:
            return image.size
    except (UnidentifiedImageError, OSError) as exc:
        raise Sam3Error("bad_image", "Uploaded file is not a readable image.", 400) from exc


def parse_guidance_mask(data: bytes, width: int, height: int) -> np.ndarray:
    if not data:
        raise Sam3Error("invalid_guidance_mask", "guidance_mask PNG is required for prompt_type=guidance_mask.", 400)
    try:
        with Image.open(BytesIO(data)) as image:
            if image.format != "PNG":
                raise Sam3Error("invalid_guidance_mask", "guidance_mask must be a PNG image.", 400)
            if image.size != (width, height):
                raise Sam3Error("guidance_mask_size_mismatch", "guidance_mask must match the input image dimensions.", 400)
            alpha = np.asarray(image.convert("RGBA").getchannel("A"))
    except Sam3Error:
        raise
    except (UnidentifiedImageError, OSError) as exc:
        raise Sam3Error("invalid_guidance_mask", "guidance_mask must be a readable PNG image.", 400) from exc

    # transparent pixels are neutral; every non-transparent pixel is positive guidance.
    bitmap = alpha > 0
    if not bool(bitmap.any()):
        raise Sam3Error("no_positive_guidance", "guidance_mask must contain at least one non-transparent pixel.", 400)
    return bitmap


def guidance_bbox(bitmap: np.ndarray) -> list[int]:
    ys, xs = np.where(bitmap)
    return [int(xs.min()), int(ys.min()), int(xs.max()), int(ys.max())]


def parse_json(value: str, fallback: Any) -> Any:
    if not value.strip():
        return fallback
    try:
        return json.loads(value)
    except json.JSONDecodeError as exc:
        raise Sam3Error("invalid_prompt", "Prompt JSON is invalid.", 400) from exc


def normalize_output_format(value: str) -> str:
    output_format = (value or "metadata").strip().lower()
    if output_format not in OUTPUT_FORMATS:
        raise Sam3Error("invalid_output_format", "output_format must be metadata, polygon, rle, both, or png.", 400)
    return output_format


def parse_points_prompt(value: str) -> tuple[list[list[float]], list[int] | None]:
    payload = parse_json(value, {})
    if isinstance(payload, dict):
        points = payload.get("points")
        labels = payload.get("labels")
    else:
        points = payload
        labels = None

    if not isinstance(points, list) or not points:
        raise Sam3Error("invalid_prompt", 'points prompt requires points_json like {"points":[[320,240]],"labels":[1]}.', 400)
    normalized: list[list[float]] = []
    for point in points:
        if not isinstance(point, list) or len(point) != 2:
            raise Sam3Error("invalid_prompt", "points_json points must be [x,y] pairs.", 400)
        try:
            normalized.append([float(point[0]), float(point[1])])
        except (TypeError, ValueError) as exc:
            raise Sam3Error("invalid_prompt", "points_json points must be numeric.", 400) from exc

    if labels is None:
        labels = [1] * len(normalized)
        return normalized, labels
    if not isinstance(labels, list) or len(labels) != len(normalized):
        raise Sam3Error("invalid_prompt", "points_json labels length must match points.", 400)
    try:
        normalized_labels = [int(label) for label in labels]
    except (TypeError, ValueError) as exc:
        raise Sam3Error("invalid_prompt", "points_json labels must be integers.", 400) from exc
    if any(label not in {0, 1} for label in normalized_labels):
        raise Sam3Error("invalid_prompt", "points_json labels must be 0 or 1; 1 selects target, 0 excludes target.", 400)
    if 1 not in normalized_labels:
        raise Sam3Error("invalid_prompt", "points_json labels must include at least one positive label (1).", 400)
    return normalized, normalized_labels


def parse_text_prompt(value: str) -> list[str]:
    cleaned = (value or "").strip()
    if not cleaned:
        raise Sam3Error("invalid_prompt", "text prompt requires text, e.g. mammal/insect/plant.", 400)
    prompts = [part.strip() for part in cleaned.replace(",", "/").split("/") if part.strip()]
    if not prompts:
        raise Sam3Error("invalid_prompt", "text prompt requires at least one concept.", 400)
    if any(len(prompt) > 80 for prompt in prompts) or len(prompts) > 12:
        raise Sam3Error("invalid_prompt", "text prompt should use short noun phrases.", 400)
    return prompts


def mask_items(results: Any, output_format: str, label_hints: list[str] | None = None) -> list[dict[str, Any]]:
    label_hints = label_hints or []
    items: list[dict[str, Any]] = []
    for result in results or []:
        if not isinstance(result, dict) or "mask" not in result:
            continue
        bitmap = np.asarray(result["mask"]) > 0
        ys, xs = np.where(bitmap)
        if len(xs) == 0 or len(ys) == 0:
            continue
        x1, x2 = int(xs.min()), int(xs.max())
        y1, y2 = int(ys.min()), int(ys.max())
        item: dict[str, Any] = {
            "id": int(result.get("id", len(items) + 1)),
            "score": float(result.get("score", 1.0)),
            "confidence": float(result.get("score", 1.0)),
            "label_name": label_hints[min(len(items), len(label_hints) - 1)] if label_hints else "",
            "bbox": [x1, y1, x2 - x1 + 1, y2 - y1 + 1],
            "area": int(bitmap.sum()),
        }
        if output_format in {"polygon", "both"}:
            try:
                item["polygon"] = polygon_from_mask(bitmap)
                item["polygons"] = polygons_from_mask(bitmap)
            except Exception as exc:
                raise Sam3Error("polygon_extract_failed", safe_message(exc), 502) from exc
        if output_format in {"rle", "both"}:
            try:
                item["rle"] = rle_from_mask(bitmap)
            except Exception as exc:
                raise Sam3Error("rle_encode_failed", safe_message(exc), 502) from exc
        items.append(item)
    return items


def mask_png(bitmap: np.ndarray, width: int, height: int) -> bytes:
    image = Image.fromarray((bitmap.astype("uint8") * 255), mode="L")
    if image.size != (width, height):
        image = image.resize((width, height), Image.Resampling.NEAREST)
    alpha = image
    rgba = Image.new("RGBA", (width, height), (255, 255, 255, 0))
    rgba.putalpha(alpha)
    buffer = BytesIO()
    rgba.save(buffer, format="PNG")
    return buffer.getvalue()


def merged_mask_png(results: Any, width: int, height: int) -> bytes:
    merged: np.ndarray | None = None
    for result in results or []:
        if not isinstance(result, dict) or "mask" not in result:
            continue
        bitmap = np.asarray(result["mask"]) > 0
        merged = bitmap if merged is None else np.logical_or(merged, bitmap)
    if merged is None:
        merged = np.zeros((height, width), dtype=bool)
    return mask_png(merged, width, height)


def parse_boxes_prompt(value: str) -> list[list[float]]:
    payload = parse_json(value, [])
    if isinstance(payload, list) and len(payload) == 4 and not any(isinstance(item, list) for item in payload):
        payload = [payload]
    if not isinstance(payload, list) or not payload:
        raise Sam3Error("invalid_prompt", "boxes prompt requires boxes_json.", 400)
    boxes: list[list[float]] = []
    for box in payload:
        if not isinstance(box, list) or len(box) != 4:
            raise Sam3Error("invalid_prompt", "boxes_json boxes must be [x1,y1,x2,y2].", 400)
        try:
            boxes.append([float(value) for value in box])
        except (TypeError, ValueError) as exc:
            raise Sam3Error("invalid_prompt", "boxes_json coordinates must be numeric.", 400) from exc
    return boxes


def decoded_image(data: bytes) -> Image.Image:
    try:
        with Image.open(BytesIO(data)) as image:
            image.verify()
        with Image.open(BytesIO(data)) as image:
            return image.convert("RGB")
    except (UnidentifiedImageError, OSError) as exc:
        raise Sam3Error("bad_image", "Uploaded file is not a readable image.", 400) from exc


def run_sam3(data: bytes, width: int, height: int, prompt_type: str, points_json: str, boxes_json: str, text_prompt: str, output_format: str, guidance_bitmap: np.ndarray | None = None) -> dict[str, Any]:
    if prompt_type not in PROMPT_TYPES:
        raise Sam3Error("invalid_prompt", "prompt_type must be auto, points, boxes, text, or guidance_mask.", 400)

    checkpoint = current_checkpoint()
    points: list[list[float]] | None = None
    labels: list[int] | None = None
    boxes: list[list[float]] | None = None
    text_prompts: list[str] = []
    if prompt_type == "points":
        points, labels = parse_points_prompt(points_json)
    elif prompt_type == "boxes":
        boxes = parse_boxes_prompt(boxes_json)
    elif prompt_type == "text":
        text_prompts = parse_text_prompt(text_prompt)
    elif prompt_type == "guidance_mask":
        if guidance_bitmap is None:
            raise Sam3Error("invalid_guidance_mask", "guidance_mask PNG is required for prompt_type=guidance_mask.", 400)

    started = time.perf_counter()
    try:
        configure_sam3_env()
        with _SAM_LOCK:
            with model_work():
                results = segment_single_image(
                    resident_sam3_loader(),
                    decoded_image(data),
                    prompt_type=prompt_type,
                    points=points,
                    labels=labels,
                    boxes=boxes,
                    text_prompts=text_prompts or None,
                    guidance_bitmap=guidance_bitmap,
                    workspace=Path(os.getenv("SAM3_SERVICE_DATA_DIR", "/data/service")),
                )
    except TimeoutError as exc:
        raise Sam3Error("inference_timeout", "SAM3 inference timed out.", 504) from exc
    except Sam31Error as exc:
        if exc.code == "invalid_prompt":
            raise Sam3Error("invalid_prompt", "SAM3 prompt is invalid.", 400) from exc
        raise Sam3Error("inference_failed", "SAM3 inference failed.", 502) from exc
    except Sam3Error:
        raise
    except Exception as exc:
        raise Sam3Error("inference_failed", safe_message(exc), 502) from exc
    if output_format == "png":
        return {
            "ok": True,
            "mock": False,
            "runtime_level": runtime_level(),
            "output_format": output_format,
            "png": merged_mask_png(results, width, height),
            "elapsed_ms": int(round((time.perf_counter() - started) * 1000)),
        }
    return {
        "ok": True,
        "mock": False,
        "runtime_level": runtime_level(),
        "model": {"checkpoint": CHECKPOINT_NAME},
        "prompt_type": prompt_type,
        "text_prompt": text_prompt if prompt_type == "text" else "",
        "text_prompts": text_prompts,
        "output_format": output_format,
        "image": {"width": width, "height": height},
        "masks": mask_items(results, output_format, text_prompts if prompt_type == "text" else []),
        "elapsed_ms": int(round((time.perf_counter() - started) * 1000)),
    }


@app.post("/segment/image")
async def segment_image(
    image: UploadFile = File(...),
    guidance_mask: UploadFile | None = File(None),
    prompt_type: str = Form("auto"),
    points_json: str = Form(""),
    boxes_json: str = Form(""),
    text: str = Form(""),
    text_prompt: str = Form(""),
    output_format: str = Form("metadata"),
    real_inference: str = Form("0"),
) -> JSONResponse:
    data = await image.read()
    if not data:
        return error_response(400, "bad_image", "image is required")
    try:
        normalized_output_format = normalize_output_format(output_format)
    except Sam3Error as exc:
        return error_response(exc.status_code, exc.code, exc.message)

    if env_enabled(real_inference) or env_enabled(os.getenv("SAM3_REAL_INFERENCE")):
        try:
            width, height = image_info(data)
            guidance_bitmap = None
            if (prompt_type or "auto") == "guidance_mask":
                guidance_bitmap = parse_guidance_mask(await guidance_mask.read() if guidance_mask is not None else b"", width, height)
            semantic_text = text_prompt or text
            payload = run_sam3(data, width, height, prompt_type or "auto", points_json, boxes_json, semantic_text, normalized_output_format, guidance_bitmap)
            if isinstance(payload.get("png"), bytes):
                return Response(content=payload["png"], media_type="image/png", headers={"X-SAM3-Output-Format": "png"})
            return JSONResponse(content=payload)
        except Sam3Error as exc:
            return error_response(exc.status_code, exc.code, exc.message)

    mock_text_prompts: list[str] = []
    mock_points: list[list[float]] = []
    mock_guidance: np.ndarray | None = None
    normalized_prompt_type = prompt_type or "auto"
    if normalized_prompt_type not in PROMPT_TYPES:
        return error_response(400, "invalid_prompt", "prompt_type must be auto, points, boxes, text, or guidance_mask.")
    if normalized_prompt_type == "points":
        try:
            mock_points, _ = parse_points_prompt(points_json)
        except Sam3Error as exc:
            return error_response(exc.status_code, exc.code, exc.message)
    if normalized_prompt_type == "guidance_mask":
        try:
            width, height = image_info(data)
            mock_guidance = parse_guidance_mask(await guidance_mask.read() if guidance_mask is not None else b"", width, height)
        except Sam3Error as exc:
            return error_response(exc.status_code, exc.code, exc.message)
    if normalized_prompt_type == "text":
        try:
            mock_text_prompts = parse_text_prompt(text_prompt or text)
        except Sam3Error as exc:
            return error_response(exc.status_code, exc.code, exc.message)
    if normalized_output_format == "png":
        try:
            width, height = image_info(data)
        except Sam3Error as exc:
            return error_response(exc.status_code, exc.code, exc.message)
        if mock_guidance is not None:
            return Response(content=mask_png(mock_guidance, width, height), media_type="image/png", headers={"X-SAM3-Output-Format": "png"})
        return Response(content=merged_mask_png([], width, height), media_type="image/png", headers={"X-SAM3-Output-Format": "png"})

    return JSONResponse(content={
        "ok": True,
        "mock": True,
        "runtime_level": runtime_level(),
        "prompt_type": normalized_prompt_type,
        "text_prompt": text_prompt or text if normalized_prompt_type == "text" else "",
        "text_prompts": mock_text_prompts,
        "output_format": normalized_output_format,
        "masks": [],
        "points": mock_points,
        "guidance_bbox": guidance_bbox(mock_guidance) if mock_guidance is not None else [],
        "boxes": [],
        "elapsed_ms": 0,
        "message": "SAM3 mock segmentation",
    })


def internal_error(status: int, code: str) -> JSONResponse:
    return JSONResponse(status_code=status, content={"ok": False, "error": code})


@app.get("/internal/capacity", response_model=None)
def internal_capacity(x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, Any] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return internal_error(403, "internal_auth_failed")
    with _ACTIVE_RESIDENT_JOBS_LOCK, _MODEL_WORK_LOCK:
        state = "running" if _ACTIVE_RESIDENT_JOBS or _MODEL_WORK_DEPTH else "ready" if _MODEL_CACHE else "cold"
        return {"model_state": state, "active_runs": len(_ACTIVE_RESIDENT_JOBS)}


@app.get("/internal/jobs/{run_id}", response_model=None)
def internal_job_status(run_id: str, x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, str] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return internal_error(403, "internal_auth_failed")
    try:
        stage = resident_stage(run_id)
    except RuntimeError:
        return internal_error(404, "resident_job_not_found")
    with _ACTIVE_RESIDENT_JOBS_LOCK:
        state = "running" if run_id in _ACTIVE_RESIDENT_JOBS else resident_terminal_state(stage)
    return {"run_id": run_id, "state": state}


@app.post("/internal/jobs/{run_id}/cancel", response_model=None)
def internal_job_cancel(run_id: str, x_aihub_internal_token: str | None = Header(default=None, alias="X-AIHub-Internal-Token")) -> dict[str, str] | JSONResponse:
    if not internal_authorized(x_aihub_internal_token):
        return internal_error(403, "internal_auth_failed")
    with _ACTIVE_RESIDENT_JOBS_LOCK:
        cancelled = _ACTIVE_RESIDENT_JOBS.get(run_id)
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
        resident_regular(stage, "input", "request.json")
        resident_regular(stage, "input", "source")
    except RuntimeError:
        return internal_error(400, "resident_job_invalid")
    terminal = resident_terminal_state(stage)
    if terminal != "unknown":
        return {"run_id": run_id, "state": terminal}
    cancelled = threading.Event()
    with _ACTIVE_RESIDENT_JOBS_LOCK:
        if _ACTIVE_RESIDENT_JOBS:
            return internal_error(409, "resident_job_active")
        _ACTIVE_RESIDENT_JOBS[run_id] = cancelled
    state = "failed"
    try:
        import jobs

        with _SAM_LOCK:
            with model_work():
                jobs.run_job("monitor", stage, stage / "input", stage / "output", predictor_loader=resident_sam3_loader)
        state = "cancelled" if cancelled.is_set() else "succeeded"
    except Exception:
        state = "failed"
    try:
        write_resident_terminal_state(stage, state)
    except Exception:
        return internal_error(500, "resident_job_state_failed")
    finally:
        with _ACTIVE_RESIDENT_JOBS_LOCK:
            _ACTIVE_RESIDENT_JOBS.pop(run_id, None)
    return {"run_id": run_id, "state": state}
