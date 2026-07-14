from __future__ import annotations

import importlib
import importlib.metadata
import json


def version(module: object, package: str) -> str:
    return str(getattr(module, "__version__", importlib.metadata.version(package)))


def main() -> None:
    fastapi = importlib.import_module("fastapi")
    pillow = importlib.import_module("PIL")
    numpy = importlib.import_module("numpy")
    requests = importlib.import_module("requests")
    ultralytics = importlib.import_module("ultralytics")
    cv2 = importlib.import_module("cv2")
    print(json.dumps({
        "ok": True,
        "message": "smoke.py import SAM3 adapter deps OK",
        "runtime_level": "L2-deps-import",
        "fastapi": version(fastapi, "fastapi"),
        "PIL": version(pillow, "pillow"),
        "numpy": version(numpy, "numpy"),
        "requests": version(requests, "requests"),
        "ultralytics": version(ultralytics, "ultralytics"),
        "cv2": version(cv2, "opencv-python-headless"),
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
