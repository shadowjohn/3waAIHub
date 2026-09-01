from __future__ import annotations

import json
import sys


def main() -> int:
    import torch

    compiled = bool(torch.cuda.is_available())
    device_count = int(torch.cuda.device_count()) if compiled else 0
    available = compiled and device_count > 0
    # Pack manifest 不允許 CPU fallback；驗收腳本也不能被環境變數降級。
    required = True

    device_name = torch.cuda.get_device_name(0) if available else "none"
    total_memory_gb = round(torch.cuda.get_device_properties(0).total_memory / (1024**3), 2) if available else 0.0

    payload = {
        "ok": available,
        "torch_version": torch.__version__,
        "cuda_available": available,
        "cuda_device_count": device_count,
        "gpu_name": device_name,
        "gpu_memory_gb": total_memory_gb,
        "gpu_required": required,
    }
    if not available:
        payload["error"] = "gpu_required_but_unavailable"

    print(json.dumps(payload, ensure_ascii=False))
    return 0 if payload["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
