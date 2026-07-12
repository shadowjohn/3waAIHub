from __future__ import annotations

import json
from io import BytesIO

from PIL import Image

from app import run_bioclip, runtime_level


def sample_image() -> bytes:
    image = Image.new("RGB", (224, 224), (70, 120, 65))
    buffer = BytesIO()
    image.save(buffer, format="JPEG")
    return buffer.getvalue()


def main() -> None:
    result = run_bioclip(sample_image(), "plant,insect,bird,mammal")
    ok = bool(result.get("ok")) and result.get("mock") is False and isinstance(result.get("labels"), list)
    print(json.dumps({
        "ok": ok,
        "runtime_level": runtime_level(),
        "endpoint": "/classify/image",
        "labels": result.get("labels", []),
        "device": result.get("device", {}),
        "elapsed_ms": result.get("elapsed_ms", 0),
    }, ensure_ascii=False))
    if not ok:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
