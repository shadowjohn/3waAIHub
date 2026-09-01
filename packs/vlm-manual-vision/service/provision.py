from __future__ import annotations

import hashlib
import json
import os
import re
import shutil
import stat
import tempfile
from contextlib import contextmanager
from dataclasses import dataclass
from pathlib import Path
from typing import Callable, Mapping

import fcntl


MODEL_ID = "google/paligemma-3b-ft-docvqa-448"
MARKER_NAME = "verified-snapshot.json"
REVISIONS_DIR = "revisions"
ALLOW_PATTERNS = ("*.json", "*.model", "*.safetensors", "*.txt")
RUNTIME_FILES = frozenset({
    "added_tokens.json",
    "config.json",
    "generation_config.json",
    "model.safetensors.index.json",
    "preprocessor_config.json",
    "special_tokens_map.json",
    "tokenizer.json",
    "tokenizer.model",
    "tokenizer_config.json",
})


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
    ):
        raise ValueError("invalid private provisioning settings")
    return settings


def _require_provision_token(settings: ProvisionSettings) -> None:
    if not settings.token:
        raise ValueError("missing private provisioning token")


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
    if not RUNTIME_FILES.issubset(names):
        raise ValueError("incomplete snapshot")
    try:
        index = json.loads((snapshot / "model.safetensors.index.json").read_text(encoding="utf-8"))
        weights = index["weight_map"]
    except (OSError, ValueError, TypeError, KeyError, json.JSONDecodeError) as exc:
        raise ValueError("invalid safetensors index") from exc
    if not isinstance(weights, dict) or not weights:
        raise ValueError("invalid safetensors index")
    shards: set[str] = set()
    for parameter, shard in weights.items():
        if (
            not isinstance(parameter, str)
            or not parameter
            or not isinstance(shard, str)
            or not shard
            or shard.startswith("/")
            or "\\" in shard
            or "/" in shard
            or ".." in Path(shard).parts
            or not shard.endswith(".safetensors")
        ):
            raise ValueError("invalid safetensors index")
        shards.add(shard)
    if shards != {name for name in names if name.endswith(".safetensors")}:
        raise ValueError("incomplete safetensors shards")
    return names


def manifest_for_snapshot(snapshot: Path, snapshot_name: str = "snapshot") -> dict[str, object]:
    return {
        "snapshot": snapshot_name,
        "files": [
            {"path": name, "sha256": _hash_file(snapshot / name)}
            for name in sorted(_snapshot_files(snapshot))
        ],
    }


def _snapshot_path(root: Path, name: object) -> Path:
    if not isinstance(name, str) or re.fullmatch(r"revisions/[a-f0-9]{40}/snapshot", name) is None:
        raise ValueError("invalid snapshot path")
    snapshot = root / name
    for current in (root / REVISIONS_DIR, snapshot.parent, snapshot):
        if current.is_symlink():
            raise ValueError("snapshot symlink")
    return snapshot


def _read_manifest(root: Path) -> tuple[Path, list[tuple[str, str]]]:
    marker = root / MARKER_NAME
    if root.is_symlink() or marker.is_symlink() or not marker.is_file():
        raise ValueError("verified marker missing")
    try:
        payload = json.loads(marker.read_text(encoding="utf-8"))
    except (OSError, ValueError, TypeError, json.JSONDecodeError) as exc:
        raise ValueError("invalid verified marker") from exc
    if not isinstance(payload, dict) or set(payload) != {"snapshot", "files"} or not isinstance(payload["files"], list):
        raise ValueError("invalid verified marker")
    snapshot = _snapshot_path(root, payload["snapshot"])
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
    return snapshot, rows


def verify_published_snapshot(root: Path) -> Path:
    snapshot, rows = _read_manifest(root)
    names = _snapshot_files(snapshot)
    if names != {path for path, _checksum in rows}:
        raise ValueError("incomplete verified marker")
    for path, checksum in rows:
        if _hash_file(snapshot / path) != checksum:
            raise ValueError("snapshot hash mismatch")
    return snapshot


