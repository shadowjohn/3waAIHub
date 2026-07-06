from __future__ import annotations

from typing import Any

from fastapi import FastAPI, File, HTTPException, UploadFile

app = FastAPI(title="3waAIHub YOLO Mock")


def runtime_level() -> str:
    return "L2-deps-import"


@app.get("/health")
def health() -> dict[str, Any]:
    return {
        "ok": True,
        "service": "yolo",
        "ready": True,
        "runtime_level": runtime_level(),
    }


@app.post("/detect/image")
async def detect_image(image: UploadFile = File(...)) -> dict[str, Any]:
    data = await image.read()
    if not data:
        raise HTTPException(status_code=400, detail="image is required")

    return {
        "ok": True,
        "mock": True,
        "runtime_level": runtime_level(),
        "detections": [],
        "filename": image.filename,
        "bytes": len(data),
    }
