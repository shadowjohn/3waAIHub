from __future__ import annotations

import importlib
import importlib.metadata
import json
import os


def main() -> None:
    fastapi = importlib.import_module("fastapi")
    torch = importlib.import_module("torch")
    transformers = importlib.import_module("transformers")
    print(
        json.dumps(
            {
                "ok": True,
                "message": "smoke.py import transformers OK",
                "level": "L2-deps-import",
                "fastapi": getattr(fastapi, "__version__", "unknown"),
                "torch": getattr(torch, "__version__", "unknown"),
                "transformers": getattr(transformers, "__version__", "unknown"),
                "cuda_available": bool(torch.cuda.is_available()),
                "paligemma2_model": os.getenv("PALIGEMMA2_MODEL", "google/paligemma2-3b-pt-224"),
            },
            ensure_ascii=False,
        )
    )


if __name__ == "__main__":
    main()
