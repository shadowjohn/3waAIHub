from __future__ import annotations

import importlib
import json


def main() -> None:
    fastapi = importlib.import_module("fastapi")
    pillow = importlib.import_module("PIL")
    numpy = importlib.import_module("numpy")
    torch = importlib.import_module("torch")
    open_clip = importlib.import_module("open_clip")
    print(json.dumps(
        {
            "ok": True,
            "message": "smoke.py import BioCLIP L2 deps OK",
            "runtime_level": "L2-deps-import",
            "fastapi": getattr(fastapi, "__version__", "unknown"),
            "pillow": getattr(pillow, "__version__", "unknown"),
            "numpy": getattr(numpy, "__version__", "unknown"),
            "torch": getattr(torch, "__version__", "unknown"),
            "open_clip": getattr(open_clip, "__version__", "unknown"),
            "cuda_available": bool(torch.cuda.is_available()),
        },
        ensure_ascii=False,
    ))


if __name__ == "__main__":
    main()
