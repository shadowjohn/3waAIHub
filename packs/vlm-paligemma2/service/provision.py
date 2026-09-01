from __future__ import annotations

import hashlib
import json
import os
import re
import shutil
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


MODEL_ID = "google/paligemma2-3b-pt-224"
MODEL_REVISION = "96eeb174da13ca1a2b247e4d0867436296c36420"
MANIFEST_NAME = "provision-manifest.json"
REQUIRED_FILES = {"config.json", "model.safetensors.index.json", "preprocessor_config.json"}


def configured_model() -> tuple[str, str]:
    model = os.getenv("PALIGEMMA2_MODEL", MODEL_ID).strip()
    revision = os.getenv("PALIGEMMA2_MODEL_REVISION", MODEL_REVISION).strip()
    if model != MODEL_ID or revision != MODEL_REVISION:
        raise RuntimeError("model_reference_not_allowed")
    return model, revision


def model_root() -> Path:
    root = Path(os.getenv("PALIGEMMA2_MODEL_DIR", "/models/paligemma2"))
    if root.is_symlink():
        raise RuntimeError("unsafe_model_root")
    return root


def snapshot_root() -> Path:
    return model_root() / "snapshot"


def _regular_snapshot_files(root: Path) -> list[Path]:
    if not root.is_dir() or root.is_symlink():
        raise RuntimeError("model_not_provisioned")
    files: list[Path] = []
    for candidate in sorted(root.rglob("*")):
        relative = candidate.relative_to(root)
        if relative.parts and relative.parts[0] == ".cache":
            continue
        if candidate.is_symlink():
            raise RuntimeError("model_manifest_invalid")
        if candidate.is_file():
            files.append(candidate)
        elif not candidate.is_dir():
            raise RuntimeError("model_manifest_invalid")
    return files


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _file_manifest(root: Path) -> list[dict[str, Any]]:
    return [
        {
            "path": file.relative_to(root).as_posix(),
            "size_bytes": file.stat().st_size,
            "sha256": _sha256(file),
        }
        for file in _regular_snapshot_files(root)
        if file.name != MANIFEST_NAME
    ]


def _validate_model_files(root: Path, files: list[dict[str, Any]]) -> None:
    names = {str(item["path"]) for item in files}
    if not REQUIRED_FILES.issubset(names):
        raise RuntimeError("model_manifest_invalid")
    if not any(name.endswith(".safetensors") for name in names):
        raise RuntimeError("model_manifest_invalid")
    if not any(name in names for name in {"tokenizer.json", "tokenizer.model"}):
        raise RuntimeError("model_manifest_invalid")


def _atomic_json(path: Path, value: dict[str, Any]) -> None:
    if path.is_symlink():
        raise RuntimeError("model_manifest_invalid")
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=path.parent, delete=False) as handle:
        json.dump(value, handle, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
        handle.write("\n")
        temporary = Path(handle.name)
    os.chmod(temporary, 0o600)
    temporary.replace(path)


def verify_snapshot() -> dict[str, Any]:
    model, revision = configured_model()
    root = snapshot_root()
    manifest_path = root / MANIFEST_NAME
    if not manifest_path.is_file() or manifest_path.is_symlink():
        raise RuntimeError("model_not_provisioned")
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise RuntimeError("model_manifest_invalid") from exc
    if manifest.get("model") != model or manifest.get("revision") != revision:
        raise RuntimeError("model_manifest_invalid")
    expected = manifest.get("files")
    if not isinstance(expected, list) or not expected:
        raise RuntimeError("model_manifest_invalid")
    actual = _file_manifest(root)
    if actual != expected:
        raise RuntimeError("model_manifest_invalid")
    _validate_model_files(root, actual)
    return {
        "model": model,
        "revision": revision,
        "snapshot": str(root),
        "file_count": len(actual),
        "size_bytes": sum(int(item["size_bytes"]) for item in actual),
    }


def provision() -> dict[str, Any]:
    model, revision = configured_model()
    credential = os.getenv("HF_TOKEN", "").strip()
    if not credential:
        raise RuntimeError("license_token_missing")

    root = model_root()
    root.mkdir(parents=True, exist_ok=True)
    if root.is_symlink():
        raise RuntimeError("unsafe_model_root")
    target = snapshot_root()
    if target.exists() or target.is_symlink():
        return verify_snapshot()

    cache = Path(os.getenv("PALIGEMMA2_CACHE_DIR", "/cache/paligemma2")) / "huggingface"
    if cache.is_symlink():
        raise RuntimeError("unsafe_cache_root")
    cache.mkdir(parents=True, exist_ok=True)
    stage = Path(tempfile.mkdtemp(prefix=".provision-", dir=root))
    try:
        from huggingface_hub import snapshot_download

        snapshot_download(
            repo_id=model,
            revision=revision,
            token=credential,
            local_dir=stage,
            cache_dir=cache,
        )
        files = _file_manifest(stage)
        _validate_model_files(stage, files)
        manifest = {
            "model": model,
            "revision": revision,
            "created_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
            "files": files,
        }
        _atomic_json(stage / MANIFEST_NAME, manifest)
        stage.replace(target)
    except Exception:
        shutil.rmtree(stage, ignore_errors=True)
        raise
    return verify_snapshot()


def main() -> int:
    try:
        result = provision()
        print(json.dumps({"ok": True, **result}, ensure_ascii=False, sort_keys=True))
        return 0
    except RuntimeError as exc:
        print(json.dumps({"ok": False, "error": str(exc)}, ensure_ascii=False, sort_keys=True), file=sys.stderr)
        return 2
    except Exception:
        # 不輸出 exception；下載端可能把 request metadata 帶入文字，credential 不能進 worker log。
        print(json.dumps({"ok": False, "error": "provision_failed"}, ensure_ascii=False, sort_keys=True), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
