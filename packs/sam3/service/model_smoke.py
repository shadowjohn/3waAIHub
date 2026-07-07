from __future__ import annotations

import json
import os
import sys
from pathlib import Path
from typing import Any


MODEL_EXTENSIONS = {".pt", ".pth", ".safetensors", ".ckpt"}


def is_path_under(path: Path, root: Path) -> bool:
    try:
        path.resolve(strict=False).relative_to(root.resolve(strict=False))
        return True
    except ValueError:
        return False


def safe_checkpoint_path(raw: str, model_root: Path) -> tuple[Path | None, str]:
    value = raw.strip()
    if value == "":
        return None, "scan"
    if "\x00" in value:
        return None, "invalid_checkpoint_path"

    candidate = Path(value)
    if not candidate.is_absolute():
        candidate = model_root / candidate
    if not is_path_under(candidate, model_root):
        return None, "invalid_checkpoint_path"

    return candidate, "SAM3_CHECKPOINT"


def is_safe_model_file(path: Path, model_root: Path) -> bool:
    if path.suffix.lower() not in MODEL_EXTENSIONS or path.is_dir():
        return False
    try:
        resolved = path.resolve(strict=True)
        resolved.relative_to(model_root.resolve(strict=True))
    except (OSError, ValueError):
        return False

    return path.is_file()


def model_candidates(model_root: Path) -> list[Path]:
    candidates: list[Path] = []
    if not model_root.is_dir():
        return candidates

    for current_root, dirs, files in os.walk(model_root):
        root_path = Path(current_root)
        dirs[:] = [name for name in dirs if not (root_path / name).is_symlink()]
        for filename in files:
            candidate = root_path / filename
            if is_safe_model_file(candidate, model_root):
                candidates.append(candidate)

    return sorted(candidates, key=lambda path: str(path))


def model_status() -> dict[str, Any]:
    model_root = Path(os.getenv("SAM3_MODEL_DIR", "/models/sam3"))
    checkpoint, source = safe_checkpoint_path(os.getenv("SAM3_CHECKPOINT", ""), model_root)
    candidates = model_candidates(model_root)
    error = ""

    if source == "invalid_checkpoint_path":
        error = "invalid_checkpoint_path"
        checkpoint = None
    elif checkpoint is not None and is_safe_model_file(checkpoint, model_root):
        return {
            "ok": True,
            "model_dir": str(model_root),
            "present": True,
            "checkpoint": str(checkpoint.resolve(strict=True)),
            "source": source,
            "candidates_count": len(candidates),
        }
    elif checkpoint is None and candidates:
        return {
            "ok": True,
            "model_dir": str(model_root),
            "present": True,
            "checkpoint": str(candidates[0].resolve(strict=True)),
            "source": "scan",
            "candidates_count": len(candidates),
        }

    payload: dict[str, Any] = {
        "ok": False,
        "model_dir": str(model_root),
        "present": False,
        "checkpoint": str(checkpoint) if checkpoint is not None else "",
        "source": source,
        "candidates_count": len(candidates),
    }
    if error:
        payload["error"] = error

    return payload


def main() -> int:
    payload = model_status()
    print(json.dumps(payload, ensure_ascii=False))
    return 0 if payload["present"] else 2


if __name__ == "__main__":
    sys.exit(main())
