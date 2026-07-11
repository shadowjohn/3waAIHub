from __future__ import annotations

import importlib.metadata
import importlib.util
import json


def package_version(*names: str) -> str | None:
    for name in names:
        try:
            return importlib.metadata.version(name)
        except importlib.metadata.PackageNotFoundError:
            continue
    return None


def main() -> None:
    if importlib.util.find_spec("paddleocr") is None:
        raise SystemExit("paddleocr import spec missing")
    if importlib.util.find_spec("paddle") is None:
        raise SystemExit("paddle import spec missing")
    print(json.dumps({
        "ok": True,
        "service": "structure-ppstructurev3",
        "runtime_level": "L4-real-inference",
        "fastapi": package_version("fastapi"),
        "paddleocr": package_version("paddleocr"),
        "paddlepaddle": package_version("paddlepaddle", "paddlepaddle-gpu"),
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
