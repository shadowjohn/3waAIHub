from __future__ import annotations

import json
import subprocess


def _driver_version() -> str:
    try:
        return subprocess.check_output(
            ["nvidia-smi", "--query-gpu=driver_version", "--format=csv,noheader"],
            text=True,
            timeout=5,
        ).splitlines()[0].strip()
    except (OSError, subprocess.SubprocessError, IndexError):
        return "unknown"


def main() -> int:
    try:
        import torch

        if not torch.cuda.is_available():
            print(json.dumps({"cuda": False}))
            return 1
        free, total = torch.cuda.mem_get_info()
        print(json.dumps({
            "cuda": True,
            "cuda_version": torch.version.cuda,
            "driver_version": _driver_version(),
            "device": torch.cuda.get_device_name(0),
            "free_vram_bytes": int(free),
            "total_vram_bytes": int(total),
        }))
        return 0
    except Exception:
        print(json.dumps({"cuda": False}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
