from __future__ import annotations

import hashlib
import json
import stat
from pathlib import Path
from typing import Any

from image_contract import ImageToolsError, select_model


DEFAULT_MODEL_ROOT = Path("/models/image-tools/realesrgan")
REAL_ESRGAN_REPOSITORY = "https://github.com/xinntao/Real-ESRGAN"
REAL_ESRGAN_COMMIT = "a4abfb2979a7bbff3f69f58f58ae324608821e27"
MODEL_FILES = (
    "RealESRGAN_x4plus.pth",
    "RealESRGAN_x4plus_anime_6B.pth",
    "realesr-animevideov3.pth",
)
MODEL_URLS = {
    "RealESRGAN_x4plus.pth": "https://github.com/xinntao/Real-ESRGAN/releases/download/v0.2.5.0/RealESRGAN_x4plus.pth",
    "RealESRGAN_x4plus_anime_6B.pth": "https://github.com/xinntao/Real-ESRGAN/releases/download/v0.2.5.0/RealESRGAN_x4plus_anime_6B.pth",
    "realesr-animevideov3.pth": "https://github.com/xinntao/Real-ESRGAN/releases/download/v0.2.5.0/realesr-animevideov3.pth",
}


class ModelRuntimeError(RuntimeError):
    def __init__(self, code: str) -> None:
        self.code = code
        super().__init__(code)


def _hash_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def _invalid_marker() -> ModelRuntimeError:
    return ModelRuntimeError("model_load_failed")


def verify_ready(model_root: Path = DEFAULT_MODEL_ROOT) -> dict[str, Any]:
    root = Path(model_root)
    marker_path = root / "ready.json"
    try:
        if not root.is_dir() or root.is_symlink() or not marker_path.is_file() or marker_path.is_symlink():
            raise ModelRuntimeError("model_not_present")
        if marker_path.stat().st_size > 2 * 1024 * 1024:
            raise _invalid_marker()
        marker = json.loads(marker_path.read_text(encoding="utf-8"))
        if (
            not isinstance(marker, dict)
            or marker.get("repository") != REAL_ESRGAN_REPOSITORY
            or marker.get("commit") != REAL_ESRGAN_COMMIT
            or not isinstance(marker.get("files"), list)
        ):
            raise _invalid_marker()
        rows = marker["files"]
        if len(rows) != len(MODEL_FILES):
            raise _invalid_marker()
        listed: set[str] = set()
        for row in rows:
            if not isinstance(row, dict) or set(row) != {"path", "size", "sha256", "url"}:
                raise _invalid_marker()
            name, size, digest, url = row["path"], row["size"], row["sha256"], row["url"]
            if (
                not isinstance(name, str) or name not in MODEL_FILES or name in listed
                or not isinstance(size, int) or isinstance(size, bool) or size < 1
                or not isinstance(digest, str) or len(digest) != 64 or any(char not in "0123456789abcdef" for char in digest)
                or not isinstance(url, str) or url != MODEL_URLS[name]
            ):
                raise _invalid_marker()
            candidate = root / name
            if candidate.is_symlink() or not candidate.is_file() or not stat.S_ISREG(candidate.stat().st_mode):
                raise _invalid_marker()
            if candidate.stat().st_size != size or _hash_file(candidate) != digest:
                raise _invalid_marker()
            listed.add(name)
        if listed != set(MODEL_FILES):
            raise _invalid_marker()
        if {entry.name for entry in root.iterdir()} != {"ready.json", *MODEL_FILES}:
            raise _invalid_marker()
        return marker
    except ModelRuntimeError:
        raise
    except (OSError, ValueError, TypeError, json.JSONDecodeError) as exc:
        raise ModelRuntimeError("model_load_failed") from exc


def model_path_for_alias(alias: str, model_root: Path = DEFAULT_MODEL_ROOT) -> Path:
    try:
        selection = select_model(alias)
    except ImageToolsError as exc:
        raise ModelRuntimeError(exc.code) from exc
    verify_ready(model_root)
    return Path(model_root) / selection.filename


def prepare_model(model: Any, backend: str) -> Any:
    if backend not in {"cuda", "cpu"}:
        raise ModelRuntimeError("invalid_backend")
    try:
        prepared = model.to(backend)
        prepared.eval()
        if backend == "cuda":
            prepared.half()
        return prepared
    except Exception as exc:
        raise ModelRuntimeError("model_load_failed") from exc
