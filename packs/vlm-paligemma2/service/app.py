from __future__ import annotations

import json
import os
import re
import tempfile
import time
from io import BytesIO
from pathlib import Path, PurePosixPath
from typing import Any

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse
from PIL import Image

app = FastAPI(title="3waAIHub PaliGemma 2 Service")

_PALIGEMMA_MODEL: Any | None = None
_PALIGEMMA_PROCESSOR: Any | None = None
_PALIGEMMA_MODEL_NAME = ""
_PALIGEMMA_MODEL_REVISION = ""
_PALIGEMMA_DEVICE = ""
_ACCEPTANCE_RECORD_NAME = "paligemma2-acceptance.json"
_ACCEPTANCE_FIXTURE_SHA256 = "53170e6afeba5c703e1e858c126a582e4494d137fb9592c0b1372c49f4e91f8c"


def runtime_level() -> str:
    # 真實模型尚未做 CUDA acceptance；不得把 import smoke 說成可交付推論。
    return "L2-deps-import"


def env_enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


def accepted_runtime_record() -> dict[str, Any] | None:
    """Only trust the local record written by the fixed-image CUDA acceptance command."""
    if not env_enabled(os.getenv("PALIGEMMA2_REAL_INFERENCE")):
        return None
    service_data = Path(os.getenv("PALIGEMMA2_SERVICE_DATA_DIR", "/data/service"))
    record_path = service_data / _ACCEPTANCE_RECORD_NAME
    if service_data.is_symlink() or not service_data.is_dir() or record_path.is_symlink() or not record_path.is_file():
        return None
    try:
        record = json.loads(record_path.read_text(encoding="utf-8"))
        from provision import configured_model

        model, revision = configured_model()
    except (OSError, json.JSONDecodeError, RuntimeError):
        return None
    if not isinstance(record, dict):
        return None
    required = {
        "schema_version": 1,
        "ok": True,
        "mock": False,
        "runtime_level": "L4-real-inference",
        "model": model,
        "revision": revision,
        "fixture_sha256": _ACCEPTANCE_FIXTURE_SHA256,
    }
    if any(record.get(key) != value for key, value in required.items()):
        return None
    if not isinstance(record.get("gpu"), str) or not record["gpu"].strip() or not isinstance(record.get("accepted_at"), str):
        return None
    for key in ("vram_total_bytes", "vram_peak_bytes", "elapsed_ms"):
        value = record.get(key)
        if not isinstance(value, int) or isinstance(value, bool) or value < 0:
            return None
    if record["vram_total_bytes"] < 1 or record["vram_peak_bytes"] < 1:
        return None

    return {
        "model": model,
        "revision": revision,
        "gpu": record["gpu"].strip(),
        "vram_total_bytes": record["vram_total_bytes"],
        "vram_peak_bytes": record["vram_peak_bytes"],
        "elapsed_ms": record["elapsed_ms"],
        "accepted_at": record["accepted_at"],
    }


def health_readiness(accepted: dict[str, Any] | None, errors: list[str]) -> tuple[bool, str, str]:
    if errors:
        return False, "storage_not_ready", runtime_level()
    if accepted is None:
        return False, "acceptance_pending", runtime_level()
    return True, "ready", "L4-real-inference"


def configure_paligemma_env() -> None:
    model_dir = os.getenv("PALIGEMMA2_MODEL_DIR", "/models/paligemma2")
    cache_dir = os.getenv("PALIGEMMA2_CACHE_DIR", "/cache/paligemma2")
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
        os.getenv("PALIGEMMA2_SERVICE_DATA_DIR", "/data/service"),
        env["HF_HOME"],
        env["XDG_CACHE_HOME"],
        env["HOME"],
    ]:
        Path(path).mkdir(parents=True, exist_ok=True)


def effective_device() -> str:
    import torch

    requested = os.getenv("PALIGEMMA2_DEVICE", "cuda").lower()
    if requested != "cuda" or not torch.cuda.is_available():
        raise RuntimeError("gpu_unavailable")
    return "cuda"


def provisioned_snapshot() -> dict[str, Any]:
    # API runtime 只讀取 explicit provisioner 寫出的 local snapshot；不允許推論時下載。
    from provision import verify_snapshot

    return verify_snapshot()


