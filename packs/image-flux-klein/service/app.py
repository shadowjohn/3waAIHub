import asyncio
import logging
import os
from pathlib import Path
from secrets import randbelow

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse, Response
from pydantic import BaseModel, ConfigDict, Field, ValidationError, field_validator
from starlette.concurrency import run_in_threadpool
from starlette.requests import ClientDisconnect

from engine import FluxEngine, FluxError
from model import MODEL_ID

MAX_BODY_BYTES = 16 * 1024
MAX_SEED = 2**32 - 1
ERRORS = {
    "model_not_provisioned": (503, "Prepare the pinned FLUX model snapshot before starting inference."),
    "gpu_unavailable": (503, "A CUDA 12.9 BF16 GPU runtime is required."),
    "gpu_out_of_memory": (503, "The GPU ran out of memory. Use a smaller image or free VRAM and try again."),
}


class GenerateInput(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)
    prompt: str = Field(min_length=1, max_length=1500)
    width: int = Field(default=768, ge=256, le=1024, multiple_of=16)
    height: int = Field(default=768, ge=256, le=1024, multiple_of=16)
    seed: int | None = Field(default=None, ge=0, le=MAX_SEED)

    @field_validator("prompt")
    @classmethod
    def trim_prompt(cls, value: str) -> str:
        value = value.strip()
        if not value or "\x00" in value:
            raise ValueError("Prompt must contain text and cannot contain NUL")
        return value


def error_response(status: int, code: str, message: str) -> JSONResponse:
    return JSONResponse({"ok": False, "error": code, "message": message}, status_code=status)


def runtime_error(code: str) -> JSONResponse:
    status, message = ERRORS[code]
    return error_response(status, code, message)


def create_app(engine: FluxEngine | None = None) -> FastAPI:
    app = FastAPI(title="3waAIHub FLUX.2 klein 4B")
    backend = engine if engine is not None else FluxEngine(
        Path(os.environ.get("FLUX_MODEL_DIR", "/models/flux-klein-4b"))
    )
    active = asyncio.Lock()

    @app.get("/health")
    async def health() -> Response:
        reason = await run_in_threadpool(backend.readiness)
        if reason:
            return runtime_error(reason)
        return JSONResponse({"ok": True, "service": "image-flux-klein"})

    @app.post("/generate")
    async def generate(request: Request) -> Response:
        if request.headers.get("content-type", "").split(";", 1)[0].strip().lower() != "application/json":
            return error_response(415, "unsupported_media_type", "Send application/json.")
        body = bytearray()
        try:
            async for chunk in request.stream():
                if len(body) + len(chunk) > MAX_BODY_BYTES:
                    return error_response(413, "payload_too_large", "JSON request exceeds 16 KiB.")
                body.extend(chunk)
            params = GenerateInput.model_validate_json(body)
        except (ValidationError, ClientDisconnect):
            return error_response(
                400, "invalid_parameter",
                "Provide a prompt and optional width, height (256–1024, multiples of 16) and integer seed.",
            )

        if active.locked():
            return error_response(429, "busy", "Another image is being generated.")
        seed = params.seed if params.seed is not None else randbelow(MAX_SEED + 1)
        async with active:
            try:
                image, elapsed_ms = await run_in_threadpool(
                    backend.generate, params.prompt, params.width, params.height, seed
                )
            except FluxError as error:
                return runtime_error(error.code)
            except Exception as error:
                logging.getLogger(__name__).warning("FLUX inference failed (%s)", type(error).__name__)
                return error_response(500, "inference_failed", "Image generation failed. Check the service log.")

        return Response(
            image,
            media_type="image/png",
            headers={
                "Cache-Control": "private, no-store",
                "X-3waAIHub-Model": MODEL_ID,
                "X-3waAIHub-Device": "cuda",
                "X-3waAIHub-Elapsed-Ms": str(elapsed_ms),
                "X-3waAIHub-Width": str(params.width),
                "X-3waAIHub-Height": str(params.height),
            },
        )

    return app


app = create_app()
