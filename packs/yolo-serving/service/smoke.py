from __future__ import annotations

import importlib
import json


def main() -> None:
    fastapi = importlib.import_module("fastapi")
    ultralytics = importlib.import_module("ultralytics")
    print(json.dumps({
        "ok": True,
        "service": "yolo-serving",
        "fastapi": getattr(fastapi, "__version__", "unknown"),
        "ultralytics": getattr(ultralytics, "__version__", "unknown"),
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
