from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path
from typing import Any


PATHS = {
    "models": "/models/yolo",
    "cache": "/cache/yolo",
    "ultralytics": "/cache/yolo/ultralytics",
    "service_data": "/data/service",
}


def check_path(path: str) -> dict[str, Any]:
    target = Path(path)
    exists = target.is_dir()
    readable = exists and os.access(target, os.R_OK)
    writable = False
    error = ""

    if exists and readable:
        try:
            with tempfile.NamedTemporaryFile(prefix=".3waaihub-write-", dir=target, delete=False) as handle:
                test_path = Path(handle.name)
            test_path.unlink(missing_ok=True)
            writable = True
        except OSError as exc:
            error = str(exc)
    elif not exists:
        error = "directory missing"
    else:
        error = "directory not readable"

    result: dict[str, Any] = {
        "path": path,
        "exists": exists,
        "readable": readable,
        "writable": writable,
    }
    if error:
        result["error"] = error
    return result


def main() -> None:
    storage = {name: check_path(path) for name, path in PATHS.items()}
    errors = [
        f"{name} {key} failed: {status['path']}"
        for name, status in storage.items()
        for key in ("exists", "readable", "writable")
        if not status[key]
    ]
    print(json.dumps({"ok": not errors, "storage": storage, "errors": errors}, ensure_ascii=False))
    if errors:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
