#!/usr/bin/env python3
"""Trusted administrator provisioning for the fixed Paraformer model archive."""

from __future__ import annotations

import argparse
import ctypes
import hashlib
import json
import os
import shutil
import tarfile
import tempfile
import urllib.request
from pathlib import Path
from typing import Any, Callable


ARCHIVE_URL = "https://github.com/k2-fsa/sherpa-onnx/releases/download/asr-models/sherpa-onnx-paraformer-zh-small-2024-03-09.tar.bz2"
ARCHIVE_SHA256 = "da92b3db5218c5be53aad53e57d1b6e63e7fc98a0e054fbdd6dbe18e9c6b1450"
ARCHIVE_ROOT = "sherpa-onnx-paraformer-zh-small-2024-03-09"
MODEL = "sherpa-onnx-paraformer-zh-small-2024-03-09"
MARKER_NAME = ".aihub-speech-fast-zh-ready.json"
MODEL_HASHES = {
    "model.int8.onnx": "3ef6c19369b912f7caf3cef8e545c5ccd1a33d9d7ec792a46668dc41c4b229ec",
    "tokens.txt": "4b2d964e18b9cf139b473003b6698fb2ed9a2a5ec55b93daa677b28f578897aa",
}
_ARCHIVE_FILES = {
    "README.md",
    "tokens.txt",
    "add-model-metadata.py",
    "config.yaml",
    "generate-tokens.py",
    "test_wavs/2-zh-en.wav",
    "test_wavs/5-henan.wav",
    "test_wavs/8k.wav",
    "test_wavs/0.wav",
    "test_wavs/1.wav",
    "test_wavs/4-tianjin.wav",
    "test_wavs/3-sichuan.wav",
    "am.mvn",
    "model.int8.onnx",
}
_ARCHIVE_DIRECTORIES = {"", "test_wavs/"}
EXPECTED_ARCHIVE_MEMBERS = frozenset(
    {f"{ARCHIVE_ROOT}/{name}" for name in _ARCHIVE_FILES}
    | {f"{ARCHIVE_ROOT}/{name}" for name in _ARCHIVE_DIRECTORIES}
)


def require_regular_file(path: Path, error_code: str) -> None:
    if not path.is_file() or path.is_symlink():
        raise RuntimeError(error_code)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def ready_marker() -> dict[str, str]:
    return {
        "schema": "aihub-speech-fast-zh/v1",
        "model": MODEL,
        "model_sha256": MODEL_HASHES["model.int8.onnx"],
        "tokens_sha256": MODEL_HASHES["tokens.txt"],
    }


def ensure_model_parent(model_root: Path) -> Path:
    if not model_root.is_absolute():
        raise RuntimeError("storage_root_invalid")
    if model_root.is_symlink() or (model_root.exists() and not model_root.is_dir()):
        raise RuntimeError("storage_root_invalid")
    parent = model_root.parent
    current = Path(parent.anchor)
    for part in parent.parts[1:]:
        current /= part
        if current.is_symlink() or not current.is_dir():
            raise RuntimeError("storage_root_invalid")
    return parent


def require_local_archive(path: Path) -> Path:
    if not path.is_absolute() or "\x00" in str(path):
        raise RuntimeError("archive_invalid")
    require_regular_file(path, "archive_invalid")
    return path


def validate_archive_members(archive: tarfile.TarFile) -> dict[str, tarfile.TarInfo]:
    members: dict[str, tarfile.TarInfo] = {}
    for member in archive.getmembers():
        name = member.name.rstrip("/") + "/" if member.isdir() else member.name
        if name in members or name not in EXPECTED_ARCHIVE_MEMBERS:
            raise RuntimeError("archive_layout_invalid")
        if name.endswith("/"):
            if not member.isdir():
                raise RuntimeError("archive_layout_invalid")
        elif not member.isreg():
            raise RuntimeError("archive_layout_invalid")
        members[name] = member
    if set(members) != EXPECTED_ARCHIVE_MEMBERS:
        raise RuntimeError("archive_layout_invalid")
    return members


def verify_staged_files(directory: Path) -> None:
    for name, expected_hash in MODEL_HASHES.items():
        path = directory / name
        require_regular_file(path, "model_validation_failed")
        if sha256_file(path) != expected_hash:
            raise RuntimeError("model_validation_failed")


