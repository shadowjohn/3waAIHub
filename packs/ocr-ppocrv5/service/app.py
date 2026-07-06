from __future__ import annotations

import os
import tempfile
import json
from pathlib import Path
from typing import Any

from fastapi import FastAPI, File, Form, HTTPException, UploadFile

app = FastAPI(title="3waAIHub PP-OCRv5")
_OCR_ENGINE: Any | None = None


def runtime_level() -> str:
    return "L5-benchmark-ready"


def env_enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


def configure_ocr_env() -> None:
    model_dir = os.getenv("OCR_MODEL_DIR", "/models/paddleocr")
    cache_dir = os.getenv("OCR_CACHE_DIR", "/cache/paddleocr")
    # PaddleOCR 3.7 + PaddlePaddle 3.3 CPU inference can trip PIR/oneDNN here.
    os.environ.setdefault("PADDLE_PDX_ENABLE_MKLDNN_BYDEFAULT", "0")
    os.environ.setdefault("PADDLEOCR_HOME", model_dir)
    os.environ.setdefault("XDG_CACHE_HOME", f"{cache_dir}/xdg")
    os.environ.setdefault("HOME", f"{model_dir}/home")

    for path in [
        model_dir,
        cache_dir,
        os.getenv("OCR_SERVICE_DATA_DIR", "/data/service"),
        os.environ["XDG_CACHE_HOME"],
        os.environ["HOME"],
    ]:
        Path(path).mkdir(parents=True, exist_ok=True)


def paddleocr_init_kwargs(cls: type[Any]) -> dict[str, Any]:
    import inspect

    params = inspect.signature(cls).parameters
    kwargs: dict[str, Any] = {}
    lang = os.getenv("OCR_LANG", "ch")
    if "lang" in params and lang != "auto":
        kwargs["lang"] = lang
    for key in ["use_doc_orientation_classify", "use_doc_unwarping", "use_textline_orientation", "use_angle_cls"]:
        if key in params:
            kwargs[key] = False
    if "use_gpu" in params:
        kwargs["use_gpu"] = env_enabled(os.getenv("OCR_USE_GPU"))

    return kwargs


def ocr_engine() -> Any:
    global _OCR_ENGINE
    if _OCR_ENGINE is None:
        configure_ocr_env()
        from paddleocr import PaddleOCR

        _OCR_ENGINE = PaddleOCR(**paddleocr_init_kwargs(PaddleOCR))

    return _OCR_ENGINE


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
    configure_ocr_env()
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


def as_jsonable(value: Any) -> Any:
    if hasattr(value, "json"):
        raw = value.json
        return as_jsonable(raw() if callable(raw) else raw)
    if hasattr(value, "to_json"):
        return as_jsonable(value.to_json())
    if isinstance(value, str) and value[:1] in {"{", "["}:
        try:
            return json.loads(value)
        except json.JSONDecodeError:
            return value
    if hasattr(value, "tolist"):
        return value.tolist()
    if isinstance(value, dict):
        return {str(key): as_jsonable(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [as_jsonable(item) for item in value]
    return value


def bbox_value(value: Any) -> Any:
    return as_jsonable(value) if value is not None else [0, 0, 0, 0]


def add_block(blocks: list[dict[str, Any]], text: Any, bbox: Any = None, confidence: Any = 0.0) -> None:
    text = str(text or "").strip()
    if text == "":
        return
    try:
        score = float(confidence)
    except (TypeError, ValueError):
        score = 0.0
    blocks.append({"text": text, "bbox": bbox_value(bbox), "confidence": score})


def collect_blocks(value: Any, blocks: list[dict[str, Any]]) -> None:
    value = as_jsonable(value)
    if isinstance(value, dict):
        data = value.get("res") if isinstance(value.get("res"), dict) else value
        texts = data.get("rec_texts") or data.get("texts")
        if isinstance(texts, list):
            boxes = data.get("rec_polys") or data.get("dt_polys") or data.get("rec_boxes") or data.get("boxes") or []
            scores = data.get("rec_scores") or data.get("scores") or []
            for index, text in enumerate(texts):
                add_block(
                    blocks,
                    text,
                    boxes[index] if index < len(boxes) else None,
                    scores[index] if index < len(scores) else 0.0,
                )
            return
        if "text" in data:
            add_block(blocks, data.get("text"), data.get("bbox") or data.get("box"), data.get("confidence") or data.get("score") or 0.0)
            return
        for item in data.values():
            collect_blocks(item, blocks)
        return

    if isinstance(value, list):
        if len(value) >= 2 and isinstance(value[1], (list, tuple)) and len(value[1]) >= 2 and isinstance(value[1][0], str):
            add_block(blocks, value[1][0], value[0], value[1][1])
            return
        for item in value:
            collect_blocks(item, blocks)


def run_paddleocr(image_path: Path) -> dict[str, Any]:
    engine = ocr_engine()
    if hasattr(engine, "predict"):
        try:
            raw = engine.predict(str(image_path))
        except TypeError:
            raw = engine.predict(input=str(image_path))
    elif hasattr(engine, "ocr"):
        raw = engine.ocr(str(image_path), cls=False)
    else:
        raise RuntimeError("PaddleOCR engine has no predict or ocr method")

    blocks: list[dict[str, Any]] = []
    collect_blocks(raw, blocks)
    text = "\n".join(block["text"] for block in blocks)

    return {
        "ok": True,
        "text": text,
        "blocks": blocks,
        "mock": False,
        "real_inference": True,
        "runtime_level": runtime_level(),
    }


@app.post("/ocr/image")
async def ocr_image(image: UploadFile = File(...), real_inference: str = Form("0")) -> dict[str, Any]:
    data = await image.read()
    max_bytes = int(os.getenv("OCR_MAX_UPLOAD_MB", "50")) * 1024 * 1024
    if not data:
        raise HTTPException(status_code=400, detail="image is required")
    if len(data) > max_bytes:
        raise HTTPException(status_code=413, detail="image is too large")

    if env_enabled(os.getenv("OCR_REAL_INFERENCE")) or env_enabled(real_inference):
        suffix = Path(image.filename or "upload.png").suffix or ".png"
        try:
            with tempfile.NamedTemporaryFile(prefix="ocr-", suffix=suffix, delete=False) as handle:
                handle.write(data)
                image_path = Path(handle.name)
            result = run_paddleocr(image_path)
        except Exception as exc:
            raise HTTPException(status_code=500, detail=f"inference failed: {exc}") from exc
        finally:
            if "image_path" in locals():
                image_path.unlink(missing_ok=True)
        result["filename"] = image.filename
        result["bytes"] = len(data)
        return result

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
