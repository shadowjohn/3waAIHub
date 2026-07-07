from __future__ import annotations

import json
import os
import sys
import tempfile
from pathlib import Path

import requests
from PIL import Image, ImageDraw


def fixture_path() -> Path:
    configured = Path(sys.argv[1] if len(sys.argv) > 1 else os.getenv("SAM3_SMOKE_IMAGE", "/data/service/sample.png"))
    if configured.is_file():
        return configured

    target = Path(tempfile.gettempdir()) / "sam3-smoke.png"
    image = Image.new("RGB", (96, 96), "white")
    draw = ImageDraw.Draw(image)
    draw.rectangle((24, 24, 72, 72), fill="black")
    image.save(target)
    return target


def main() -> int:
    url = os.getenv("SAM3_SMOKE_URL", "http://127.0.0.1:8000/segment/image")
    fixture = fixture_path()
    with fixture.open("rb") as handle:
        response = requests.post(
            url,
            files={"image": (fixture.name, handle, "image/png")},
            data={"prompt_type": "auto", "real_inference": "1"},
            timeout=int(os.getenv("SAM3_SMOKE_TIMEOUT", "180")),
        )

    try:
        payload = response.json()
    except ValueError:
        payload = {"ok": False, "error": "bad_response", "body": response.text[:300]}

    ok = (
        response.status_code == 200
        and payload.get("ok") is True
        and payload.get("mock") is False
        and isinstance(payload.get("masks"), list)
    )
    print(json.dumps({
        "ok": ok,
        "status": response.status_code,
        "endpoint": "/segment/image",
        "real_inference": True,
        "runtime_level": payload.get("runtime_level"),
        "masks": len(payload.get("masks", [])) if isinstance(payload.get("masks"), list) else 0,
        "error": payload.get("error", ""),
        "message": payload.get("message", ""),
    }, ensure_ascii=False))
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
