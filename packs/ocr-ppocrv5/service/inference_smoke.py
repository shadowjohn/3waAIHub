from __future__ import annotations

import json
import os
import sys
from pathlib import Path

from app import run_paddleocr, runtime_level


def main() -> None:
    fixture = Path(sys.argv[1] if len(sys.argv) > 1 else "/data/service/real_sample.png")
    if not fixture.is_file():
        raise SystemExit(f"fixture missing: {fixture}")

    os.environ.setdefault("OCR_REAL_INFERENCE", "1")
    result = run_paddleocr(fixture)
    ok = bool(result.get("ok")) and bool(result.get("blocks"))
    print(json.dumps({
        "ok": ok,
        "endpoint": "/ocr/image",
        "runtime_level": runtime_level(),
        "real_inference": True,
        "text": result.get("text", ""),
        "blocks": len(result.get("blocks", [])),
    }, ensure_ascii=False))
    if not ok:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
