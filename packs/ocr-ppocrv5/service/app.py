from __future__ import annotations

import os
from typing import Any

from fastapi import FastAPI, File, HTTPException, UploadFile

app = FastAPI(title="3waAIHub PP-OCRv5 Mock")


@app.get("/health")
def health() -> dict[str, Any]:
    return {"ok": True, "service": "ocr-ppocrv5", "ready": True}


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
        "filename": image.filename,
        "bytes": len(data),
    }
