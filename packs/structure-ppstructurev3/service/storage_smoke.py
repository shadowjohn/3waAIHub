from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path
from typing import Any


PATHS = {
    "models": "/models/ppstructurev3",
    "cache": "/cache/ppstructurev3",
    "service_data": "/data/service",
}


def check_path(path: str) -> dict[str, Any]:
    target = Path(path)
    target.mkdir(parents=True, exist_ok=True)
    writable = False
    error = ""
    try:
        with tempfile.NamedTemporaryFile(prefix=".3waaihub-write-", dir=target, delete=False) as handle:
            test_path = Path(handle.name)
        test_path.unlink(missing_ok=True)
        writable = True
    except OSError as exc:
        error = str(exc)

    result: dict[str, Any] = {
        "path": path,
        "exists": target.is_dir(),
        "readable": os.access(target, os.R_OK),
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
