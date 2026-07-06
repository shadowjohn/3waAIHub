from __future__ import annotations

import importlib
import json


def main() -> None:
    paddle = importlib.import_module("paddle")
    paddleocr = importlib.import_module("paddleocr")
    is_compiled_with_cuda = getattr(paddle.device, "is_compiled_with_cuda", lambda: False)
    get_device = getattr(paddle.device, "get_device", lambda: "unknown")
    print(json.dumps(
        {
            "ok": True,
            "level": "L2-deps-import",
            "paddle": getattr(paddle, "__version__", "unknown"),
            "paddleocr": getattr(paddleocr, "__version__", "unknown"),
            "cuda_compiled": bool(is_compiled_with_cuda()),
            "device": get_device(),
        },
        ensure_ascii=False,
    ))


if __name__ == "__main__":
    main()
