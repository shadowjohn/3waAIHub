from __future__ import annotations

from fastapi import FastAPI, File, UploadFile
from fastapi.responses import JSONResponse


app = FastAPI(title="3waAIHub BiRefNet Adapter", version="0.1.0")


@app.get("/health")
def health() -> dict[str, object]:
    return {
        "ok": True,
        "runtime_level": "L3-storage-mount",
        "runtime_ready": False,
    }


@app.post("/remove-background/image")
async def remove_background(image: UploadFile | None = File(default=None)) -> JSONResponse:
    if image is None:
        return JSONResponse(
            status_code=400,
            content={"ok": False, "error": "file_required", "message": "image is required"},
        )

    return JSONResponse(
        status_code=503,
        content={
            "ok": False,
            "error": "runtime_not_ready",
            "message": "BiRefNet runtime is not ready",
        },
    )
