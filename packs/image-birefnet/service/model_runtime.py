from __future__ import annotations

import gc
import hashlib
import json
import os
import re
import threading
from pathlib import Path
from typing import Any, Callable, Mapping

from provision_offline_assets import MODEL_REPOSITORY, MODEL_REVISION


DEFAULT_MODEL_ROOT = Path("/models/birefnet")
_MODEL_LOCK = threading.Lock()
_MODEL_STATE: tuple[Any, str] | None = None


class ModelRuntimeError(RuntimeError):
    def __init__(self, code: str) -> None:
        self.code = code
        super().__init__(code)


def _hash_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _snapshot_files(snapshot: Path) -> set[str]:
    files: set[str] = set()
    for current_root, directories, filenames in os.walk(snapshot):
        current = Path(current_root)
        directories[:] = sorted(name for name in directories if name not in {".cache", ".git"})
        for name in filenames:
            candidate = current / name
            relative = candidate.relative_to(snapshot)
            if any(part in {".cache", ".git"} for part in relative.parts):
                continue
            files.add(relative.as_posix())
    return files


def verify_ready(model_root: Path = DEFAULT_MODEL_ROOT) -> dict[str, Any]:
    try:
        root = Path(model_root)
        snapshot = root / "snapshot"
        ready_path = root / "ready.json"
        if not snapshot.is_dir() or not ready_path.is_file() or ready_path.stat().st_size > 2 * 1024 * 1024:
            raise ModelRuntimeError("model_not_present")
        payload = json.loads(ready_path.read_text(encoding="utf-8"))
        if (
            not isinstance(payload, dict)
            or payload.get("repository") != MODEL_REPOSITORY
            or payload.get("revision") != MODEL_REVISION
            or not isinstance(payload.get("files"), list)
        ):
            raise ModelRuntimeError("model_load_failed")

        snapshot_root = snapshot.resolve(strict=True)
        listed: set[str] = set()
        for row in payload["files"]:
            if not isinstance(row, dict) or set(row) != {"path", "size", "sha256"}:
                raise ModelRuntimeError("model_load_failed")
            relative = row["path"]
            size = row["size"]
            expected_hash = row["sha256"]
            if (
                not isinstance(relative, str)
                or relative == ""
                or relative.startswith("/")
                or "\\" in relative
                or ".." in Path(relative).parts
                or relative in listed
                or not isinstance(size, int)
                or isinstance(size, bool)
                or size < 0
                or not isinstance(expected_hash, str)
                or re.fullmatch(r"[a-f0-9]{64}", expected_hash) is None
            ):
                raise ModelRuntimeError("model_load_failed")
            candidate = snapshot / relative
            resolved = candidate.resolve(strict=True)
            resolved.relative_to(snapshot_root)
            if not resolved.is_file() or resolved.stat().st_size != size or _hash_file(resolved) != expected_hash:
                raise ModelRuntimeError("model_load_failed")
            listed.add(relative)

        if "config.json" not in listed or not any(path.endswith(".safetensors") for path in listed):
            raise ModelRuntimeError("model_load_failed")
        if listed != _snapshot_files(snapshot):
            raise ModelRuntimeError("model_load_failed")
        return payload
    except ModelRuntimeError:
        raise
    except (OSError, ValueError, TypeError, json.JSONDecodeError) as exc:
        raise ModelRuntimeError("model_load_failed") from exc


def _enabled(value: str, default: bool) -> bool:
    normalized = value.strip().lower()
    if normalized == "":
        return default
    return normalized not in {"0", "false", "no", "off"}


def _device_order(environment: Mapping[str, str], torch_module: Any) -> list[str]:
    requested = environment.get("BIREFNET_DEVICE", "auto").strip().lower()
    if requested not in {"auto", "cuda", "cpu"}:
        raise ModelRuntimeError("model_load_failed")
    use_gpu = _enabled(environment.get("BIREFNET_USE_GPU", "1"), True)
    fallback = _enabled(environment.get("BIREFNET_CPU_FALLBACK", "1"), True)
    if not use_gpu or requested == "cpu":
        return ["cpu"]
    cuda_available = bool(torch_module.cuda.is_available())
    if requested == "cuda" and not cuda_available:
        if fallback:
            return ["cpu"]
        raise ModelRuntimeError("model_load_failed")
    if cuda_available:
        return ["cuda", "cpu"] if fallback else ["cuda"]
    return ["cpu"]


def _release_cuda(torch_module: Any) -> None:
    gc.collect()
    empty_cache = getattr(getattr(torch_module, "cuda", None), "empty_cache", None)
    if callable(empty_cache):
        empty_cache()


def load_model(
    *,
    model_root: Path | None = None,
    torch_module: Any | None = None,
    model_factory: Callable[..., Any] | None = None,
    environment: Mapping[str, str] | None = None,
) -> tuple[Any, str]:
    global _MODEL_STATE
    with _MODEL_LOCK:
        if _MODEL_STATE is not None:
            return _MODEL_STATE

        values = os.environ if environment is None else environment
        root = Path(values.get("BIREFNET_MODEL_DIR", str(DEFAULT_MODEL_ROOT))) if model_root is None else Path(model_root)
        verify_ready(root)
        if torch_module is None:
            import torch as torch_module
        if model_factory is None:
            from transformers import AutoModelForImageSegmentation

            model_factory = AutoModelForImageSegmentation.from_pretrained

        devices = _device_order(values, torch_module)
        snapshot = str(root / "snapshot")
        for index, device in enumerate(devices):
            candidate = None
            try:
                candidate = model_factory(snapshot, trust_remote_code=True, local_files_only=True)
                candidate = candidate.to(device)
                candidate.eval()
                if device == "cuda":
                    candidate.half()
                _MODEL_STATE = (candidate, device)
                return _MODEL_STATE
            except Exception as exc:
                candidate = None
                if device == "cuda" and index + 1 < len(devices):
                    _release_cuda(torch_module)
                    continue
                raise ModelRuntimeError("model_load_failed") from exc
        raise ModelRuntimeError("model_load_failed")


def reset_model() -> None:
    global _MODEL_STATE
    with _MODEL_LOCK:
        _MODEL_STATE = None


def model_health(environment: Mapping[str, str] | None = None) -> dict[str, Any]:
    values = os.environ if environment is None else environment
    root = Path(values.get("BIREFNET_MODEL_DIR", str(DEFAULT_MODEL_ROOT)))
    error = ""
    revision = ""
    try:
        revision = str(verify_ready(root)["revision"])
    except ModelRuntimeError as exc:
        error = exc.code
    with _MODEL_LOCK:
        effective = _MODEL_STATE[1] if _MODEL_STATE is not None else None
    result: dict[str, Any] = {
        "model_present": error == "",
        "model_revision": revision or None,
        "requested_device": values.get("BIREFNET_DEVICE", "auto"),
        "effective_device": effective,
        "storage": {
            "models": values.get("BIREFNET_MODEL_DIR", str(DEFAULT_MODEL_ROOT)),
            "cache": values.get("BIREFNET_CACHE_DIR", "/cache/birefnet"),
            "service_data": values.get("BIREFNET_SERVICE_DATA_DIR", "/data/service"),
        },
    }
    if error:
        result["error"] = error
    return result
