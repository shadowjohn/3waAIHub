from __future__ import annotations

import os
import tempfile
import time
from pathlib import Path
from typing import Any

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import JSONResponse

app = FastAPI(title="3waAIHub YOLO")
_YOLO_MODEL: Any | None = None
_YOLO_MODEL_NAME = ""


def runtime_level() -> str:
    return "L5-benchmark-ready"


def env_enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


def configure_yolo_env() -> None:
    model_dir = os.getenv("YOLO_MODEL_DIR", "/models/yolo")
    cache_dir = os.getenv("YOLO_CACHE_DIR", "/cache/yolo")
    env = {
        "XDG_CACHE_HOME": os.getenv("XDG_CACHE_HOME", f"{cache_dir}/xdg"),
        "HOME": os.getenv("HOME", f"{cache_dir}/home"),
        "ULTRALYTICS_SETTINGS_DIR": os.getenv("ULTRALYTICS_SETTINGS_DIR", f"{cache_dir}/ultralytics"),
        "YOLO_CONFIG_DIR": os.getenv("YOLO_CONFIG_DIR", f"{cache_dir}/ultralytics"),
    }
    os.environ.update(env)

    for path in [
        model_dir,
        cache_dir,
        os.getenv("YOLO_SERVICE_DATA_DIR", "/data/service"),
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
    configure_yolo_env()
    storage = {
        "models": storage_path_status(os.getenv("YOLO_MODEL_DIR", "/models/yolo")),
        "cache": storage_path_status(os.getenv("YOLO_CACHE_DIR", "/cache/yolo")),
        "service_data": storage_path_status(os.getenv("YOLO_SERVICE_DATA_DIR", "/data/service")),
    }
    errors = []
    for name, status in storage.items():
        for key in ("exists", "readable", "writable"):
            if not status[key]:
                errors.append(f"{name} {key} failed: {status['path']}")
    return storage, errors


@app.get("/health")
def health() -> dict[str, Any]:
    storage, errors = storage_status()
    return {
        "ok": True,
        "service": "yolo",
        "ready": not errors,
        "runtime_level": runtime_level(),
        "storage": storage,
        "errors": errors,
    }


def model_arg(model: str) -> tuple[str, Path]:
    model_dir = Path(os.getenv("YOLO_MODEL_DIR", "/models/yolo"))
    model_path = Path(model)
    if model_path.is_absolute():
        return str(model_path), model_path.parent
    return model, model_dir


def yolo_model() -> Any:
    global _YOLO_MODEL, _YOLO_MODEL_NAME
    configure_yolo_env()
    model_name = os.getenv("YOLO_MODEL", "yolo11n.pt")
    if _YOLO_MODEL is not None and _YOLO_MODEL_NAME == model_name:
        return _YOLO_MODEL

    from ultralytics import YOLO

    arg, cwd = model_arg(model_name)
    old_cwd = Path.cwd()
    try:
        os.chdir(cwd)
        _YOLO_MODEL = YOLO(arg)
        _YOLO_MODEL_NAME = model_name
        return _YOLO_MODEL
    finally:
        os.chdir(old_cwd)


def parse_float(value: str | None, fallback: str, name: str) -> float:
    raw = fallback if value in (None, "") else str(value)
    try:
        parsed = float(raw)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=f"{name} must be a number") from exc
    if parsed < 0 or parsed > 1:
        raise HTTPException(status_code=400, detail=f"{name} must be between 0 and 1")
    return parsed


def detection_items(result: Any) -> list[dict[str, Any]]:
    names = getattr(result, "names", {}) or getattr(yolo_model(), "names", {})
    boxes = getattr(result, "boxes", None)
    if boxes is None:
        return []

    detections: list[dict[str, Any]] = []
    for box in boxes:
        class_id = int(box.cls.item()) if hasattr(box.cls, "item") else int(box.cls)
        confidence = float(box.conf.item()) if hasattr(box.conf, "item") else float(box.conf)
        xyxy = box.xyxy[0].tolist() if hasattr(box.xyxy[0], "tolist") else list(box.xyxy[0])
        detections.append({
            "class_id": class_id,
            "label": str(names.get(class_id, class_id)) if isinstance(names, dict) else str(class_id),
            "confidence": confidence,
            "bbox": [float(v) for v in xyxy],
        })
    return detections


def run_yolo(image_path: Path, conf: float | None = None, iou: float | None = None) -> dict[str, Any]:
    started = time.perf_counter()
    model = yolo_model()
    device = "0" if env_enabled(os.getenv("YOLO_USE_GPU")) else "cpu"
    results = model.predict(
        source=str(image_path),
        conf=conf if conf is not None else float(os.getenv("YOLO_CONF", "0.25")),
        iou=iou if iou is not None else float(os.getenv("YOLO_IOU", "0.7")),
        device=device,
        verbose=False,
    )
    detections: list[dict[str, Any]] = []
    for result in results:
        detections.extend(detection_items(result))

    return {
        "ok": True,
        "mock": False,
        "runtime_level": runtime_level(),
        "model": os.getenv("YOLO_MODEL", "yolo11n.pt"),
        "detections": detections,
        "elapsed_ms": int(round((time.perf_counter() - started) * 1000)),
    }


def error_message(exc: Exception) -> str:
    message = str(exc) or exc.__class__.__name__
    for value in [os.getenv("YOLO_MODEL_DIR", ""), os.getenv("YOLO_CACHE_DIR", ""), os.getenv("YOLO_SERVICE_DATA_DIR", "")]:
        if value:
            message = message.replace(value, Path(value).as_posix())
    return message.splitlines()[0][:300]


@app.post("/detect/image", response_model=None)
async def detect_image(
    image: UploadFile = File(...),
    real_inference: str = Form("0"),
    conf: str | None = Form(None),
    iou: str | None = Form(None),
) -> dict[str, Any] | JSONResponse:
    data = await image.read()
    if not data:
        raise HTTPException(status_code=400, detail="image is required")

    if env_enabled(os.getenv("YOLO_REAL_INFERENCE")) or env_enabled(real_inference):
        suffix = Path(image.filename or "upload.jpg").suffix or ".jpg"
        try:
            with tempfile.NamedTemporaryFile(prefix="yolo-", suffix=suffix, delete=False) as handle:
                handle.write(data)
                image_path = Path(handle.name)
            result = run_yolo(
                image_path,
                parse_float(conf, os.getenv("YOLO_CONF", "0.25"), "conf"),
                parse_float(iou, os.getenv("YOLO_IOU", "0.7"), "iou"),
            )
        except HTTPException:
            raise
        except Exception as exc:
            return JSONResponse(
                status_code=500,
                content={"ok": False, "error": "inference_failed", "message": error_message(exc)},
            )
        finally:
            if "image_path" in locals():
                image_path.unlink(missing_ok=True)
        result["filename"] = image.filename
        result["bytes"] = len(data)
        return result

    return {
        "ok": True,
        "mock": True,
        "runtime_level": runtime_level(),
        "detections": [],
        "filename": image.filename,
        "bytes": len(data),
    }
