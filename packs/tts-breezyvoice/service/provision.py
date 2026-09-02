#!/usr/bin/env python3
"""Provision an immutable BreezyVoice model snapshot outside image builds."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import tempfile
from pathlib import Path
from typing import Any, Callable


IMMUTABLE_REVISION = re.compile(r"^[0-9a-f]{40}$")
SHA256 = re.compile(r"^[0-9a-f]{64}$")
Downloader = Callable[[str, str, Path], None]


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for block in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def require_immutable_revision(revision: str) -> str:
    if not isinstance(revision, str) or revision in {"", "main", "latest"} or not IMMUTABLE_REVISION.fullmatch(revision):
        raise RuntimeError("model_revision_invalid")
    return revision


def require_model_id(model_id: str) -> str:
    if not isinstance(model_id, str) or not model_id.strip() or "\x00" in model_id:
        raise RuntimeError("model_id_invalid")
    return model_id


def prepare_destination(model_dir: Path) -> Path:
    if not model_dir.is_absolute() or model_dir.name in {"", ".", ".."}:
        raise RuntimeError("model_dir_invalid")
    lexical = Path(os.path.abspath(model_dir))
    lexical.parent.mkdir(parents=True, exist_ok=True)
    if lexical.parent.is_symlink() or lexical.parent.resolve(strict=True) != lexical.parent:
        raise RuntimeError("model_dir_invalid")
    if lexical.exists() and (lexical.is_symlink() or not lexical.is_dir()):
        raise RuntimeError("model_dir_invalid")
    return lexical


def _collect_regular_files(root: Path) -> list[Path]:
    if root.is_symlink() or not root.is_dir():
        raise RuntimeError("model_layout_invalid")

    files: list[Path] = []

    def walk(current: Path) -> None:
        with os.scandir(current) as entries:
            for entry in entries:
                path = Path(entry.path)
                if entry.is_symlink():
                    raise RuntimeError("model_layout_invalid")
                if entry.is_dir(follow_symlinks=False):
                    walk(path)
                elif entry.is_file(follow_symlinks=False):
                    files.append(path)
                else:
                    raise RuntimeError("model_layout_invalid")

    walk(root)
    return sorted(files, key=lambda path: path.relative_to(root).as_posix())


def build_manifest(stage: Path, model_id: str, revision: str) -> dict[str, Any]:
    reserved = stage / "model-manifest.json"
    if reserved.exists() or reserved.is_symlink():
        raise RuntimeError("model_layout_invalid")
    files = _collect_regular_files(stage)
    if not files:
        raise RuntimeError("model_layout_invalid")
    manifest_files: list[dict[str, Any]] = []
    for path in files:
        relative = path.relative_to(stage).as_posix()
        stat = path.stat(follow_symlinks=False)
        manifest_files.append({
            "path": relative,
            "sha256": sha256_file(path),
            "size_bytes": stat.st_size,
        })
    if any(not SHA256.fullmatch(item["sha256"]) for item in manifest_files):
        raise RuntimeError("model_layout_invalid")
    return {"model_id": model_id, "revision": revision, "files": manifest_files}


def write_manifest(stage: Path, manifest: dict[str, Any]) -> None:
    payload = json.dumps(manifest, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n"
    path = stage / "model-manifest.json"
    with path.open("x", encoding="utf-8", newline="\n") as stream:
        stream.write(payload)
        stream.flush()
        os.fsync(stream.fileno())


def default_downloader(model_id: str, revision: str, destination: Path) -> None:
    try:
        from huggingface_hub import snapshot_download
    except ImportError as error:
        raise RuntimeError("provision_dependency_missing") from error
    snapshot_download(
        repo_id=model_id,
        revision=revision,
        local_dir=str(destination),
        local_dir_use_symlinks=False,
    )


def install_stage(stage: Path, destination: Path) -> None:
    """Publish only a complete validated directory; never copy files into live content."""
    try:
        # tempfile 建立的 root 是 0700；WSL worker 必須可安全 traverse 已發布快照。
        os.chmod(stage, 0o755)
    except OSError as error:
        raise RuntimeError("model_publish_failed") from error
    backup: Path | None = None
    if destination.exists() or destination.is_symlink():
        if destination.is_symlink() or not destination.is_dir():
            raise RuntimeError("model_dir_invalid")
        backup = Path(tempfile.mkdtemp(prefix=f".{destination.name}.previous-", dir=destination.parent))
        backup.rmdir()
        os.replace(destination, backup)
    try:
        os.replace(stage, destination)
    except OSError as error:
        if backup is not None and backup.exists() and not destination.exists():
            os.replace(backup, destination)
        raise RuntimeError("model_publish_failed") from error
    if backup is not None:
        shutil.rmtree(backup)


def provision_models(
    model_dir: Path,
    model_id: str,
    revision: str,
    *,
    downloader: Downloader = default_downloader,
) -> dict[str, Any]:
    destination = prepare_destination(model_dir)
    safe_model_id = require_model_id(model_id)
    safe_revision = require_immutable_revision(revision)
    stage = Path(tempfile.mkdtemp(prefix=f".{destination.name}.stage-", dir=destination.parent))
    try:
        downloader(safe_model_id, safe_revision, stage)
        manifest = build_manifest(stage, safe_model_id, safe_revision)
        write_manifest(stage, manifest)
        install_stage(stage, destination)
        return manifest
    finally:
        if stage.exists():
            shutil.rmtree(stage, ignore_errors=True)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Provision one immutable BreezyVoice model revision")
    parser.add_argument("--model-dir", required=True)
    parser.add_argument("--model-id", required=True)
    parser.add_argument("--revision", required=True)
    args = parser.parse_args(argv)
    try:
        manifest = provision_models(Path(args.model_dir), args.model_id, args.revision)
    except RuntimeError as error:
        print(f"AIHUB_ERROR_CODE={error}")
        return 2
    print(json.dumps(manifest, ensure_ascii=False, sort_keys=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
