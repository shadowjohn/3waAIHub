from __future__ import annotations

import json
import os
import tempfile
from functools import lru_cache
from pathlib import Path
from typing import Any

from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from ultralytics import SAM

app = FastAPI(title="3waAIHub SAM3 Adapter")


@lru_cache(maxsize=1)
def model() -> SAM:
    path = Path(os.getenv("SAM3_MODEL_PATH", "/models/sam3/sam3.pt"))
    if not path.is_file():
        raise HTTPException(status_code=503, detail=f"model not found: {path}")
    return SAM(str(path))


def parse_json_list(value: str, fallback: Any) -> Any:
    if value.strip() == "":
        return fallback
    try:
        return json.loads(value)
    except json.JSONDecodeError as exc:
        raise HTTPException(status_code=400, detail="invalid JSON prompt") from exc


@app.get("/health")
def health() -> dict[str, Any]:
    path = Path(os.getenv("SAM3_MODEL_PATH", "/models/sam3/sam3.pt"))
    return {"ok": True, "service": "sam3", "ready": path.is_file(), "model": str(path)}


@app.post("/segment/image")
async def segment_image(
    image: UploadFile = File(...),
    points: str = Form("[]"),
    labels: str = Form("[]"),
    bboxes: str = Form("[]"),
) -> dict[str, Any]:
    data = await image.read()
    if not data:
        raise HTTPException(status_code=400, detail="image is required")

    parsed_points = parse_json_list(points, [])
    parsed_labels = parse_json_list(labels, [])
    parsed_bboxes = parse_json_list(bboxes, [])
    if not parsed_points and not parsed_bboxes:
        raise HTTPException(status_code=400, detail="points or bboxes are required")

    args: dict[str, Any] = {
        "imgsz": int(os.getenv("SAM3_IMGSZ", "1024")),
        "device": os.getenv("ULTRALYTICS_DEVICE", "0"),
        "verbose": False,
    }
    if parsed_points:
        args["points"] = parsed_points
        args["labels"] = parsed_labels or [1 for _ in parsed_points]
    if parsed_bboxes:
        args["bboxes"] = parsed_bboxes

    with tempfile.NamedTemporaryFile(suffix=Path(image.filename or "image.jpg").suffix or ".jpg") as tmp:
        tmp.write(data)
        tmp.flush()
        results = model()(tmp.name, **args)

    result = results[0]
    max_points = int(os.getenv("SAM3_MAX_POLYGON_POINTS", "200"))
    masks = []
    if result.masks is not None:
        boxes = [] if result.boxes is None else result.boxes.xyxy.tolist()
        for i, polygon in enumerate(result.masks.xy):
            masks.append({
                "bbox": [float(v) for v in boxes[i]] if i < len(boxes) else None,
                "polygon": [[float(x), float(y)] for x, y in polygon[:max_points]],
                "polygon_truncated": len(polygon) > max_points,
            })

    return {"ok": True, "masks": masks, "count": len(masks)}
