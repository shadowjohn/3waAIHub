from __future__ import annotations

import importlib
import importlib.metadata
import json
import os


def main() -> None:
    fastapi = importlib.import_module("fastapi")
    paddleocr = importlib.import_module("paddleocr")
    print(json.dumps(
        {
            "ok": True,
            "message": "smoke.py import paddleocr OK",
            "level": "L2-deps-import",
            "fastapi": getattr(fastapi, "__version__", "unknown"),
            "paddleocr": getattr(paddleocr, "__version__", importlib.metadata.version("paddleocr")),
            "ocr_use_gpu": os.getenv("OCR_USE_GPU", "0"),
            "nvidia_visible_devices": os.getenv("NVIDIA_VISIBLE_DEVICES", ""),
        },
        ensure_ascii=False,
    ))


if __name__ == "__main__":
    main()