def lightweight_snapshot_status() -> dict[str, Any]:
    """讀取已驗證快照的 health 資訊，但不在每次 probe 重算全部模型雜湊。"""
    from provision import MANIFEST_NAME, REQUIRED_FILES, configured_model, snapshot_root

    model, revision = configured_model()
    root = snapshot_root()
    manifest_path = root / MANIFEST_NAME
    if root.is_symlink() or not root.is_dir() or manifest_path.is_symlink() or not manifest_path.is_file():
        raise RuntimeError("model_not_provisioned")
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise RuntimeError("model_manifest_invalid") from exc
    if manifest.get("model") != model or manifest.get("revision") != revision:
        raise RuntimeError("model_manifest_invalid")

    expected = manifest.get("files")
    if not isinstance(expected, list) or not expected:
        raise RuntimeError("model_manifest_invalid")

    names: set[str] = set()
    size_bytes = 0
    for item in expected:
        if not isinstance(item, dict):
            raise RuntimeError("model_manifest_invalid")
        relative = item.get("path")
        expected_size = item.get("size_bytes")
        expected_sha256 = item.get("sha256")
        if not isinstance(relative, str) or not isinstance(expected_size, int) or isinstance(expected_size, bool) or expected_size < 0:
            raise RuntimeError("model_manifest_invalid")
        if not isinstance(expected_sha256, str) or re.fullmatch(r"[0-9a-f]{64}", expected_sha256) is None:
            raise RuntimeError("model_manifest_invalid")

        pure_path = PurePosixPath(relative)
        if not relative or pure_path.is_absolute() or pure_path.as_posix() != relative or any(part in {".", ".."} for part in pure_path.parts):
            raise RuntimeError("model_manifest_invalid")
        if relative in names or pure_path.name == MANIFEST_NAME:
            raise RuntimeError("model_manifest_invalid")

        candidate = root
        for part in pure_path.parts:
            candidate = candidate / part
            if candidate.is_symlink():
                raise RuntimeError("model_manifest_invalid")
        if not candidate.is_file() or candidate.stat().st_size != expected_size:
            raise RuntimeError("model_manifest_invalid")
        names.add(relative)
        size_bytes += expected_size

    if not REQUIRED_FILES.issubset(names):
        raise RuntimeError("model_manifest_invalid")
    if not any(name.endswith(".safetensors") for name in names):
        raise RuntimeError("model_manifest_invalid")
    if not any(name in names for name in {"tokenizer.json", "tokenizer.model"}):
        raise RuntimeError("model_manifest_invalid")

    return {
        "model": model,
        "revision": revision,
        "snapshot": str(root),
        "file_count": len(names),
        "size_bytes": size_bytes,
    }


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
    configure_paligemma_env()
    storage = {
        "models": storage_path_status(os.getenv("PALIGEMMA2_MODEL_DIR", "/models/paligemma2")),
        "cache": storage_path_status(os.getenv("PALIGEMMA2_CACHE_DIR", "/cache/paligemma2")),
        "service_data": storage_path_status(os.getenv("PALIGEMMA2_SERVICE_DATA_DIR", "/data/service")),
    }
    errors = [f"{name}: {info['error']}" for name, info in storage.items() if info.get("error")]
    return storage, errors


def paligemma_model() -> tuple[Any, Any, str, str]:
    global _PALIGEMMA_MODEL, _PALIGEMMA_PROCESSOR, _PALIGEMMA_MODEL_NAME, _PALIGEMMA_MODEL_REVISION, _PALIGEMMA_DEVICE
    device = effective_device()

    if _PALIGEMMA_MODEL is not None:
        snapshot = lightweight_snapshot_status()
        model_name = str(snapshot["model"])
        revision = str(snapshot["revision"])
        if _PALIGEMMA_MODEL_NAME == model_name and _PALIGEMMA_MODEL_REVISION == revision and _PALIGEMMA_DEVICE == device:
            return _PALIGEMMA_MODEL, _PALIGEMMA_PROCESSOR, _PALIGEMMA_MODEL_NAME, _PALIGEMMA_DEVICE

    # 冷啟動或設定變更時，仍須完整重算檔案雜湊後才允許載入權重。
    snapshot = provisioned_snapshot()
    model_name = str(snapshot["model"])
    revision = str(snapshot["revision"])
    model_path = str(snapshot["snapshot"])

    configure_paligemma_env()
    import torch
    from transformers import AutoProcessor, PaliGemmaForConditionalGeneration

    processor = AutoProcessor.from_pretrained(model_path, local_files_only=True)
    # Pascal GTX 1080 不支援 bfloat16；使用完整 FP16，不聲稱未驗證的 4/8-bit 路徑。
    model = PaliGemmaForConditionalGeneration.from_pretrained(
        model_path,
        local_files_only=True,
        torch_dtype=torch.float16,
        low_cpu_mem_usage=True,
    )
    model.to(device)
    model.eval()

    _PALIGEMMA_MODEL = model
    _PALIGEMMA_PROCESSOR = processor
    _PALIGEMMA_MODEL_NAME = model_name
    _PALIGEMMA_MODEL_REVISION = revision
    _PALIGEMMA_DEVICE = device
    return model, processor, model_name, device


