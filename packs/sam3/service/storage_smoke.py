from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path
from typing import Any


PATHS = {
    "models": ("/models/sam3", False),
    "cache": ("/cache/sam3", True),
    "xdg": ("/cache/sam3/xdg", True),
    "home": ("/cache/sam3/home", True),
    "service_data": ("/data/service", True),
}


def check_path(path: str, require_writable: bool) -> dict[str, Any]:
    target = Path(path)
    if require_writable:
        target.mkdir(parents=True, exist_ok=True)
    exists = target.is_dir()
    readable = exists and os.access(target, os.R_OK)
    writable = exists and os.access(target, os.W_OK)
    error = ""
    if require_writable and writable:
        try:
            with tempfile.NamedTemporaryFile(prefix=".3waaihub-write-", dir=target, delete=False) as handle:
                test_path = Path(handle.name)
            test_path.unlink(missing_ok=True)
        except OSError as exc:
            writable = False
            error = str(exc)
    elif not exists:
        error = "directory missing"
    elif not readable:
        error = "directory not readable"
    elif require_writable:
        error = "directory not writable"

    result: dict[str, Any] = {
        "exists": exists,
        "readable": readable,
        "writable": writable,
        "required_writable": require_writable,
    }
    if error:
        result["error"] = error
    return result


def main() -> None:
    storage = {name: check_path(path, require_writable) for name, (path, require_writable) in PATHS.items()}
    errors = [
        f"{name} unavailable"
        for name, status in storage.items()
        if not status["exists"] or not status["readable"] or (status["required_writable"] and not status["writable"])
    ]
    print(json.dumps({"ok": not errors, "storage": storage, "errors": errors}, ensure_ascii=False))
    if errors:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
