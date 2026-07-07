from __future__ import annotations

import os
import tempfile
from pathlib import Path
from typing import Any

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse

app = FastAPI(title="3waAIHub SAM3")
MODEL_EXTENSIONS = {".pt", ".pth", ".safetensors", ".ckpt"}


def runtime_level() -> str:
    return "L4a-model-present-smoke"


def env_enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


def configure_sam3_env() -> None:
    model_dir = os.getenv("SAM3_MODEL_DIR", "/models/sam3")
    cache_dir = os.getenv("SAM3_CACHE_DIR", "/cache/sam3")
    env = {
        "HF_HOME": os.getenv("HF_HOME", f"{model_dir}/huggingface"),
        "TORCH_HOME": os.getenv("TORCH_HOME", f"{model_dir}/torch"),
        "XDG_CACHE_HOME": os.getenv("XDG_CACHE_HOME", f"{cache_dir}/xdg"),
        "HOME": os.getenv("HOME", f"{cache_dir}/home"),
        "PYTHONUNBUFFERED": os.getenv("PYTHONUNBUFFERED", "1"),
    }
    os.environ.update(env)
    for path in [
        model_dir,
        cache_dir,
        os.getenv("SAM3_SERVICE_DATA_DIR", "/data/service"),
        env["HF_HOME"],
        env["TORCH_HOME"],
        env["XDG_CACHE_HOME"],
        env["HOME"],
    ]:
        Path(path).mkdir(parents=True, exist_ok=True)


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
    configure_sam3_env()
    storage = {
        "models": storage_path_status(os.getenv("SAM3_MODEL_DIR", "/models/sam3")),
        "cache": storage_path_status(os.getenv("SAM3_CACHE_DIR", "/cache/sam3")),
        "service_data": storage_path_status(os.getenv("SAM3_SERVICE_DATA_DIR", "/data/service")),
    }
    errors = [
        f"{name} {key} failed: {status['path']}"
        for name, status in storage.items()
        for key in ("exists", "readable", "writable")
        if not status[key]
    ]
    return storage, errors


def is_path_under(path: Path, root: Path) -> bool:
    try:
        path.resolve(strict=False).relative_to(root.resolve(strict=False))
        return True
    except ValueError:
        return False


def safe_checkpoint_path(raw: str, model_root: Path) -> tuple[Path | None, str]:
    value = raw.strip()
    if value == "":
        return None, "scan"
    if "\x00" in value:
        return None, "invalid_checkpoint_path"

    candidate = Path(value)
    if not candidate.is_absolute():
        candidate = model_root / candidate
    if not is_path_under(candidate, model_root):
        return None, "invalid_checkpoint_path"

    return candidate, "SAM3_CHECKPOINT"


def is_safe_model_file(path: Path, model_root: Path) -> bool:
    if path.suffix.lower() not in MODEL_EXTENSIONS or path.is_dir():
        return False
    try:
        resolved = path.resolve(strict=True)
        resolved.relative_to(model_root.resolve(strict=True))
    except (OSError, ValueError):
        return False

    return path.is_file()


def model_candidates(model_root: Path) -> list[Path]:
    candidates: list[Path] = []
    if not model_root.is_dir():
        return candidates

    for current_root, dirs, files in os.walk(model_root):
        root_path = Path(current_root)
        dirs[:] = [name for name in dirs if not (root_path / name).is_symlink()]
        for filename in files:
            candidate = root_path / filename
            if is_safe_model_file(candidate, model_root):
                candidates.append(candidate)

    return sorted(candidates, key=lambda path: str(path))


def model_status() -> dict[str, Any]:
    model_root = Path(os.getenv("SAM3_MODEL_DIR", "/models/sam3"))
    raw_checkpoint = os.getenv("SAM3_CHECKPOINT", "")
    candidates = model_candidates(model_root)
    checkpoint, source = safe_checkpoint_path(raw_checkpoint, model_root)

    error = ""
    if source == "invalid_checkpoint_path":
        error = "invalid_checkpoint_path"
        checkpoint = None
    elif checkpoint is not None and is_safe_model_file(checkpoint, model_root):
        return {
            "present": True,
            "checkpoint": str(checkpoint.resolve(strict=True)),
            "source": source,
            "required_for_real_inference": True,
            "candidates_count": len(candidates),
        }
    elif checkpoint is None and candidates:
        return {
            "present": True,
            "checkpoint": str(candidates[0].resolve(strict=True)),
            "source": "scan",
            "required_for_real_inference": True,
            "candidates_count": len(candidates),
        }

    status: dict[str, Any] = {
        "present": False,
        "checkpoint": str(checkpoint) if checkpoint is not None else "",
        "source": source,
        "required_for_real_inference": True,
        "candidates_count": len(candidates),
    }
    if error:
        status["error"] = error

    return status


@app.get("/health")
def health() -> dict[str, Any]:
    storage, errors = storage_status()
    model = model_status()
    warnings = []
    if not model["present"]:
        warnings.append("model_not_present")
    if "error" in model:
        warnings.append((str(model["error"])))

    return {
        "ok": True,
        "service": "sam3",
        "ready": not errors and bool(model["present"]),
        "runtime_level": runtime_level(),
        "real_inference": env_enabled(os.getenv("SAM3_REAL_INFERENCE")),
        "storage": storage,
        "gpu": {"checked": False},
        "model": model,
        "warnings": warnings,
        "errors": errors,
    }


@app.post("/segment/image")
async def segment_image(
    image: UploadFile = File(...),
    prompt_type: str = Form("auto"),
    points_json: str = Form(""),
    boxes_json: str = Form(""),
    real_inference: str = Form("0"),
) -> JSONResponse:
    data = await image.read()
    if not data:
        return JSONResponse(status_code=400, content={"ok": False, "error": "bad_request", "message": "image is required"})
    if env_enabled(real_inference) or env_enabled(os.getenv("SAM3_REAL_INFERENCE")):
        return JSONResponse(status_code=501, content={
            "ok": False,
            "error": "runtime_not_ready",
            "message": "real SAM3 inference is not implemented in this runtime level",
        })

    return JSONResponse(content={
        "ok": True,
        "mock": True,
        "runtime_level": runtime_level(),
        "prompt_type": prompt_type or "auto",
        "masks": [],
        "boxes": [],
        "message": "SAM3 mock segmentation",
    })