def parse_bounding_boxes(raw_text: str, img_width: int, img_height: int) -> list[dict[str, Any]]:
    """Parse PaliGemma spatial <locXXXX> tokens into normalized and pixel bounding boxes."""
    loc_pattern = re.compile(r"<loc(\d{4})><loc(\d{4})><loc(\d{4})><loc(\d{4})>(?:\s*([^<]+))?")
    boxes: list[dict[str, Any]] = []

    for match in loc_pattern.finditer(raw_text):
        y1_raw, x1_raw, y2_raw, x2_raw, label = match.groups()
        ymin = int(y1_raw) / 1024.0
        xmin = int(x1_raw) / 1024.0
        ymax = int(y2_raw) / 1024.0
        xmax = int(x2_raw) / 1024.0
        boxes.append(
            {
                "label": (label or "").strip(),
                "normalized_box": [round(ymin, 4), round(xmin, 4), round(ymax, 4), round(xmax, 4)],
                "pixel_box": [
                    int(round(xmin * img_width)),
                    int(round(ymin * img_height)),
                    int(round(xmax * img_width)),
                    int(round(ymax * img_height)),
                ],
            }
        )
    return boxes


def format_prompt_for_paligemma(prompt: str, task: str) -> str:
    p = (prompt or "").strip()
    task = (task or "caption").strip().lower()

    if task not in {"caption", "general"}:
        raise ValueError("unsupported_task")
    instruction = p if p else "caption en"
    return instruction if instruction.startswith("<image>") else "<image>" + instruction


def run_paligemma_inference(
    image_bytes: bytes,
    prompt: str,
    task: str = "caption",
    max_tokens: int = 64,
    temperature: float = 0.1,
) -> dict[str, Any]:
    started = time.perf_counter()
    model, processor, model_name, device = paligemma_model()

    try:
        pil_image = Image.open(BytesIO(image_bytes)).convert("RGB")
    except Exception as exc:
        raise ValueError("bad_image") from exc

    formatted_prompt = format_prompt_for_paligemma(prompt, task)
    inputs = processor(text=formatted_prompt, images=pil_image, return_tensors="pt")

    if device == "cuda" and hasattr(model, "device"):
        inputs = inputs.to(model.device)
    elif device == "cuda":
        inputs = inputs.to("cuda")

    import torch

    with torch.no_grad():
        output = model.generate(
            **inputs,
            max_new_tokens=max(16, min(128, max_tokens)),
            temperature=temperature if temperature > 0 else 0.1,
            do_sample=False,
        )

    input_len = inputs["input_ids"].shape[-1]
    decoded = processor.decode(output[0][input_len:], skip_special_tokens=False).strip()
    clean_text = processor.decode(output[0][input_len:], skip_special_tokens=True).strip()

    bboxes = parse_bounding_boxes(decoded, pil_image.width, pil_image.height)

    return {
        "ok": True,
        "mock": False,
        "runtime_level": "L4-real-inference",
        "model": model_name,
        "prompt": prompt,
        "task": (task or "caption").strip().lower(),
        "text": clean_text,
        "answer": clean_text,
        "raw_text": decoded,
        "bboxes": bboxes,
        "image": {
            "width": pil_image.width,
            "height": pil_image.height,
        },
        "device": {
            "requested": "cuda",
            "effective": device,
        },
        "elapsed_ms": int(round((time.perf_counter() - started) * 1000)),
    }


