from __future__ import annotations

import importlib
import json
import os


def main() -> None:
    fastapi = importlib.import_module("fastapi")
    print(json.dumps(
        {
            "ok": True,
            "level": "L1-gpu-api-mock",
            "fastapi": getattr(fastapi, "__version__", "unknown"),
            "ocr_use_gpu": os.getenv("OCR_USE_GPU", "0"),
            "nvidia_visible_devices": os.getenv("NVIDIA_VISIBLE_DEVICES", ""),
        },
        ensure_ascii=False,
    ))


if __name__ == "__main__":
    main()
