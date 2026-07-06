from __future__ import annotations

import os
import tempfile
from pathlib import Path
from typing import Any

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse

app = FastAPI(title="3waAIHub SAM3")


def runtime_level() -> str:
    return "L3-storage-mount"


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


@app.get("/health")
def health() -> dict[str, Any]:
    storage, errors = storage_status()
    checkpoint = os.getenv("SAM3_CHECKPOINT", "")
    return {
        "ok": True,
        "service": "sam3",
        "ready": not errors,
        "runtime_level": runtime_level(),
        "real_inference": env_enabled(os.getenv("SAM3_REAL_INFERENCE")),
        "storage": storage,
        "gpu": {"checked": False},
        "model": {
            "present": bool(checkpoint),
            "checkpoint": checkpoint,
            "required_for_this_level": False,
        },
        "errors": errors,
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
        return JSONResponse(status_code=400, content={"ok": False, "error": "bad_request", "message": "image is required"})
    if env_enabled(real_inference) or env_enabled(os.getenv("SAM3_REAL_INFERENCE")):
        return JSONResponse(status_code=501, content={
            "ok": False,
            "error": "runtime_not_ready",
            "message": "real SAM3 inference is not implemented in this runtime level",
        })

    return JSONResponse(content={
        "ok": True,
        "mock": True,
        "runtime_level": runtime_level(),
        "prompt_type": prompt_type or "auto",
        "masks": [],
        "boxes": [],
        "message": "SAM3 mock segmentation",
    })
