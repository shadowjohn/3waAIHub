from __future__ import annotations

import os
import json
import tempfile
import time
from importlib import metadata, util
from pathlib import Path
from typing import Any

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse

app = FastAPI(title="3waAIHub PP-StructureV3")
PIPELINE: Any | None = None


def runtime_level() -> str:
    return "L4-real-inference"


def env_enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


def configure_runtime_env() -> None:
    model_dir = os.getenv("STRUCTURE_MODEL_DIR", "/models/ppstructurev3")
    cache_dir = os.getenv("STRUCTURE_CACHE_DIR", "/cache/ppstructurev3")
    os.environ.setdefault("PADDLEOCR_HOME", model_dir)
    os.environ.setdefault("PADDLEX_HOME", model_dir)
    os.environ.setdefault("XDG_CACHE_HOME", f"{cache_dir}/xdg")
    os.environ.setdefault("HOME", f"{model_dir}/home")


def dependency_status() -> dict[str, Any]:
    status: dict[str, Any] = {
        "paddleocr_available": util.find_spec("paddleocr") is not None,
        "paddle_available": util.find_spec("paddle") is not None,
    }
    for package in ("paddleocr",):
        try:
            status[package] = metadata.version(package)
        except metadata.PackageNotFoundError:
            status[package] = None
    try:
        status["paddlepaddle"] = metadata.version("paddlepaddle")
    except metadata.PackageNotFoundError:
        try:
            status["paddlepaddle"] = metadata.version("paddlepaddle-gpu")
        except metadata.PackageNotFoundError:
            status["paddlepaddle"] = None
    return status


def storage_path_status(path: str) -> dict[str, Any]:
    target = Path(path)
    target.mkdir(parents=True, exist_ok=True)
    error = ""
    writable = False
    try:
        with tempfile.NamedTemporaryFile(prefix=".3waaihub-write-", dir=target, delete=False) as handle:
            test_path = Path(handle.name)
        test_path.unlink(missing_ok=True)
        writable = True
    except OSError as exc:
        error = str(exc)

    status: dict[str, Any] = {
        "path": path,
        "exists": target.is_dir(),
        "readable": os.access(target, os.R_OK),
        "writable": writable,
    }
    if error:
        status["error"] = error
    return status