def _write_marker(root: Path, manifest: Mapping[str, object]) -> None:
    descriptor, temporary = tempfile.mkstemp(prefix=f".{MARKER_NAME}.", dir=root)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            json.dump(manifest, handle, sort_keys=True, separators=(",", ":"))
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temporary, 0o644)
        os.replace(temporary, root / MARKER_NAME)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def write_verified_marker(root: Path, manifest: Mapping[str, object]) -> None:
    snapshot, rows = _read_manifest_payload(root, manifest)
    if _snapshot_files(snapshot) != {path for path, _checksum in rows}:
        raise ValueError("incomplete verified marker")
    if any(_hash_file(snapshot / path) != checksum for path, checksum in rows):
        raise ValueError("snapshot hash mismatch")
    _write_marker(root, manifest)


def _read_manifest_payload(root: Path, payload: Mapping[str, object]) -> tuple[Path, list[tuple[str, str]]]:
    if not isinstance(payload, dict) or set(payload) != {"snapshot", "files"} or not isinstance(payload["files"], list):
        raise ValueError("invalid verified marker")
    snapshot = _snapshot_path(root, payload["snapshot"])
    rows: list[tuple[str, str]] = []
    seen: set[str] = set()
    for row in payload["files"]:
        if not isinstance(row, dict) or set(row) != {"path", "sha256"}:
            raise ValueError("invalid verified marker")
        path, checksum = row["path"], row["sha256"]
        if (
            not isinstance(path, str) or not path or path.startswith("/") or "\\" in path
            or ".." in Path(path).parts or path in seen or not isinstance(checksum, str)
            or re.fullmatch(r"[a-f0-9]{64}", checksum) is None
        ):
            raise ValueError("invalid verified marker")
        seen.add(path)
        rows.append((path, checksum))
    return snapshot, rows


@contextmanager
def publisher_lock(root: Path):
    root.mkdir(parents=True, exist_ok=True)
    lock = root / ".publish.lock"
    with lock.open("a", encoding="utf-8") as handle:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(handle.fileno(), fcntl.LOCK_UN)


def _make_runtime_readable(revision: Path) -> None:
    for current, directories, files in os.walk(revision):
        directory = Path(current)
        if directory.is_symlink():
            raise ValueError("snapshot symlink")
        os.chown(directory, 10001, 10001)
        os.chmod(directory, 0o755)
        for name in directories:
            if (directory / name).is_symlink():
                raise ValueError("snapshot symlink")
        for name in files:
            candidate = directory / name
            if candidate.is_symlink() or not candidate.is_file():
                raise ValueError("snapshot file")
            os.chown(candidate, 10001, 10001)
            os.chmod(candidate, 0o644)


def provision_snapshot(
    environment: Mapping[str, str] | None = None,
    *,
    snapshot_download: Callable[..., object] | None = None,
) -> Path:
    settings = settings_from_environment(environment)
    _require_provision_token(settings)
    values = os.environ if environment is None else environment
    root = Path(values.get("MANUAL_VISION_MODEL_DIR", "/models/manual-vision"))
    if root.is_symlink():
        raise ValueError("model root symlink")
    root.mkdir(parents=True, exist_ok=True)
    with publisher_lock(root):
        revisions = root / REVISIONS_DIR
        revisions.mkdir(mode=0o755, exist_ok=True)
        if revisions.is_symlink():
            raise ValueError("revisions symlink")
        target = revisions / settings.revision
        if target.exists() or target.is_symlink():
            raise ValueError("revision already published")
        stage = Path(tempfile.mkdtemp(prefix=".stage-", dir=revisions))
        published = False
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
            relative = f"{REVISIONS_DIR}/{settings.revision}/snapshot"
            manifest = manifest_for_snapshot(stage / "snapshot", relative)
            _make_runtime_readable(stage)
            stage.rename(target)
            published = True
            write_verified_marker(root, manifest)
            return verify_published_snapshot(root)
        except Exception:
            if published and target.exists() and not target.is_symlink():
                shutil.rmtree(target)
            raise
        finally:
            if stage.exists():
                shutil.rmtree(stage)


if __name__ == "__main__":
    provision_snapshot()
