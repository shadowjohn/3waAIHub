from __future__ import annotations

import base64
import binascii
import io
import os
import re
import shutil
import tempfile
import warnings
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from typing import Iterator

from PIL import Image, ImageOps, UnidentifiedImageError


MAX_SOURCE_BYTES = 50 * 1024 * 1024
MAX_BASE64_CHARS = 70 * 1024 * 1024
MAX_AXIS = 8_192
MAX_SYNC_PIXELS = 4_000_000
MAX_ASYNC_PIXELS = 10_000_000
MAX_OUTPUT_PIXELS = 64_000_000
ALLOWED_FORMATS = frozenset({"JPEG", "PNG", "WEBP", "BMP"})
_DATA_URI = re.compile(r"\Adata:image/(?:jpeg|png|webp|bmp);base64,")
_BASE64 = re.compile(r"\A[A-Za-z0-9+/=\t-\r ]+\Z")
_BASE64_COMPLETE = re.compile(r"\A(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?\Z")


class ImageToolsError(ValueError):
    def __init__(self, code: str) -> None:
        self.code = code
        super().__init__(code)


@dataclass(frozen=True)
class ModelSelection:
    alias: str
    filename: str
    scale: int


_MODELS = {
    "realesrgan-x4plus": ModelSelection("realesrgan-x4plus", "RealESRGAN_x4plus.pth", 4),
    "realesrgan-x4plus-anime": ModelSelection("realesrgan-x4plus-anime", "RealESRGAN_x4plus_anime_6B.pth", 4),
    "realesr-animevideov3-x2": ModelSelection("realesr-animevideov3-x2", "realesr-animevideov3.pth", 2),
    "realesr-animevideov3-x3": ModelSelection("realesr-animevideov3-x3", "realesr-animevideov3.pth", 3),
    "realesr-animevideov3-x4": ModelSelection("realesr-animevideov3-x4", "realesr-animevideov3.pth", 4),
}


def decode_base64(source: str) -> bytes:
    if not isinstance(source, str):
        raise ImageToolsError("invalid_base64")
    if len(source) > MAX_BASE64_CHARS:
        raise ImageToolsError("invalid_base64")
    if source.startswith("data:"):
        match = _DATA_URI.match(source)
        if match is None:
            raise ImageToolsError("invalid_base64")
        source = source[match.end():]
    if not source or _BASE64.fullmatch(source) is None:
        raise ImageToolsError("invalid_base64")
    encoded = re.sub(r"[\t-\r ]+", "", source)
    max_encoded = 4 * ((MAX_SOURCE_BYTES + 2) // 3)
    if len(encoded) > max_encoded or _BASE64_COMPLETE.fullmatch(encoded) is None:
        raise ImageToolsError("invalid_base64")
    try:
        decoded = base64.b64decode(encoded, validate=True)
    except (binascii.Error, ValueError) as exc:
        raise ImageToolsError("invalid_base64") from exc
    if len(decoded) > MAX_SOURCE_BYTES:
        raise ImageToolsError("invalid_base64")
    return decoded


def _source_limit(operation: str) -> int:
    if operation == "upscale":
        return MAX_SYNC_PIXELS
    if operation == "upscale_task":
        return MAX_ASYNC_PIXELS
    raise ImageToolsError("invalid_operation")


def decode_image(data: bytes, *, operation: str) -> Image.Image:
    if not isinstance(data, bytes) or not data:
        raise ImageToolsError("invalid_image")
    if len(data) > MAX_SOURCE_BYTES:
        raise ImageToolsError("payload_too_large")
    max_pixels = _source_limit(operation)
    try:
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            with Image.open(io.BytesIO(data)) as probe:
                image_format = (probe.format or "").upper()
                if image_format not in ALLOWED_FORMATS:
                    raise ImageToolsError("unsupported_media_type")
                _validate_dimensions(*probe.size, max_pixels=max_pixels)
                probe.verify()
            with Image.open(io.BytesIO(data)) as opened:
                opened.load()
                image = ImageOps.exif_transpose(opened).copy()
    except ImageToolsError:
        raise
    except (Image.DecompressionBombError, Image.DecompressionBombWarning, UnidentifiedImageError, OSError, SyntaxError, ValueError) as exc:
        raise ImageToolsError("invalid_image") from exc
    _validate_dimensions(*image.size, max_pixels=max_pixels)
    return image.convert("RGB")


def _validate_dimensions(width: int, height: int, *, max_pixels: int) -> None:
    if width < 1 or height < 1 or width > MAX_AXIS or height > MAX_AXIS or width * height > max_pixels:
        raise ImageToolsError("invalid_image")


def select_model(alias: str) -> ModelSelection:
    model = _MODELS.get(alias)
    if model is None:
        raise ImageToolsError("invalid_model")
    return model


def resolve_backend(requested: str, *, cuda_available: bool) -> str:
    if requested == "cpu":
        return "cpu"
    if requested == "cuda":
        if not cuda_available:
            raise ImageToolsError("backend_unavailable")
        return "cuda"
    if requested == "auto":
        return "cuda" if cuda_available else "cpu"
    raise ImageToolsError("invalid_backend")


def validate_output_pixels(source_pixels: int, scale: int) -> None:
    if not isinstance(source_pixels, int) or isinstance(source_pixels, bool) or source_pixels < 1 or scale not in {2, 3, 4}:
        raise ImageToolsError("invalid_image")
    if source_pixels * scale * scale > MAX_OUTPUT_PIXELS:
        raise ImageToolsError("invalid_image")


def _workspace_path(workspace: Path, candidate: Path, *, must_exist: bool) -> Path:
    try:
        root = workspace.resolve(strict=True)
        path = candidate.resolve(strict=must_exist)
        path.relative_to(root)
    except (OSError, ValueError) as exc:
        raise ImageToolsError("invalid_request") from exc
    return path


def build_upscale_argv(
    *,
    workspace: Path,
    source: Path,
    output: Path,
    model: str,
    backend: str,
    model_dir: Path,
) -> list[str]:
    source_path = _workspace_path(workspace, source, must_exist=True)
    output_path = _workspace_path(workspace, output, must_exist=False)
    if not source_path.is_file() or backend not in {"cuda", "cpu"}:
        raise ImageToolsError("invalid_request")
    select_model(model)
    return [
        "python3", "/app/upscale_runner.py", "--input", str(source_path), "--output", str(output_path),
        "--model", model, "--backend", backend, "--model-dir", str(model_dir),
    ]


@contextmanager
def private_job_directory() -> Iterator[Path]:
    path = Path(tempfile.mkdtemp(prefix="image-tools-"))
    os.chmod(path, 0o700)
    try:
        yield path
    finally:
        shutil.rmtree(path, ignore_errors=True)
