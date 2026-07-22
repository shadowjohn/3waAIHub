from __future__ import annotations

import hashlib
import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable


MODEL_REPOSITORY = "ZhengPeng7/BiRefNet"
MODEL_REVISION = "e2bf8e4460fc8fa32bba5ea4d94b3233d367b0e4"
DEFAULT_MODEL_ROOT = Path("/models/birefnet")


class ProvisionError(RuntimeError):
    pass


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _runtime_files(snapshot_dir: Path) -> list[dict[str, Any]]:
    snapshot_root = snapshot_dir.resolve(strict=True)
    rows: list[dict[str, Any]] = []
    for current_root, directories, filenames in os.walk(snapshot_dir):
        current = Path(current_root)
        directories[:] = sorted(name for name in directories if name not in {".cache", ".git"})
        for name in directories:
            candidate = current / name
            if candidate.is_symlink():
                try:
                    candidate.resolve(strict=True).relative_to(snapshot_root)
                except (OSError, ValueError) as exc:
                    raise ProvisionError("snapshot path escapes model root") from exc
        for name in sorted(filenames):
            candidate = current / name
            relative = candidate.relative_to(snapshot_dir)
            if any(part in {".cache", ".git"} for part in relative.parts):
                continue
            try:
                resolved = candidate.resolve(strict=True)
                resolved.relative_to(snapshot_root)
            except (OSError, ValueError) as exc:
                raise ProvisionError("snapshot path escapes model root") from exc
            if not resolved.is_file():
                continue
            rows.append({
                "path": relative.as_posix(),
                "size": resolved.stat().st_size,
                "sha256": _sha256(resolved),
            })
    return sorted(rows, key=lambda row: row["path"])


def _write_ready(path: Path, payload: dict[str, Any]) -> None:
    temporary = path.with_name(path.name + ".tmp")
    with temporary.open("w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temporary, path)
    directory_fd = os.open(path.parent, os.O_RDONLY)
    try:
        os.fsync(directory_fd)
    finally:
        os.close(directory_fd)


def provision(
    *,
    model_root: Path = DEFAULT_MODEL_ROOT,
    downloader: Callable[..., str] | None = None,
) -> dict[str, Any]:
    os.environ["HF_HUB_DISABLE_TELEMETRY"] = "1"
    model_root = Path(model_root)
    model_root.mkdir(parents=True, exist_ok=True)
    snapshot_dir = model_root / "snapshot"
    ready_path = model_root / "ready.json"
    temporary_ready = model_root / "ready.json.tmp"
    ready_path.unlink(missing_ok=True)
    temporary_ready.unlink(missing_ok=True)

    if downloader is None:
        from huggingface_hub import snapshot_download

        downloader = snapshot_download

    try:
        downloader(
            repo_id=MODEL_REPOSITORY,
            revision=MODEL_REVISION,
            local_dir=str(snapshot_dir),
        )
        files = _runtime_files(snapshot_dir)
        paths = {row["path"] for row in files}
        if "config.json" not in paths or not any(path.endswith(".safetensors") for path in paths):
            raise ProvisionError("incomplete snapshot")
        payload = {
            "repository": MODEL_REPOSITORY,
            "revision": MODEL_REVISION,
            "created_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
            "files": files,
        }
        _write_ready(ready_path, payload)
        model_root.chmod(0o755)
        for current_root, directories, _filenames in os.walk(snapshot_dir):
            Path(current_root).chmod(0o755)
            for name in directories:
                (Path(current_root) / name).chmod(0o755)
        for row in files:
            (snapshot_dir / row["path"]).chmod(0o644)
        ready_path.chmod(0o644)
        return payload
    except Exception:
        temporary_ready.unlink(missing_ok=True)
        raise


def main() -> None:
    payload = provision(model_root=Path(os.getenv("BIREFNET_MODEL_DIR", str(DEFAULT_MODEL_ROOT))))
    print(json.dumps({
        "ok": True,
        "repository": payload["repository"],
        "revision": payload["revision"],
        "files": len(payload["files"]),
        "total_bytes": sum(row["size"] for row in payload["files"]),
    }, sort_keys=True))


if __name__ == "__main__":
    main()
