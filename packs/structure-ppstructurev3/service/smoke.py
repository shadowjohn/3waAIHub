from __future__ import annotations

import importlib.metadata
import importlib.util
import json


def main() -> None:
    if importlib.util.find_spec("paddleocr") is None:
        raise SystemExit("paddleocr import spec missing")
    if importlib.util.find_spec("paddle") is None:
        raise SystemExit("paddle import spec missing")
    print(json.dumps({
        "ok": True,
        "service": "structure-ppstructurev3",
        "runtime_level": "L4-real-inference",
        "fastapi": importlib.metadata.version("fastapi"),
        "paddleocr": importlib.metadata.version("paddleocr"),
        "paddlepaddle": importlib.metadata.version("paddlepaddle"),
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
