from __future__ import annotations

import os
import tempfile
import time
import json
from io import BytesIO
from pathlib import Path
from typing import Any

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse
from PIL import Image

app = FastAPI(title="3waAIHub BioCLIP")
_BIOCLIP_MODEL: Any | None = None
_BIOCLIP_PREPROCESS: Any | None = None
_BIOCLIP_TOKENIZER: Any | None = None
_BIOCLIP_MODEL_NAME = ""
_BIOCLIP_DEVICE = ""


def runtime_level() -> str:
    return "L5-benchmark-ready"


def env_enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


def configure_bioclip_env() -> None:
    model_dir = os.getenv("BIOCLIP_MODEL_DIR", "/models/bioclip")
    cache_dir = os.getenv("BIOCLIP_CACHE_DIR", "/cache/bioclip")
    env = {
        "HF_HOME": os.getenv("HF_HOME", f"{model_dir}/huggingface"),
        "XDG_CACHE_HOME": os.getenv("XDG_CACHE_HOME", f"{cache_dir}/xdg"),
        "HOME": os.getenv("HOME", f"{cache_dir}/home"),
        "PYTHONUNBUFFERED": os.getenv("PYTHONUNBUFFERED", "1"),
    }
    os.environ.update(env)
    for path in [
        model_dir,
        cache_dir,
        os.getenv("BIOCLIP_SERVICE_DATA_DIR", "/data/service"),
        env["HF_HOME"],
        env["XDG_CACHE_HOME"],
        env["HOME"],
    ]:
        Path(path).mkdir(parents=True, exist_ok=True)


def effective_device() -> str:
    import torch

    requested = os.getenv("BIOCLIP_DEVICE", "cpu")
    if requested == "auto":
        return "cuda" if torch.cuda.is_available() else "cpu"
    if requested == "cuda" and not torch.cuda.is_available():
        raise RuntimeError("gpu_unavailable")
    return requested


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
    configure_bioclip_env()
    storage = {
        "models": storage_path_status(os.getenv("BIOCLIP_MODEL_DIR", "/models/bioclip")),
        "cache": storage_path_status(os.getenv("BIOCLIP_CACHE_DIR", "/cache/bioclip")),
        "service_data": storage_path_status(os.getenv("BIOCLIP_SERVICE_DATA_DIR", "/data/service")),
    }
    errors = [
        f"{name} {key} failed: {status['path']}"
        for name, status in storage.items()
        for key in ("exists", "readable", "writable")
        if not status[key]
    ]
    return storage, errors


def dependency_status() -> dict[str, Any]:
    try:
        import torch
        import open_clip

        return {
            "available": True,
            "torch": getattr(torch, "__version__", "unknown"),
            "open_clip": getattr(open_clip, "__version__", "unknown"),
            "cuda_available": bool(torch.cuda.is_available()),
        }
    except Exception as exc:
        return {
            "available": False,
            "error": str(exc).splitlines()[0][:300],
        }


@app.get("/health")
def health() -> dict[str, Any]:
    storage, errors = storage_status()
    return {
        "ok": True,
        "service": "bioclip",
        "ready": not errors,
        "runtime_level": runtime_level(),
        "real_inference": env_enabled(os.getenv("BIOCLIP_REAL_INFERENCE")),
        "model": os.getenv("BIOCLIP_MODEL", "hf-hub:imageomics/bioclip-2"),
        "runtime": dependency_status(),
        "storage": storage,
        "errors": errors,
    }


def bioclip_model() -> tuple[Any, Any, Any, str]:
    global _BIOCLIP_MODEL, _BIOCLIP_PREPROCESS, _BIOCLIP_TOKENIZER, _BIOCLIP_MODEL_NAME, _BIOCLIP_DEVICE
    configure_bioclip_env()
    model_name = os.getenv("BIOCLIP_MODEL", "hf-hub:imageomics/bioclip-2")
    device = effective_device()
    if _BIOCLIP_MODEL is not None and _BIOCLIP_MODEL_NAME == model_name and _BIOCLIP_DEVICE == device:
        return _BIOCLIP_MODEL, _BIOCLIP_PREPROCESS, _BIOCLIP_TOKENIZER, device

    import open_clip

    model, _, preprocess = open_clip.create_model_and_transforms(model_name)
    tokenizer = open_clip.get_tokenizer(model_name)
    model.eval().to(device)
    _BIOCLIP_MODEL = model
    _BIOCLIP_PREPROCESS = preprocess
    _BIOCLIP_TOKENIZER = tokenizer
    _BIOCLIP_MODEL_NAME = model_name
    _BIOCLIP_DEVICE = device
    return model, preprocess, tokenizer, device


