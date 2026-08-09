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
    huggingface_hub = importlib.import_module("huggingface_hub")
    sam3 = importlib.import_module("sam3")
    cv2 = importlib.import_module("cv2")
    print(json.dumps({
        "ok": True,
        "message": "smoke.py import SAM 3.1 adapter deps OK",
        "runtime_level": "L2-deps-import",
        "fastapi": version(fastapi, "fastapi"),
        "PIL": version(pillow, "pillow"),
        "numpy": version(numpy, "numpy"),
        "requests": version(requests, "requests"),
        "huggingface_hub": version(huggingface_hub, "huggingface-hub"),
        "sam3": getattr(sam3, "__file__", "installed"),
        "cv2": version(cv2, "opencv-python-headless"),
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
