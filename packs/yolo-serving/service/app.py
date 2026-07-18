from __future__ import annotations

import hashlib
import os
import tempfile
import threading
import time
from pathlib import Path
from typing import Any

from fastapi import FastAPI, File, Form, HTTPException, Request, UploadFile
from fastapi.responses import JSONResponse

app = FastAPI(title="3waAIHub YOLO Serving")
_MODEL: Any | None = None
_MODEL_PATH = ""
_GPU_LOCK = threading.Lock()
_GPU_MODELS: dict[int, dict[str, Any]] = {}


def env_enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


def registry_root() -> Path:
    return Path(os.getenv("YOLO_MODEL_REGISTRY_DIR", "/models/registry")).resolve()


def service_device() -> str:
    return os.getenv("YOLO_SERVING_DEVICE", "cpu").strip() or "cpu"


def service_key() -> str:
    return "yolo-gpu0" if service_device().startswith("cuda") else "yolo-cpu"


def gpu_slot_count() -> int:
    try:
        return max(0, min(2, int(os.getenv("YOLO_GPU_SLOTS", "0"))))
    except ValueError:
        return 0


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


def load_yolo_model(path: Path, device: str) -> Any:
    configure_env()
    from ultralytics import YOLO

    model = YOLO(str(path))
    if str(getattr(model, "task", "detect") or "detect") != "detect":
        raise HTTPException(status_code=400, detail="model_task_unsupported")
    if device.startswith("cuda"):
        try:
            model.to(device)
        except AttributeError:
            pass

    return model


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


def parse_slot_no(value: Any) -> int:
    try:
        slot_no = int(value)
    except (TypeError, ValueError) as exc:
        raise HTTPException(status_code=400, detail="gpu_slot_invalid") from exc
    if slot_no not in {1, 2}:
        raise HTTPException(status_code=400, detail="gpu_slot_invalid")
    return slot_no


def version_id_int(value: str) -> int:
    try:
        return int(value or 0)
    except ValueError:
        return 0


def gpu_memory_bytes() -> int | None:
    try:
        import torch

        if torch.cuda.is_available():
            return int(torch.cuda.memory_allocated(0))
    except Exception:
        return None
    return None


def yolo_error(status: int, error: str, message: str | None = None) -> JSONResponse:
    return JSONResponse(status_code=status, content={"ok": False, "error": error, "message": message or error})


def slot_status(slot_no: int) -> dict[str, Any]:
    with _GPU_LOCK:
        entry = _GPU_MODELS.get(slot_no)
        if not entry:
            return {"slot_no": slot_no, "actual_state": "cold", "model_ref": None, "model_version_id": None}
        return {
            "slot_no": slot_no,
            "actual_state": "hot",
            "model_ref": entry["model_ref"],
            "model_version_id": entry["model_version_id"],
            "sha256": entry["sha256"],
            "loaded_at": entry["loaded_at"],
            "last_used_at": entry.get("last_used_at"),
            "vram_bytes": entry.get("vram_bytes"),
            "load_duration_ms": entry.get("load_duration_ms"),
            "warm_inference_ms": entry.get("warm_inference_ms"),
        }


@app.get("/health")
def health() -> dict[str, Any]:
    configure_env()
    root = registry_root()
    return {
        "ok": True,
        "service": "yolo-serving",
        "ready": root.is_dir() and os.access(root, os.R_OK),
        "runtime_level": "L3-storage-mount",
        "device": service_device(),
        "gpu_slots": gpu_slot_count(),
        "registry": {"path": str(root), "readable": os.access(root, os.R_OK)},
    }


@app.get("/models")
def models() -> dict[str, Any]:
    return {
        "ok": True,
        "service": "yolo-serving",
        "service_key": service_key(),
        "device": service_device(),
        "slots": [slot_status(slot_no) for slot_no in range(1, max(2, gpu_slot_count()) + 1)],
    }


@app.get("/models/{slot_no}/status")
def model_slot_status(slot_no: int) -> dict[str, Any]:
    parse_slot_no(slot_no)
    return {"ok": True, **slot_status(slot_no)}


