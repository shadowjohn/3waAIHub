from __future__ import annotations

import importlib.metadata
import json
import os
import sys


def enabled(value: str | None) -> bool:
    return str(value or "").lower() in {"1", "true", "yes", "on"}


def package_version(*names: str) -> str | None:
    for name in names:
        try:
            return importlib.metadata.version(name)
        except importlib.metadata.PackageNotFoundError:
            continue
    return None


def main() -> int:
    import paddle

    compiled = bool(paddle.device.is_compiled_with_cuda())
    device_count = int(paddle.device.cuda.device_count()) if compiled else 0
    available = compiled and device_count > 0
    required = enabled(os.getenv("OCR_GPU_REQUIRED", "0"))

    payload = {
        "ok": available or not required,
        "paddle": package_version("paddlepaddle", "paddlepaddle-gpu"),
        "paddle_cuda_compiled": compiled,
        "paddle_cuda_available": available,
        "cuda_device_count": device_count,
        "ocr_gpu_required": required,
    }
    if required and not available:
        payload["error"] = "gpu_required_but_unavailable"
    elif not available:
        payload["warning"] = "gpu_unavailable_cpu_allowed"

    print(json.dumps(payload, ensure_ascii=False))
    return 0 if payload["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
