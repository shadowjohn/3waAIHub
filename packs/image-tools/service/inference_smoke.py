from __future__ import annotations

import argparse
import io
import json
import urllib.request
from pathlib import Path

from PIL import Image


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:8000")
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--backend", choices=["cpu", "cuda"], required=True)
    args = parser.parse_args()
    fixture = Path(args.fixture)
    source = fixture.read_bytes()
    boundary = "3waaihub-image-tools"
    body = b"".join((
        b"--" + boundary.encode() + b"\r\nContent-Disposition: form-data; name=\"operation\"\r\n\r\nupscale\r\n",
        b"--" + boundary.encode() + b"\r\nContent-Disposition: form-data; name=\"backend\"\r\n\r\n" + args.backend.encode() + b"\r\n",
        b"--" + boundary.encode() + b"\r\nContent-Disposition: form-data; name=\"image\"; filename=\"source.png\"\r\nContent-Type: image/png\r\n\r\n" + source + b"\r\n",
        b"--" + boundary.encode() + b"--\r\n",
    ))
    request = urllib.request.Request(args.base_url.rstrip("/") + "/process/image", data=body, method="POST", headers={"Content-Type": "multipart/form-data; boundary=" + boundary})
    with urllib.request.urlopen(request, timeout=900) as response:
        output = response.read()
        with Image.open(io.BytesIO(output)) as image:
            image.load()
            size = image.size
        ok = response.headers.get_content_type() == "image/png" and response.headers.get("X-3waAIHub-Backend") == args.backend
    print(json.dumps({"ok": ok, "backend": args.backend, "size": list(size)}, sort_keys=True))
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