@app.post("/models/warm", response_model=None)
async def warm_model(request: Request) -> dict[str, Any] | JSONResponse:
    started = time.perf_counter()
    payload = await request.json()
    slot_no = parse_slot_no(payload.get("slot_no"))
    if slot_no > max(0, gpu_slot_count()) or not service_device().startswith("cuda"):
        return yolo_error(409, "gpu_not_ready", "GPU slot is not enabled for this service")

    model_ref = str(payload.get("model_ref") or "").strip()
    model_version_id = int(payload.get("model_version_id") or 0)
    model_path = str(payload.get("model_path") or "")
    expected_sha = str(payload.get("sha256") or "").lower().strip()
    if not model_ref or not model_version_id:
        return yolo_error(400, "bad_request", "model_ref and model_version_id are required")

    try:
        import torch

        if not torch.cuda.is_available():
            return yolo_error(503, "gpu_not_ready", "CUDA is not available")
    except Exception as exc:
        return yolo_error(503, "gpu_not_ready", str(exc).splitlines()[0][:200])

    path = safe_model_path(model_path)
    if expected_sha and sha256_file(path) != expected_sha:
        return yolo_error(409, "model_checksum_mismatch", "registered model checksum mismatch")

    with _GPU_LOCK:
        existing = _GPU_MODELS.get(slot_no)
        if existing and existing["model_ref"] != model_ref:
            return yolo_error(409, "gpu_slot_occupied", "GPU slot already has a different model")

    try:
        load_start = time.perf_counter()
        model = load_yolo_model(path, service_device())
        load_ms = int(round((time.perf_counter() - load_start) * 1000))
        warm_start = time.perf_counter()
        try:
            import numpy as np

            dummy = np.zeros((64, 64, 3), dtype=np.uint8)
            model.predict(source=dummy, imgsz=64, device=service_device(), verbose=False)
        except ImportError:
            with tempfile.NamedTemporaryFile(prefix="yolo-warm-", suffix=".jpg", delete=False) as handle:
                handle.write(b"\xff\xd8\xff\xd9")
                dummy_path = Path(handle.name)
            try:
                model.predict(source=str(dummy_path), imgsz=64, device=service_device(), verbose=False)
            finally:
                dummy_path.unlink(missing_ok=True)
        warm_ms = int(round((time.perf_counter() - warm_start) * 1000))
    except HTTPException:
        raise
    except Exception as exc:
        message = str(exc).splitlines()[0][:300]
        error = "gpu_out_of_memory" if "out of memory" in message.lower() else "gpu_warm_failed"
        return yolo_error(503, error, message)

    loaded_at = time.strftime("%Y-%m-%d %H:%M:%S")
    entry = {
        "model": model,
        "model_ref": model_ref,
        "model_version_id": model_version_id,
        "model_path": str(path),
        "sha256": expected_sha,
        "loaded_at": loaded_at,
        "last_used_at": None,
        "vram_bytes": gpu_memory_bytes(),
        "load_duration_ms": load_ms,
        "warm_inference_ms": warm_ms,
    }
    with _GPU_LOCK:
        _GPU_MODELS[slot_no] = entry

    return {
        "ok": True,
        "state": "hot",
        "slot_no": slot_no,
        "model_ref": model_ref,
        "model_version_id": model_version_id,
        "vram_bytes": entry["vram_bytes"],
        "load_duration_ms": load_ms,
        "warm_inference_ms": warm_ms,
        "elapsed_ms": int(round((time.perf_counter() - started) * 1000)),
    }


@app.post("/models/unload", response_model=None)
async def unload_model(request: Request) -> dict[str, Any] | JSONResponse:
    payload = await request.json()
    slot_no = parse_slot_no(payload.get("slot_no"))
    model_ref = str(payload.get("model_ref") or "").strip()
    with _GPU_LOCK:
        entry = _GPU_MODELS.get(slot_no)
        if entry and model_ref and entry["model_ref"] != model_ref:
            return yolo_error(409, "gpu_model_slot_mismatch", "GPU slot has a different model")
        if entry:
            del _GPU_MODELS[slot_no]
    try:
        import torch

        if torch.cuda.is_available():
            torch.cuda.empty_cache()
    except Exception:
        pass

    return {"ok": True, "slot_no": slot_no, "model_ref": model_ref or None, "state": "cold"}