def parse_candidate_labels(value: str) -> list[str]:
    raw = (value or "").strip()
    if raw.startswith("["):
        parsed = json.loads(raw)
        labels = [str(item).strip() for item in parsed if str(item).strip()]
    else:
        labels = [label.strip() for label in raw.split(",") if label.strip()]
    if not labels:
        labels = ["animal", "plant", "fungus", "insect", "bird", "mammal"]
    return labels[:100]


def run_bioclip(image_bytes: bytes, candidate_labels: str) -> dict[str, Any]:
    import torch

    started = time.perf_counter()
    labels = parse_candidate_labels(candidate_labels)
    model, preprocess, tokenizer, device = bioclip_model()
    template = os.getenv("BIOCLIP_LABEL_TEMPLATE", "a photo of {}")

    try:
        pil_image = Image.open(BytesIO(image_bytes)).convert("RGB")
    except Exception as exc:
        raise ValueError("bad_image") from exc

    image_tensor = preprocess(pil_image).unsqueeze(0).to(device)
    text_tokens = tokenizer([template.format(label) for label in labels]).to(device)
    with torch.no_grad():
        image_features = model.encode_image(image_tensor)
        text_features = model.encode_text(text_tokens)
        image_features /= image_features.norm(dim=-1, keepdim=True)
        text_features /= text_features.norm(dim=-1, keepdim=True)
        probs = (100.0 * image_features @ text_features.T).softmax(dim=-1)[0]

    top_k = min(max(int(os.getenv("BIOCLIP_TOP_K", "5")), 1), len(labels))
    scores, indices = probs.topk(top_k)
    return {
        "ok": True,
        "mock": False,
        "runtime_level": runtime_level(),
        "model": os.getenv("BIOCLIP_MODEL", "hf-hub:imageomics/bioclip-2"),
        "labels": [
            {"label": labels[int(index)], "score": float(score)}
            for score, index in zip(scores.detach().cpu().tolist(), indices.detach().cpu().tolist())
        ],
        "device": {
            "requested": os.getenv("BIOCLIP_DEVICE", "cpu"),
            "effective": device,
        },
        "image": {
            "width": pil_image.width,
            "height": pil_image.height,
        },
        "elapsed_ms": int(round((time.perf_counter() - started) * 1000)),
    }


def failure_response(exc: Exception) -> JSONResponse:
    message = str(exc) or exc.__class__.__name__
    if message == "gpu_unavailable":
        return JSONResponse(status_code=503, content={"ok": False, "error": "gpu_unavailable", "message": "CUDA GPU is not available."})
    if message == "bad_image":
        return JSONResponse(status_code=400, content={"ok": False, "error": "bad_image", "message": "image cannot be decoded."})
    if exc.__class__.__name__ in {"ImportError", "ModuleNotFoundError"}:
        return JSONResponse(status_code=500, content={"ok": False, "error": "runtime_dependency_missing", "message": message.splitlines()[0][:300]})
    if "pretrained" in message.lower() or "hf_hub" in message.lower() or "huggingface" in message.lower():
        return JSONResponse(status_code=500, content={"ok": False, "error": "model_load_failed", "message": message.splitlines()[0][:300]})
    return JSONResponse(status_code=500, content={"ok": False, "error": "inference_failed", "message": message.splitlines()[0][:300]})


@app.post("/classify/image")
async def classify_image(
    image: UploadFile = File(...),
    candidate_labels: str = Form("species,plant,insect,bird,mammal"),
    real_inference: str = Form("0"),
) -> JSONResponse:
    data = await image.read()
    max_bytes = int(os.getenv("BIOCLIP_MAX_UPLOAD_MB", "50")) * 1024 * 1024
    if not data:
        return JSONResponse(status_code=400, content={"ok": False, "error": "bad_request", "message": "image is required"})
    if len(data) > max_bytes:
        return JSONResponse(status_code=413, content={"ok": False, "error": "file_too_large", "message": "image is too large"})
    if env_enabled(real_inference) or env_enabled(os.getenv("BIOCLIP_REAL_INFERENCE")):
        try:
            result = run_bioclip(data, candidate_labels)
            result["filename"] = image.filename
            result["bytes"] = len(data)
            return JSONResponse(content=result)
        except Exception as exc:
            return failure_response(exc)

    labels = [label.strip() for label in candidate_labels.split(",") if label.strip()]
    label = labels[0] if labels else "mock species"
    return JSONResponse(content={
        "ok": True,
        "mock": True,
        "runtime_level": runtime_level(),
        "model": os.getenv("BIOCLIP_MODEL", "hf-hub:imageomics/bioclip-2"),
        "labels": [{"label": label, "score": 1.0}],
        "filename": image.filename,
        "bytes": len(data),
        "device": {
            "requested": os.getenv("BIOCLIP_DEVICE", "auto"),
            "effective": "mock",
        },
    })