def read_marker(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise RuntimeError("model_validation_failed") from error
    if not isinstance(value, dict) or value != ready_marker():
        raise RuntimeError("model_validation_failed")
    return value


def verify_ready_model(directory: Path) -> None:
    verify_staged_files(directory)
    marker = directory / MARKER_NAME
    require_regular_file(marker, "model_validation_failed")
    read_marker(marker)


def make_model_readable(directory: Path) -> None:
    os.chmod(directory, 0o755)
    for name in (*MODEL_HASHES, MARKER_NAME):
        path = directory / name
        require_regular_file(path, "model_validation_failed")
        os.chmod(path, 0o644)


def write_atomic(path: Path, content: bytes) -> None:
    descriptor, temporary = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        os.fchmod(descriptor, 0o644)
        with os.fdopen(descriptor, "wb") as stream:
            stream.write(content)
        Path(temporary).replace(path)
    except BaseException:
        Path(temporary).unlink(missing_ok=True)
        raise


def extract_model_files(archive_path: Path, stage: Path) -> None:
    try:
        with tarfile.open(archive_path, "r:bz2") as archive:
            members = validate_archive_members(archive)
            for name in MODEL_HASHES:
                source = archive.extractfile(members[f"{ARCHIVE_ROOT}/{name}"])
                if source is None:
                    raise RuntimeError("archive_layout_invalid")
                with source, (stage / name).open("wb") as destination:
                    shutil.copyfileobj(source, destination)
    except (OSError, EOFError, tarfile.TarError) as error:
        raise RuntimeError("archive_invalid") from error


def download_archive(destination: Path) -> None:
    try:
        urllib.request.urlretrieve(ARCHIVE_URL, destination)
    except OSError as error:
        raise RuntimeError("archive_download_failed") from error


def rename_exchange(left: Path, right: Path) -> None:
    try:
        operation = ctypes.CDLL(None, use_errno=True).renameat2
    except AttributeError as error:
        raise RuntimeError("atomic_publish_unavailable") from error
    operation.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    operation.restype = ctypes.c_int
    if operation(-100, os.fsencode(left), -100, os.fsencode(right), 2) != 0:
        error_number = ctypes.get_errno()
        raise OSError(error_number, os.strerror(error_number))


def publish_model_directory(stage: Path, model_root: Path) -> None:
    if model_root.is_symlink() or (model_root.exists() and not model_root.is_dir()):
        raise RuntimeError("storage_root_invalid")
    if model_root.exists():
        rename_exchange(stage, model_root)
        shutil.rmtree(stage, ignore_errors=True)
    else:
        stage.replace(model_root)


def provision(
    model_root: Path,
    archive: Path | None = None,
    *,
    downloader: Callable[[Path], None] = download_archive,
) -> dict[str, Any]:
    parent = ensure_model_parent(model_root)
    stage = Path(tempfile.mkdtemp(prefix=f".{model_root.name}.stage-", dir=parent))
    try:
        archive_path = require_local_archive(archive) if archive is not None else stage / "archive.tar.bz2"
        if archive is None:
            downloader(archive_path)
            require_regular_file(archive_path, "archive_download_failed")
        if sha256_file(archive_path) != ARCHIVE_SHA256:
            raise RuntimeError("archive_hash_invalid")
        extract_model_files(archive_path, stage)
        verify_staged_files(stage)
        marker = ready_marker()
        write_atomic(stage / MARKER_NAME, (json.dumps(marker, sort_keys=True) + "\n").encode("utf-8"))
        verify_ready_model(stage)
        make_model_readable(stage)
        verify_ready_model(stage)
        publish_model_directory(stage, model_root)
        verify_ready_model(model_root)
        return marker
    finally:
        shutil.rmtree(stage, ignore_errors=True)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--model-dir", default="/models/paraformer")
    parser.add_argument("--archive")
    args = parser.parse_args()
    provision(Path(args.model_dir), Path(args.archive) if args.archive else None)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except RuntimeError as error:
        print(f"speech_fast_zh_provision_failed:{error}", file=os.sys.stderr)
        raise SystemExit(1)
