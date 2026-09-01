from __future__ import annotations

import hashlib
import json
import os
import re
import shutil
import stat
import tempfile
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Callable, Mapping


MODEL_ID = "google/paligemma-3b-ft-docvqa-448"
MARKER_NAME = "verified-snapshot.json"
ALLOW_PATTERNS = ("*.json", "*.model", "*.safetensors", "*.txt")


@dataclass(frozen=True)
class ProvisionSettings:
    model: str
    revision: str
    dtype: str
    device: str
    token: str


def settings_from_environment(environment: Mapping[str, str] | None = None) -> ProvisionSettings:
    values = os.environ if environment is None else environment
    settings = ProvisionSettings(
        values.get("MANUAL_VISION_MODEL", ""),
        values.get("MANUAL_VISION_MODEL_REVISION", ""),
        values.get("MANUAL_VISION_TORCH_DTYPE", ""),
        values.get("MANUAL_VISION_DEVICE", ""),
        values.get("HF_TOKEN", ""),
    )
    if (
        settings.model != MODEL_ID
        or re.fullmatch(r"[a-f0-9]{40}", settings.revision) is None
        or settings.dtype != "float16"
        or settings.device != "cuda"
        or not settings.token
    ):
        raise ValueError("invalid private provisioning settings")
    return settings


def _hash_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _snapshot_files(snapshot: Path) -> set[str]:
    if snapshot.is_symlink() or not snapshot.is_dir():
        raise ValueError("invalid snapshot")
    names: set[str] = set()
    for current, directories, files in os.walk(snapshot):
        directories.sort()
        if any((Path(current) / name).is_symlink() for name in directories):
            raise ValueError("snapshot symlink")
        for name in sorted(files):
            candidate = Path(current) / name
            info = candidate.stat(follow_symlinks=False)
            if candidate.is_symlink() or not stat.S_ISREG(info.st_mode):
                raise ValueError("snapshot file")
            names.add(candidate.relative_to(snapshot).as_posix())
    if not names or "config.json" not in names or not any(name.endswith(".safetensors") for name in names):
        raise ValueError("incomplete snapshot")
    return names


def manifest_for_snapshot(snapshot: Path) -> dict[str, object]:
    return {
        "snapshot": "snapshot",
        "files": [
            {"path": name, "sha256": _hash_file(snapshot / name)}
            for name in sorted(_snapshot_files(snapshot))
        ],
    }


def _read_manifest(root: Path) -> list[tuple[str, str]]:
    marker = root / MARKER_NAME
    if root.is_symlink() or marker.is_symlink() or not marker.is_file():
        raise ValueError("verified marker missing")
    try:
        payload = json.loads(marker.read_text(encoding="utf-8"))
    except (OSError, ValueError, TypeError, json.JSONDecodeError) as exc:
        raise ValueError("invalid verified marker") from exc
    if not isinstance(payload, dict) or set(payload) != {"snapshot", "files"} or payload["snapshot"] != "snapshot" or not isinstance(payload["files"], list):
        raise ValueError("invalid verified marker")
    rows: list[tuple[str, str]] = []
    seen: set[str] = set()
    for row in payload["files"]:
        if not isinstance(row, dict) or set(row) != {"path", "sha256"}:
            raise ValueError("invalid verified marker")
        path, checksum = row["path"], row["sha256"]
        if (
            not isinstance(path, str)
            or not path
            or path.startswith("/")
            or "\\" in path
            or ".." in Path(path).parts
            or path in seen
            or not isinstance(checksum, str)
            or re.fullmatch(r"[a-f0-9]{64}", checksum) is None
        ):
            raise ValueError("invalid verified marker")
        seen.add(path)
        rows.append((path, checksum))
    return rows


def verify_published_snapshot(root: Path) -> Path:
    snapshot = root / "snapshot"
    rows = _read_manifest(root)
    names = _snapshot_files(snapshot)
    if names != {path for path, _checksum in rows}:
        raise ValueError("incomplete verified marker")
    for path, checksum in rows:
        if _hash_file(snapshot / path) != checksum:
            raise ValueError("snapshot hash mismatch")
    return snapshot


def write_verified_marker(root: Path, manifest: Mapping[str, object]) -> None:
    marker = root / MARKER_NAME
    if marker.exists() or marker.is_symlink():
        marker.unlink()
    marker.write_text(json.dumps(manifest, sort_keys=True, separators=(",", ":")), encoding="utf-8")
    try:
        verify_published_snapshot(root)
    except Exception:
        marker.unlink(missing_ok=True)
        raise


def _remove_marker(root: Path) -> None:
    if root.is_symlink():
        return
    marker = root / MARKER_NAME
    if marker.exists() or marker.is_symlink():
        marker.unlink()


def _publish(stage: Path, root: Path) -> None:
    root.parent.mkdir(parents=True, exist_ok=True)
    backup = root.parent / f".{root.name}.previous-{uuid.uuid4().hex}"
    if root.exists() or root.is_symlink():
        if root.is_symlink():
            raise ValueError("model root symlink")
        root.rename(backup)
    try:
        stage.rename(root)
    except Exception:
        if backup.exists():
            backup.rename(root)
        raise
    if backup.exists():
        shutil.rmtree(backup)


def provision_snapshot(
    environment: Mapping[str, str] | None = None,
    *,
    snapshot_download: Callable[..., object] | None = None,
) -> Path:
    settings = settings_from_environment(environment)
    values = os.environ if environment is None else environment
    root = Path(values.get("MANUAL_VISION_MODEL_DIR", "/models/manual-vision"))
    if root.is_symlink():
        raise ValueError("model root symlink")
    root.parent.mkdir(parents=True, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix=f".{root.name}.stage-", dir=root.parent))
    try:
        if snapshot_download is None:
            from huggingface_hub import snapshot_download as hub_snapshot_download

            snapshot_download = hub_snapshot_download
        snapshot_download(
            repo_id=settings.model,
            revision=settings.revision,
            token=settings.token,
            local_dir=str(stage / "snapshot"),
            local_dir_use_symlinks=False,
            allow_patterns=list(ALLOW_PATTERNS),
        )
        manifest = manifest_for_snapshot(stage / "snapshot")
        write_verified_marker(stage, manifest)
        _publish(stage, root)
        return verify_published_snapshot(root)
    except Exception:
        _remove_marker(root)
        raise
    finally:
        if stage.exists():
            shutil.rmtree(stage)


if __name__ == "__main__":
    provision_snapshot()