@app.post("/detect/image", response_model=None)
async def detect_image(
    image: UploadFile = File(...),
    model_ref: str = Form(...),
    model_path: str = Form(...),
    model_version_id: str = Form("0"),
    sha256: str = Form(""),
    execution_policy: str = Form("auto"),
    device: str = Form("cpu"),
    slot_no: str = Form(""),
    fallback_reason: str = Form(""),
    conf: str | None = Form(None),
    iou: str | None = Form(None),
) -> dict[str, Any] | JSONResponse:
    started = time.perf_counter()
    data = await image.read()
    if not data:
        raise HTTPException(status_code=400, detail="image is required")
    path = safe_model_path(model_path)
    if sha256 and sha256_file(path) != sha256.lower():
        return JSONResponse(status_code=409, content={"ok": False, "error": "model_checksum_mismatch", "message": "registered model checksum mismatch"})

    if not env_enabled(os.getenv("YOLO_SERVING_REAL_INFERENCE", "1")):
        version_id = int(model_version_id or 0)
        gpu_slot = parse_slot_no(slot_no) if device.startswith("cuda") else None
        return {
            "ok": True,
            "mock": True,
            "model_ref": model_ref,
            "version_id": version_id,
            "model_version_id": version_id,
            "device_used": device if device.startswith("cuda") else "cpu",
            "slot_no": gpu_slot,
            "fallback_reason": fallback_reason or None,
            "model": {"model_ref": model_ref, "model_version_id": version_id, "task_type": "detect"},
            "runtime": {"service_key": service_key(), "device_requested": device, "device_used": device if device.startswith("cuda") else "cpu", "gpu_slot": gpu_slot, "warm_state": "hot" if gpu_slot else "cold", "fallback": bool(fallback_reason), "fallback_reason": fallback_reason or None, "cold_load": False},
            "timing": {"total_ms": int(round((time.perf_counter() - started) * 1000))},
            "detections": [],
        }

    suffix = Path(image.filename or "upload.jpg").suffix or ".jpg"
    try:
        with tempfile.NamedTemporaryFile(prefix="yolo-serving-", suffix=suffix, delete=False) as handle:
            handle.write(data)
            image_path = Path(handle.name)
        gpu_slot = None
        cold_load = False
        inference_device = "cpu"
        if device.startswith("cuda"):
            gpu_slot = parse_slot_no(slot_no)
            with _GPU_LOCK:
                entry = _GPU_MODELS.get(gpu_slot)
                if not entry:
                    return yolo_error(409, "gpu_not_ready", "GPU model slot is not hot")
                if entry["model_ref"] != model_ref or int(entry["model_version_id"]) != version_id_int(model_version_id):
                    return yolo_error(409, "gpu_model_slot_mismatch", "GPU slot has a different model")
                if sha256 and str(entry.get("sha256") or "") and str(entry["sha256"]).lower() != sha256.lower():
                    return yolo_error(409, "model_checksum_mismatch", "registered model checksum mismatch")
                model = entry["model"]
            inference_device = device
        else:
            model, cold_load = yolo_model(path)
        infer_start = time.perf_counter()
        results = model.predict(
            source=str(image_path),
            conf=parse_float(conf, 0.25, "conf"),
            iou=parse_float(iou, 0.7, "iou"),
            device=inference_device,
            verbose=False,
        )
        if gpu_slot is not None:
            with _GPU_LOCK:
                if gpu_slot in _GPU_MODELS:
                    _GPU_MODELS[gpu_slot]["last_used_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
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
        "device_used": inference_device,
        "slot_no": gpu_slot,
        "fallback_reason": fallback_reason or None,
        "model": {"model_ref": model_ref, "model_version_id": version_id, "task_type": "detect", "sha256": sha256},
        "runtime": {"service_key": service_key(), "device_requested": device, "device_used": inference_device, "gpu_slot": gpu_slot, "warm_state": "hot" if gpu_slot else "cold", "fallback": bool(fallback_reason), "fallback_reason": fallback_reason or None, "cold_load": cold_load},
        "timing": {"inference_ms": int(round((time.perf_counter() - infer_start) * 1000)), "total_ms": int(round((time.perf_counter() - started) * 1000))},
        "detections": detections,
    }
