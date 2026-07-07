from __future__ import annotations

import json
import os
import tempfile
import time
from io import BytesIO
from pathlib import Path
from typing import Any

import numpy as np
from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse
from PIL import Image, UnidentifiedImageError

app = FastAPI(title="3waAIHub SAM3")
MODEL_EXTENSIONS = {".pt", ".pth", ".safetensors", ".ckpt"}
MIN_CHECKPOINT_BYTES = 1024 * 1024  # ponytail: catches fake smoke files; real validation happens at SAM load.
_SAM_MODEL: Any | None = None
_SAM_CHECKPOINT = ""


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


def configure_sam3_env() -> None:
    model_dir = os.getenv("SAM3_MODEL_DIR", "/models/sam3")
    cache_dir = os.getenv("SAM3_CACHE_DIR", "/cache/sam3")
    env = {
        "HF_HOME": os.getenv("HF_HOME", f"{model_dir}/huggingface"),
        "TORCH_HOME": os.getenv("TORCH_HOME", f"{model_dir}/torch"),
        "XDG_CACHE_HOME": os.getenv("XDG_CACHE_HOME", f"{cache_dir}/xdg"),
        "HOME": os.getenv("HOME", f"{cache_dir}/home"),
        "YOLO_CONFIG_DIR": os.getenv("YOLO_CONFIG_DIR", f"{cache_dir}/ultralytics"),
        "ULTRALYTICS_SETTINGS_DIR": os.getenv("ULTRALYTICS_SETTINGS_DIR", f"{cache_dir}/ultralytics"),
        "PYTHONUNBUFFERED": os.getenv("PYTHONUNBUFFERED", "1"),
    }
    os.environ.update(env)
    for path in [
        model_dir,
        cache_dir,
        os.getenv("SAM3_SERVICE_DATA_DIR", "/data/service"),
        env["HF_HOME"],
        env["TORCH_HOME"],
        env["XDG_CACHE_HOME"],
        env["HOME"],
        env["ULTRALYTICS_SETTINGS_DIR"],
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
    configure_sam3_env()
    storage = {
        "models": storage_path_status(os.getenv("SAM3_MODEL_DIR", "/models/sam3")),
        "cache": storage_path_status(os.getenv("SAM3_CACHE_DIR", "/cache/sam3")),
        "service_data": storage_path_status(os.getenv("SAM3_SERVICE_DATA_DIR", "/data/service")),
    }
    errors = [
        f"{name} {key} failed: {status['path']}"
        for name, status in storage.items()
        for key in ("exists", "readable", "writable")
        if not status[key]
    ]
    return storage, errors


def is_path_under(path: Path, root: Path) -> bool:
    try:
        path.resolve(strict=False).relative_to(root.resolve(strict=False))
        return True
    except ValueError:
        return False


def safe_checkpoint_path(raw: str, model_root: Path) -> tuple[Path | None, str]:
    value = raw.strip()
    if value == "":
        return None, "scan"
    if "\x00" in value:
        return None, "invalid_checkpoint_path"

    candidate = Path(value)
    if not candidate.is_absolute():
        candidate = model_root / candidate
    if not is_path_under(candidate, model_root):
        return None, "invalid_checkpoint_path"

    return candidate, "SAM3_CHECKPOINT"


def is_safe_model_file(path: Path, model_root: Path) -> bool:
    if path.suffix.lower() not in MODEL_EXTENSIONS or path.is_dir():
        return False
    try:
        resolved = path.resolve(strict=True)
        resolved.relative_to(model_root.resolve(strict=True))
    except (OSError, ValueError):
        return False

    return path.is_file()


def checkpoint_loadable(path: Path) -> bool:
    try:
        return path.stat().st_size >= MIN_CHECKPOINT_BYTES
    except OSError:
        return False


def model_candidates(model_root: Path) -> list[Path]:
    candidates: list[Path] = []
    if not model_root.is_dir():
        return candidates

    for current_root, dirs, files in os.walk(model_root):
        root_path = Path(current_root)
        dirs[:] = [name for name in dirs if not (root_path / name).is_symlink()]
        for filename in files:
            candidate = root_path / filename
            if is_safe_model_file(candidate, model_root):
                candidates.append(candidate)

    return sorted(candidates, key=lambda path: (not checkpoint_loadable(path), str(path)))


def checkpoint_payload(path: Path, source: str, candidates_count: int) -> dict[str, Any]:
    size = path.stat().st_size
    return {
        "present": True,
        "checkpoint": str(path.resolve(strict=True)),
        "source": source,
        "size_bytes": size,
        "loadable": size >= MIN_CHECKPOINT_BYTES,
        "required_for_real_inference": True,
        "candidates_count": candidates_count,
    }


def model_status() -> dict[str, Any]:
    model_root = Path(os.getenv("SAM3_MODEL_DIR", "/models/sam3"))
    raw_checkpoint = os.getenv("SAM3_CHECKPOINT", "")
    candidates = model_candidates(model_root)
    checkpoint, source = safe_checkpoint_path(raw_checkpoint, model_root)

    error = ""
    if source == "invalid_checkpoint_path":
        error = "invalid_checkpoint_path"
        checkpoint = None
    elif checkpoint is not None and is_safe_model_file(checkpoint, model_root):
        return checkpoint_payload(checkpoint, source, len(candidates))
    elif checkpoint is None and candidates:
        return checkpoint_payload(candidates[0], "scan", len(candidates))

    status: dict[str, Any] = {
        "present": False,
        "checkpoint": str(checkpoint) if checkpoint is not None else "",
        "source": source,
        "size_bytes": 0,
        "loadable": False,
        "required_for_real_inference": True,
        "candidates_count": len(candidates),
    }
    if error:
        status["error"] = error

    return status


def runtime_status(model: dict[str, Any] | None = None) -> dict[str, Any]:
    try:
        from ultralytics import SAM  # noqa: F401

        dependency_available = True
        error = ""
    except Exception as exc:
        dependency_available = False
        error = safe_message(exc)

    model = model if model is not None else model_status()
    runtime = {
        "dependency_available": dependency_available,
        "backend": "sam3",
        "can_load_model": dependency_available and bool(model.get("present")) and bool(model.get("loadable")),
    }
    if error:
        runtime["error"] = error
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
    message = str(exc) or exc.__class__.__name__
    for value in [
        os.getenv("SAM3_MODEL_DIR", ""),
        os.getenv("SAM3_CACHE_DIR", ""),
        os.getenv("SAM3_SERVICE_DATA_DIR", ""),
    ]:
        if value:
            message = message.replace(value, Path(value).as_posix())
    return message.splitlines()[0][:300]


def error_response(status_code: int, code: str, message: str) -> JSONResponse:
    return JSONResponse(status_code=status_code, content={"ok": False, "error": code, "message": message})


def current_checkpoint() -> Path:
    model = model_status()
    if not model.get("present"):
        raise Sam3Error("model_not_present", "SAM3 checkpoint is not present.", 503)
    if not model.get("loadable"):
        raise Sam3Error("model_load_failed", "SAM3 checkpoint is present but checkpoint is too small to load.", 503)
    return Path(str(model["checkpoint"]))


def effective_device() -> str:
    requested = os.getenv("SAM3_DEVICE", "auto").lower()
    if requested == "cpu":
        return "cpu"
    try:
        import torch

        if torch.cuda.is_available():
            return "0"
    except Exception as exc:
        raise Sam3Error("runtime_dependency_missing", safe_message(exc), 503) from exc
    if requested == "cuda":
        raise Sam3Error("gpu_unavailable", "CUDA GPU is not available for SAM3.", 503)
    raise Sam3Error("gpu_unavailable", "SAM3 GPU runtime is not available.", 503)


def sam_model(checkpoint: Path) -> Any:
    global _SAM_MODEL, _SAM_CHECKPOINT
    configure_sam3_env()
    checkpoint_key = str(checkpoint)
    if _SAM_MODEL is not None and _SAM_CHECKPOINT == checkpoint_key:
        return _SAM_MODEL
    try:
        from ultralytics import SAM
    except Exception as exc:
        raise Sam3Error("runtime_dependency_missing", "Ultralytics SAM dependency is not available.", 503) from exc
    try:
        _SAM_MODEL = SAM(checkpoint_key)
        _SAM_CHECKPOINT = checkpoint_key
        return _SAM_MODEL
    except Exception as exc:
        raise Sam3Error("model_load_failed", safe_message(exc), 503) from exc


def image_info(data: bytes) -> tuple[int, int]:
    try:
        with Image.open(BytesIO(data)) as image:
            image.verify()
        with Image.open(BytesIO(data)) as image:
            return image.size
    except (UnidentifiedImageError, OSError) as exc:
        raise Sam3Error("bad_image", "Uploaded file is not a readable image.", 400) from exc


def parse_json(value: str, fallback: Any) -> Any:
    if not value.strip():
        return fallback
    try:
        return json.loads(value)
    except json.JSONDecodeError as exc:
        raise Sam3Error("invalid_prompt", "Prompt JSON is invalid.", 400) from exc


def to_numpy(value: Any) -> np.ndarray:
    if hasattr(value, "detach"):
        value = value.detach()
    if hasattr(value, "cpu"):
        value = value.cpu()
    if hasattr(value, "numpy"):
        return value.numpy()
    return np.asarray(value)


def score_at(result: Any, index: int) -> float:
    boxes = getattr(result, "boxes", None)
    conf = getattr(boxes, "conf", None)
    if conf is None:
        return 0.0
    try:
        value = conf[index]
        return float(value.item() if hasattr(value, "item") else value)
    except Exception:
        return 0.0


def mask_items(results: Any) -> list[dict[str, Any]]:
    items: list[dict[str, Any]] = []
    for result in results or []:
        masks = getattr(result, "masks", None)
        data = getattr(masks, "data", None)
        if data is None:
            continue
        array = to_numpy(data)
        if array.ndim == 2:
            array = array[None, :, :]
        for index, mask in enumerate(array):
            bitmap = np.asarray(mask) > 0.5
            ys, xs = np.where(bitmap)
            if len(xs) == 0 or len(ys) == 0:
                continue
            x1, x2 = int(xs.min()), int(xs.max())
            y1, y2 = int(ys.min()), int(ys.max())
            items.append({
                "id": len(items) + 1,
                "score": score_at(result, index),
                "bbox": [x1, y1, x2 - x1 + 1, y2 - y1 + 1],
                "area": int(bitmap.sum()),
            })
    return items


def run_sam3(image_path: Path, width: int, height: int, prompt_type: str, points_json: str, boxes_json: str) -> dict[str, Any]:
    if prompt_type not in {"auto", "points", "boxes"}:
        raise Sam3Error("invalid_prompt", "prompt_type must be auto, points, or boxes.", 400)

    checkpoint = current_checkpoint()
    model = sam_model(checkpoint)
    kwargs: dict[str, Any] = {"source": str(image_path), "device": effective_device(), "verbose": False}
    if prompt_type == "points":
        points = parse_json(points_json, [])
        if not points:
            raise Sam3Error("invalid_prompt", "points prompt requires points_json.", 400)
        kwargs["points"] = points
    elif prompt_type == "boxes":
        boxes = parse_json(boxes_json, [])
        if not boxes:
            raise Sam3Error("invalid_prompt", "boxes prompt requires boxes_json.", 400)
        kwargs["bboxes"] = boxes

    started = time.perf_counter()
    try:
        results = model.predict(**kwargs)
    except TimeoutError as exc:
        raise Sam3Error("inference_timeout", "SAM3 inference timed out.", 504) from exc
    except Sam3Error:
        raise
    except Exception as exc:
        message = safe_message(exc)
        if "cuda" in message.lower() or "gpu" in message.lower():
            raise Sam3Error("gpu_unavailable", message, 503) from exc
        raise Sam3Error("inference_failed", message, 502) from exc

    return {
        "ok": True,
        "mock": False,
        "runtime_level": runtime_level(),
        "model": {"checkpoint": str(checkpoint)},
        "prompt_type": prompt_type,
        "image": {"width": width, "height": height},
        "masks": mask_items(results),
        "elapsed_ms": int(round((time.perf_counter() - started) * 1000)),
    }


@app.post("/segment/image")
async def segment_image(
    image: UploadFile = File(...),
    prompt_type: str = Form("auto"),
    points_json: str = Form(""),
    boxes_json: str = Form(""),
    real_inference: str = Form("0"),
) -> JSONResponse:
    data = await image.read()
    if not data:
        return error_response(400, "bad_image", "image is required")

    if env_enabled(real_inference) or env_enabled(os.getenv("SAM3_REAL_INFERENCE")):
        image_path: Path | None = None
        try:
            width, height = image_info(data)
            suffix = Path(image.filename or "upload.png").suffix or ".png"
            with tempfile.NamedTemporaryFile(prefix="sam3-", suffix=suffix, delete=False) as handle:
                handle.write(data)
                image_path = Path(handle.name)
            return JSONResponse(content=run_sam3(image_path, width, height, prompt_type or "auto", points_json, boxes_json))
        except Sam3Error as exc:
            return error_response(exc.status_code, exc.code, exc.message)
        finally:
            if image_path is not None:
                image_path.unlink(missing_ok=True)

    return JSONResponse(content={
        "ok": True,
        "mock": True,
        "runtime_level": runtime_level(),
        "prompt_type": prompt_type or "auto",
        "masks": [],
        "boxes": [],
        "elapsed_ms": 0,
        "message": "SAM3 mock segmentation",
    })
