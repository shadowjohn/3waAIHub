from __future__ import annotations

import hashlib
import os
import tempfile
import time
from pathlib import Path
from typing import Any

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.responses import JSONResponse

app = FastAPI(title="3waAIHub YOLO Serving")
_MODEL: Any | None = None
_MODEL_PATH = ""


def env_enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


def registry_root() -> Path:
    return Path(os.getenv("YOLO_MODEL_REGISTRY_DIR", "/models/registry")).resolve()


def configure_env() -> None:
    cache = os.getenv("YOLO_CACHE_DIR", "/cache/yolo-serving")
    os.environ.setdefault("XDG_CACHE_HOME", f"{cache}/xdg")
    os.environ.setdefault("HOME", f"{cache}/home")
    os.environ.setdefault("ULTRALYTICS_SETTINGS_DIR", f"{cache}/ultralytics")
    os.environ.setdefault("YOLO_CONFIG_DIR", f"{cache}/ultralytics")
    for path in [registry_root(), Path(cache), Path(os.environ["XDG_CACHE_HOME"]), Path(os.environ["HOME"]), Path(os.environ["ULTRALYTICS_SETTINGS_DIR"])]:
        path.mkdir(parents=True, exist_ok=True)


def safe_model_path(model_path: str) -> Path:
    root = registry_root()
    path = Path(model_path)
    if not path.is_absolute():
        raise HTTPException(status_code=400, detail="model_path must be a registry container path")
    resolved = path.resolve()
    if resolved != root and root not in resolved.parents:
        raise HTTPException(status_code=400, detail="model_path must stay under registry")
    if resolved.suffix.lower() != ".pt" or not resolved.is_file():
        raise HTTPException(status_code=404, detail="model_artifact_missing")
    return resolved


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def yolo_model(path: Path) -> tuple[Any, bool]:
    global _MODEL, _MODEL_PATH
    configure_env()
    key = str(path)
    if _MODEL is not None and _MODEL_PATH == key:
        return _MODEL, False

    from ultralytics import YOLO

    _MODEL = YOLO(key)
    _MODEL_PATH = key
    return _MODEL, True


def parse_float(value: str | None, fallback: float, name: str) -> float:
    raw = str(fallback) if value in (None, "") else str(value)
    try:
        parsed = float(raw)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=f"{name} must be a number") from exc
    if parsed < 0 or parsed > 1:
        raise HTTPException(status_code=400, detail=f"{name} must be between 0 and 1")
    return parsed


def detection_items(result: Any, model: Any) -> list[dict[str, Any]]:
    names = getattr(result, "names", {}) or getattr(model, "names", {})
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


@app.get("/health")
def health() -> dict[str, Any]:
    configure_env()
    root = registry_root()
    return {
        "ok": True,
        "service": "yolo-serving",
        "ready": root.is_dir() and os.access(root, os.R_OK),
        "runtime_level": "L3-storage-mount",
        "device": os.getenv("YOLO_SERVING_DEVICE", "cpu"),
        "registry": {"path": str(root), "readable": os.access(root, os.R_OK)},
    }


@app.post("/detect/image", response_model=None)
async def detect_image(
    image: UploadFile = File(...),
    model_ref: str = Form(...),
    model_path: str = Form(...),
    model_version_id: str = Form("0"),
    sha256: str = Form(""),
    execution_policy: str = Form("auto"),
    conf: str | None = Form(None),
    iou: str | None = Form(None),
) -> dict[str, Any] | JSONResponse:
    started = time.perf_counter()
    data = await image.read()
    if not data:
        raise HTTPException(status_code=400, detail="image is required")
    if execution_policy == "gpu_only":
        return JSONResponse(status_code=409, content={"ok": False, "error": "gpu_not_ready", "message": "GPU warm pool is not implemented in Phase 1A"})

    path = safe_model_path(model_path)
    if sha256 and sha256_file(path) != sha256.lower():
        return JSONResponse(status_code=409, content={"ok": False, "error": "model_checksum_mismatch", "message": "registered model checksum mismatch"})

    if not env_enabled(os.getenv("YOLO_SERVING_REAL_INFERENCE", "1")):
        version_id = int(model_version_id or 0)
        return {
            "ok": True,
            "mock": True,
            "model_ref": model_ref,
            "version_id": version_id,
            "model_version_id": version_id,
            "device_used": "cpu",
            "fallback_reason": None,
            "model": {"model_ref": model_ref, "model_version_id": version_id, "task_type": "detect"},
            "runtime": {"service_key": "yolo-cpu", "device_requested": execution_policy, "device_used": "cpu", "gpu_slot": None, "warm_state": "cold", "fallback": False, "fallback_reason": None, "cold_load": False},
            "timing": {"total_ms": int(round((time.perf_counter() - started) * 1000))},
            "detections": [],
        }

    suffix = Path(image.filename or "upload.jpg").suffix or ".jpg"
    try:
        with tempfile.NamedTemporaryFile(prefix="yolo-serving-", suffix=suffix, delete=False) as handle:
            handle.write(data)
            image_path = Path(handle.name)
        model, cold_load = yolo_model(path)
        infer_start = time.perf_counter()
        results = model.predict(
            source=str(image_path),
            conf=parse_float(conf, 0.25, "conf"),
            iou=parse_float(iou, 0.7, "iou"),
            device="cpu",
            verbose=False,
        )
        detections: list[dict[str, Any]] = []
        for result in results:
            detections.extend(detection_items(result, model))
    except HTTPException:
        raise
    except Exception as exc:
        return JSONResponse(status_code=500, content={"ok": False, "error": "cpu_inference_failed", "message": str(exc).splitlines()[0][:300]})
    finally:
        if "image_path" in locals():
            image_path.unlink(missing_ok=True)

    version_id = int(model_version_id or 0)
    return {
        "ok": True,
        "mock": False,
        "model_ref": model_ref,
        "version_id": version_id,
        "model_version_id": version_id,
        "device_used": "cpu",
        "fallback_reason": None,
        "model": {"model_ref": model_ref, "model_version_id": version_id, "task_type": "detect", "sha256": sha256},
        "runtime": {"service_key": "yolo-cpu", "device_requested": execution_policy, "device_used": "cpu", "gpu_slot": None, "warm_state": "cold", "fallback": False, "fallback_reason": None, "cold_load": cold_load},
        "timing": {"inference_ms": int(round((time.perf_counter() - infer_start) * 1000)), "total_ms": int(round((time.perf_counter() - started) * 1000))},
        "detections": detections,
    }
