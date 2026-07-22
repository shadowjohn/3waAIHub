from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path
from typing import Any


PATHS = {
    "models": "/models/birefnet",
    "cache": "/cache/birefnet",
    "xdg": "/cache/birefnet/xdg",
    "home": "/cache/birefnet/home",
    "service_data": "/data/service",
}


def check_path(path: str) -> dict[str, Any]:
    target = Path(path)
    target.mkdir(parents=True, exist_ok=True)
    readable = target.is_dir() and os.access(target, os.R_OK)
    writable = False
    error = ""
    if readable:
        try:
            with tempfile.NamedTemporaryFile(prefix=".3waaihub-write-", dir=target, delete=False) as handle:
                probe = Path(handle.name)
            probe.unlink(missing_ok=True)
            writable = True
        except OSError as exc:
            error = str(exc)
    else:
        error = "directory is not readable"
    result: dict[str, Any] = {"path": path, "readable": readable, "writable": writable}
    if error:
        result["error"] = error
    return result


def main() -> None:
    storage = {name: check_path(path) for name, path in PATHS.items()}
    errors = [name for name, status in storage.items() if not status["readable"] or not status["writable"]]
    print(json.dumps({"ok": not errors, "storage": storage, "errors": errors}, sort_keys=True))
    if errors:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
