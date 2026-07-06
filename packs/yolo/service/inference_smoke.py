from __future__ import annotations

import json
import os
import sys
from pathlib import Path

from app import run_yolo, runtime_level


def main() -> None:
    fixture = Path(sys.argv[1] if len(sys.argv) > 1 else "/data/service/real_sample.jpg")
    if not fixture.is_file():
        raise SystemExit(f"fixture missing: {fixture}")

    os.environ.setdefault("YOLO_REAL_INFERENCE", "1")
    result = run_yolo(fixture)
    ok = bool(result.get("ok")) and isinstance(result.get("detections"), list)
    print(json.dumps({
        "ok": ok,
        "endpoint": "/detect/image",
        "runtime_level": runtime_level(),
        "real_inference": True,
        "detections": len(result.get("detections", [])),
        "elapsed_ms": result.get("elapsed_ms", 0),
    }, ensure_ascii=False))
    if not ok:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
