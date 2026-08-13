from __future__ import annotations

import hashlib
import json
import os
import stat
import sys
from pathlib import Path
from typing import Any

import numpy as np
from PIL import Image


DEFAULT_COLORIZE_MODEL_ROOT = Path("/models/image-tools/ddcolor")
DDCOLOR_REPOSITORY = "https://github.com/piddnad/DDColor"
DDCOLOR_COMMIT = "2adb63f2656ac41cbdf7b894cddd94121a3faf13"
DDCOLOR_MODEL_REPOSITORY = "https://huggingface.co/piddnad/DDColor-models"
DDCOLOR_MODEL_REVISION = "e9e7b527709c8aeb2f5e1bf701e72fd468a13baa"
DDCOLOR_MODEL_ASSET = {
    "path": "ddcolor_modelscope.pth",
    "size": 911950059,
    "sha256": "17c460d7e55b32a598370621d77173be59e03c24b0823f06821db23a50c263ce",
    "url": "https://huggingface.co/piddnad/DDColor-models/resolve/e9e7b527709c8aeb2f5e1bf701e72fd468a13baa/ddcolor_modelscope.pth",
}


class ColorizeRuntimeError(RuntimeError):
    def __init__(self, code: str) -> None:
        self.code = code
        super().__init__(code)


def _hash_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def ready_marker() -> dict[str, object]:
    return {
        "repository": DDCOLOR_REPOSITORY,
        "commit": DDCOLOR_COMMIT,
        "model_repository": DDCOLOR_MODEL_REPOSITORY,
        "model_revision": DDCOLOR_MODEL_REVISION,
        "files": [DDCOLOR_MODEL_ASSET],
    }


def verify_ready(model_root: Path = DEFAULT_COLORIZE_MODEL_ROOT) -> dict[str, Any]:
    root = Path(model_root)
    marker_path = root / "ready.json"
    try:
        if not root.is_dir() or root.is_symlink() or not marker_path.is_file() or marker_path.is_symlink():
            raise ColorizeRuntimeError("model_not_present")
        if marker_path.stat().st_size > 2 * 1024 * 1024:
            raise ColorizeRuntimeError("model_load_failed")
        marker = json.loads(marker_path.read_text(encoding="utf-8"))
        if marker != ready_marker():
            raise ColorizeRuntimeError("model_load_failed")
        asset = root / str(DDCOLOR_MODEL_ASSET["path"])
        if asset.is_symlink() or not asset.is_file() or not stat.S_ISREG(asset.stat().st_mode):
            raise ColorizeRuntimeError("model_load_failed")
        if asset.stat().st_size != DDCOLOR_MODEL_ASSET["size"] or _hash_file(asset) != DDCOLOR_MODEL_ASSET["sha256"]:
            raise ColorizeRuntimeError("model_load_failed")
        if {entry.name for entry in root.iterdir()} != {"ready.json", str(DDCOLOR_MODEL_ASSET["path"])}:
            raise ColorizeRuntimeError("model_load_failed")
        return marker
    except ColorizeRuntimeError:
        raise
    except (OSError, ValueError, TypeError, json.JSONDecodeError) as exc:
        raise ColorizeRuntimeError("model_load_failed") from exc


def build_colorizer(backend: str, model_root: Path = DEFAULT_COLORIZE_MODEL_ROOT) -> Any:
    if backend not in {"cpu", "cuda"}:
        raise ColorizeRuntimeError("invalid_backend")
    root = Path(model_root)
    verify_ready(root)
    source_root = Path(os.getenv("DDCOLOR_SOURCE_DIR", "/opt/ddcolor"))
    if source_root.is_symlink() or not (source_root / "ddcolor" / "__init__.py").is_file():
        raise ColorizeRuntimeError("model_load_failed")
    try:
        import torch

        source = str(source_root)
        if source not in sys.path:
            sys.path.insert(0, source)
        from ddcolor import ColorizationPipeline, DDColor, build_ddcolor_model

        device = torch.device(backend)
        model = build_ddcolor_model(
            DDColor,
            model_path=str(root / str(DDCOLOR_MODEL_ASSET["path"])),
            input_size=512,
            model_size="large",
            device=device,
        )
        return ColorizationPipeline(model, input_size=512, device=device)
    except Exception as exc:
        raise ColorizeRuntimeError("model_load_failed") from exc


def colorize(colorizer: Any, source: Image.Image) -> Image.Image:
    try:
        rgb = np.asarray(source.convert("RGB"))
        output = colorizer.process(rgb[:, :, ::-1].copy())
        if not isinstance(output, np.ndarray) or output.dtype != np.uint8 or output.shape != rgb.shape:
            raise ValueError
        return Image.fromarray(output[:, :, ::-1].copy(), "RGB")
    except Exception as exc:
        raise ColorizeRuntimeError("inference_failed") from exc