def mock_paligemma_response(prompt: str, task: str) -> dict[str, Any]:
    return {
        "ok": True,
        "mock": True,
        "runtime_level": runtime_level(),
        "model": os.getenv("PALIGEMMA2_MODEL", "google/paligemma2-3b-pt-224"),
        "prompt": prompt,
        "task": task,
        "text": "3waAIHub PaliGemma 2 mock response: motorcycle service manual page analyzed.",
        "answer": "3waAIHub PaliGemma 2 mock response: motorcycle service manual page analyzed.",
        "bboxes": [],
        "image": {"width": 800, "height": 600},
        "device": {"requested": "cuda", "effective": "mock"},
        "elapsed_ms": 1,
    }


def failure_response(exc: Exception) -> JSONResponse:
    message = str(exc) or exc.__class__.__name__
    if message == "gpu_unavailable":
        return JSONResponse(status_code=503, content={"ok": False, "error": "gpu_unavailable", "message": "CUDA GPU is not available."})
    if message in {"model_not_provisioned", "model_manifest_invalid"}:
        return JSONResponse(status_code=503, content={"ok": False, "error": message, "message": "PaliGemma 2 local model snapshot is not ready."})
    if message == "unsupported_task":
        return JSONResponse(status_code=422, content={"ok": False, "error": "unsupported_task", "message": "Only caption and general are accepted by the current PaliGemma 2 model contract."})
    if message == "bad_image":
        return JSONResponse(status_code=400, content={"ok": False, "error": "bad_image", "message": "image cannot be decoded."})
    if exc.__class__.__name__ in {"ImportError", "ModuleNotFoundError"}:
        return JSONResponse(status_code=500, content={"ok": False, "error": "runtime_dependency_missing", "message": message.splitlines()[0][:300]})
    return JSONResponse(status_code=500, content={"ok": False, "error": "inference_failed", "message": message.splitlines()[0][:300]})


@app.get("/health")
async def health_endpoint() -> JSONResponse:
    storage, errors = storage_status()
    device = "unknown"
    snapshot: dict[str, Any] | None = None
    try:
        device = effective_device()
    except Exception as exc:
        errors.append(f"device: {exc}")
    try:
        snapshot = lightweight_snapshot_status()
    except RuntimeError as exc:
        errors.append(f"model: {exc}")
    accepted = accepted_runtime_record()
    ready, status, level = health_readiness(accepted, errors)

    return JSONResponse(
        content={
            "ok": ready,
            "status": status,
            "runtime_level": level,
            "runtime_ready": ready,
            "model": os.getenv("PALIGEMMA2_MODEL", "google/paligemma2-3b-pt-224"),
            "model_revision": os.getenv("PALIGEMMA2_MODEL_REVISION", "96eeb174da13ca1a2b247e4d0867436296c36420"),
            "snapshot": None if snapshot is None else {"file_count": snapshot["file_count"], "size_bytes": snapshot["size_bytes"]},
            "acceptance": accepted,
            "device": device,
            "storage": storage,
            "errors": errors,
        }
    )


@app.post("/vision/image")
@app.post("/photo")
async def vision_endpoint(
    image: UploadFile = File(None),
    file: UploadFile = File(None),
    prompt: str = Form("caption"),
    task: str = Form("caption"),
    max_tokens: int = Form(64),
    temperature: float = Form(0.1),
    real_inference: str = Form("0"),
) -> JSONResponse:
    target_file = image or file
    if target_file is None:
        return JSONResponse(status_code=400, content={"ok": False, "error": "bad_request", "message": "image file is required"})

    data = await target_file.read()
    max_bytes = int(os.getenv("PALIGEMMA2_MAX_UPLOAD_MB", "50")) * 1024 * 1024
    if not data:
        return JSONResponse(status_code=400, content={"ok": False, "error": "bad_request", "message": "image is empty"})
    if len(data) > max_bytes:
        return JSONResponse(status_code=413, content={"ok": False, "error": "file_too_large", "message": "image exceeds maximum upload size"})

    if env_enabled(real_inference) and env_enabled(os.getenv("PALIGEMMA2_REAL_INFERENCE")):
        try:
            return JSONResponse(content=run_paligemma_inference(data, prompt, task, max_tokens, temperature))
        except Exception as exc:
            return failure_response(exc)

    return JSONResponse(content=mock_paligemma_response(prompt=prompt, task=task))