def storage_status() -> tuple[dict[str, Any], list[str]]:
    storage = {
        "models": storage_path_status(os.getenv("STRUCTURE_MODEL_DIR", "/models/ppstructurev3")),
        "cache": storage_path_status(os.getenv("STRUCTURE_CACHE_DIR", "/cache/ppstructurev3")),
        "service_data": storage_path_status(os.getenv("STRUCTURE_SERVICE_DATA_DIR", "/data/service")),
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
    runtime = dependency_status()
    if not runtime["paddleocr_available"]:
        errors.append("paddleocr dependency missing")
    if not runtime["paddle_available"]:
        errors.append("paddle dependency missing")
    return {
        "ok": True,
        "service": "structure-ppstructurev3",
        "ready": not errors,
        "runtime_level": runtime_level(),
        "real_inference": env_enabled(os.getenv("STRUCTURE_REAL_INFERENCE")),
        "runtime": runtime,
        "storage": storage,
        "errors": errors,
    }


def get_pipeline() -> Any:
    global PIPELINE
    if PIPELINE is not None:
        return PIPELINE

    configure_runtime_env()
    try:
        from paddleocr import PPStructureV3
    except Exception as exc:
        raise RuntimeError(f"runtime_dependency_missing: {exc}") from exc

    try:
        PIPELINE = PPStructureV3(device=os.getenv("STRUCTURE_DEVICE", "cpu"))
    except Exception as exc:
        raise RuntimeError(f"model_load_failed: {exc}") from exc

    return PIPELINE


def safe_suffix(filename: str | None) -> str:
    suffix = Path(filename or "input").suffix.lower()
    return suffix if suffix in {".pdf", ".png", ".jpg", ".jpeg", ".tif", ".tiff", ".bmp", ".webp"} else ".bin"


def read_text_files(paths: list[Path]) -> str:
    chunks = []
    for index, path in enumerate(paths, start=1):
        text = path.read_text(encoding="utf-8", errors="replace")
        if len(paths) > 1:
            chunks.append(f"\n\n<!-- page {index}: {path.name} -->\n\n{text}")
        else:
            chunks.append(text)
    return "\n".join(chunks).strip()


def read_json_files(paths: list[Path]) -> Any:
    decoded = []
    for path in paths:
        try:
            decoded.append(json.loads(path.read_text(encoding="utf-8")))
        except json.JSONDecodeError:
            decoded.append({"path": path.name, "raw": path.read_text(encoding="utf-8", errors="replace")})
    return decoded[0] if len(decoded) == 1 else decoded


def real_parse_document(data: bytes, filename: str | None, output_format: str) -> dict[str, Any]:
    started = time.perf_counter()
    with tempfile.TemporaryDirectory(prefix="ppstructurev3-") as tmp_dir:
        work_dir = Path(tmp_dir)
        input_path = work_dir / ("input" + safe_suffix(filename))
        output_dir = work_dir / "output"
        output_dir.mkdir(parents=True, exist_ok=True)
        input_path.write_bytes(data)

        pipeline = get_pipeline()
        try:
            results = pipeline.predict(input=str(input_path))
        except TypeError:
            results = pipeline.predict(str(input_path))
        except Exception as exc:
            raise RuntimeError(f"parse_failed: {exc}") from exc

        result_count = 0
        for result in results:
            result_count += 1
            if output_format in {"json", "both"}:
                result.save_to_json(save_path=str(output_dir), ensure_ascii=False)
            if output_format in {"markdown", "both"}:
                result.save_to_markdown(save_path=str(output_dir))

        payload: dict[str, Any] = {
            "ok": True,
            "mock": False,
            "runtime_level": runtime_level(),
            "output_format": output_format,
            "filename": filename,
            "bytes": len(data),
            "result_count": result_count,
            "model": "PP-StructureV3",
            "engine": "PaddleOCR",
            "device": os.getenv("STRUCTURE_DEVICE", "cpu"),
            "elapsed_ms": int((time.perf_counter() - started) * 1000),
        }
        if output_format in {"markdown", "both"}:
            markdown_paths = sorted(output_dir.glob("*.md"))
            payload["markdown"] = read_text_files(markdown_paths) if markdown_paths else ""
        if output_format in {"json", "both"}:
            json_paths = sorted(output_dir.glob("*.json"))
            payload["document_json"] = read_json_files(json_paths) if json_paths else []
        return payload


@app.post("/v1/parse")
async def parse_document(
    file: UploadFile = File(...),
    output_format: str = Form("both"),
    real_inference: str = Form("0"),
) -> JSONResponse:
    normalized_format = (output_format or os.getenv("STRUCTURE_OUTPUT_FORMAT", "both")).strip().lower()
    if normalized_format not in {"markdown", "json", "both"}:
        return JSONResponse(status_code=400, content={"ok": False, "error": "invalid_output_format", "message": "output_format must be markdown, json, or both"})

    data = await file.read()
    max_bytes = int(os.getenv("STRUCTURE_MAX_UPLOAD_MB", "100")) * 1024 * 1024
    if not data:
        return JSONResponse(status_code=400, content={"ok": False, "error": "bad_request", "message": "file is required"})
    if len(data) > max_bytes:
        return JSONResponse(status_code=413, content={"ok": False, "error": "file_too_large", "message": "file is too large"})

    if env_enabled(real_inference) or env_enabled(os.getenv("STRUCTURE_REAL_INFERENCE")):
        try:
            return JSONResponse(content=real_parse_document(data, file.filename, normalized_format))
        except RuntimeError as exc:
            text = str(exc)
            error = text.split(":", 1)[0] if ":" in text else "parse_failed"
            status = 503 if error in {"runtime_dependency_missing", "model_load_failed"} else 500
            return JSONResponse(status_code=status, content={"ok": False, "error": error, "message": text})

    payload: dict[str, Any] = {
        "ok": True,
        "mock": True,
        "runtime_level": runtime_level(),
        "output_format": normalized_format,
        "filename": file.filename,
        "bytes": len(data),
        "blocks": [
            {
                "type": "title",
                "text": "Mock document",
                "bbox": [0, 0, 0, 0],
                "confidence": 1.0,
            }
        ],
    }
    if normalized_format in {"markdown", "both"}:
        payload["markdown"] = "# Mock document\n\nPP-StructureV3 L3 mock parser is installed."
    if normalized_format in {"json", "both"}:
        payload["document_json"] = {"title": "Mock document", "blocks": payload["blocks"]}

    return JSONResponse(content=payload)
