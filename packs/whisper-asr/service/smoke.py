from __future__ import annotations

import importlib
import importlib.metadata
import json


def main() -> None:
    fastapi = importlib.import_module("fastapi")
    faster_whisper = importlib.import_module("faster_whisper")
    print(json.dumps(
        {
            "ok": True,
            "message": "smoke.py import faster_whisper OK",
            "runtime_level": "L2-deps-import",
            "fastapi": getattr(fastapi, "__version__", "unknown"),
            "faster_whisper": getattr(faster_whisper, "__version__", importlib.metadata.version("faster-whisper")),
        },
        ensure_ascii=False,
    ))


if __name__ == "__main__":
    main()
