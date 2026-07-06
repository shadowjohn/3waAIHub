from __future__ import annotations

import os
import tempfile
from pathlib import Path
from typing import Any

from fastapi import FastAPI, File, HTTPException, UploadFile

app = FastAPI(title="3waAIHub PP-OCRv5 Mock")


def runtime_level() -> str:
    return "L4a-model-init-smoke"


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
    storage = {
        "models": storage_path_status(os.getenv("OCR_MODEL_DIR", "/models/paddleocr")),
        "cache": storage_path_status(os.getenv("OCR_CACHE_DIR", "/cache/paddleocr")),
        "service_data": storage_path_status(os.getenv("OCR_SERVICE_DATA_DIR", "/data/service")),
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
        "service": "ocr-ppocrv5",
        "ready": not errors,
        "runtime_level": runtime_level(),
        "storage": storage,
        "errors": errors,
    }


@app.post("/ocr/image")
async def ocr_image(image: UploadFile = File(...)) -> dict[str, Any]:
    data = await image.read()
    max_bytes = int(os.getenv("OCR_MAX_UPLOAD_MB", "50")) * 1024 * 1024
    if not data:
        raise HTTPException(status_code=400, detail="image is required")
    if len(data) > max_bytes:
        raise HTTPException(status_code=413, detail="image is too large")

    text = os.getenv("OCR_MOCK_TEXT", "3waAIHub OCR mock")
    return {
        "ok": True,
        "text": text,
        "blocks": [{"text": text, "bbox": [0, 0, 0, 0], "confidence": 1.0}],
        "mock": True,
        "runtime_level": runtime_level(),
        "filename": image.filename,
        "bytes": len(data),
    }
