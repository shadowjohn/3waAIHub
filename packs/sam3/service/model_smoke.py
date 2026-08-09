from __future__ import annotations

import hashlib
import json
import os
import sys
from pathlib import Path
from typing import Any


CHECKPOINT_NAME = "sam3.1_multiplex.pt"
MANIFEST_NAME = "sam3.1-manifest.json"
UPSTREAM_COMMIT = "96914d2425f90a64f45ca977c2b5165418099543"
REPOSITORY = "facebook/sam3.1"


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def model_status() -> dict[str, Any]:
    root = Path(os.getenv("SAM3_MODEL_DIR", "/models/sam3"))
    checkpoint = root / CHECKPOINT_NAME
    manifest_path = root / MANIFEST_NAME
    payload: dict[str, Any] = {
        "ok": False,
        "present": False,
        "checkpoint": CHECKPOINT_NAME,
        "manifest": MANIFEST_NAME,
        "upstream_commit": UPSTREAM_COMMIT,
        "repository": REPOSITORY,
    }
    if not checkpoint.is_file() or checkpoint.is_symlink() or not manifest_path.is_file() or manifest_path.is_symlink():
        payload["error"] = "model_not_present"
        return payload
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError):
        payload["error"] = "model_manifest_invalid"
        return payload
    if not isinstance(manifest, dict) or manifest.get("upstream_commit") != UPSTREAM_COMMIT or manifest.get("repository") != REPOSITORY:
        payload["error"] = "model_manifest_invalid"
        return payload
    files = manifest.get("files")
    expected = files.get(CHECKPOINT_NAME) if isinstance(files, dict) else None
    if not isinstance(expected, str) or len(expected) != 64 or expected != sha256(checkpoint):
        payload["error"] = "model_manifest_invalid"
        return payload
    payload["ok"] = True
    payload["present"] = True
    payload["checkpoint_sha256"] = expected
    return payload


def main() -> int:
    payload = model_status()
    print(json.dumps(payload, ensure_ascii=False, sort_keys=True))
    return 0 if payload["ok"] else 2


if __name__ == "__main__":
    sys.exit(main())
