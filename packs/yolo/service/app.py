from __future__ import annotations

import os
import tempfile
from functools import lru_cache
from pathlib import Path
from typing import Any

from fastapi import FastAPI, File, HTTPException, UploadFile
from ultralytics import YOLO

app = FastAPI(title="3waAIHub YOLO Adapter")


@lru_cache(maxsize=1)
def model() -> YOLO:
    path = Path(os.getenv("YOLO_MODEL_PATH", "/models/yolo/yolo11n.pt"))
    if not path.is_file():
        raise HTTPException(status_code=503, detail=f"model not found: {path}")
    return YOLO(str(path))


@app.get("/health")
def health() -> dict[str, Any]:
    path = Path(os.getenv("YOLO_MODEL_PATH", "/models/yolo/yolo11n.pt"))
    return {"ok": True, "service": "yolo", "ready": path.is_file(), "model": str(path)}


@app.post("/detect/image")
async def detect_image(image: UploadFile = File(...)) -> dict[str, Any]:
    data = await image.read()
    if not data:
        raise HTTPException(status_code=400, detail="image is required")

    with tempfile.NamedTemporaryFile(suffix=Path(image.filename or "image.jpg").suffix or ".jpg") as tmp:
        tmp.write(data)
        tmp.flush()
        results = model()(
            tmp.name,
            conf=float(os.getenv("YOLO_CONF", "0.25")),
            imgsz=int(os.getenv("YOLO_IMGSZ", "640")),
            device=os.getenv("ULTRALYTICS_DEVICE", "0"),
            verbose=False,
        )

    result = results[0]
    names = result.names or {}
    detections = []
    if result.boxes is not None:
        for box in result.boxes:
            class_id = int(box.cls[0].item())
            detections.append({
                "class_id": class_id,
                "class_name": names.get(class_id, str(class_id)),
                "confidence": float(box.conf[0].item()),
                "bbox": [float(v) for v in box.xyxy[0].tolist()],
            })

    return {"ok": True, "detections": detections, "count": len(detections)}
