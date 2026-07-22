from __future__ import annotations

import argparse
import io
import json
from pathlib import Path

import requests
from PIL import Image

from app import MODEL_HEADER


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:8000")
    parser.add_argument("--fixture", default="/pack/demo/smoke.png")
    parser.add_argument("--expect-device", choices=["cuda", "cpu"], required=True)
    args = parser.parse_args()

    fixture = Path(args.fixture)
    if not fixture.is_file():
        raise SystemExit("fixture is missing")
    with Image.open(fixture) as source:
        expected_size = source.size
    with fixture.open("rb") as handle:
        response = requests.post(
            args.base_url.rstrip("/") + "/remove-background/image",
            files={"image": (fixture.name, handle, "image/png")},
            timeout=180,
        )

    error = ""
    output_mode = ""
    output_size = (0, 0)
    alpha_values = 0
    if response.status_code == 200 and response.content.startswith(b"\x89PNG\r\n\x1a\n"):
        with Image.open(io.BytesIO(response.content)) as output:
            output.load()
            output_mode = output.mode
            output_size = output.size
            if output.mode == "RGBA":
                alpha_values = len(set(output.getchannel("A").get_flattened_data()))
    else:
        try:
            error = str(response.json().get("error", "bad_response"))
        except ValueError:
            error = "bad_response"

    elapsed = response.headers.get("X-3waAIHub-Elapsed-Ms", "")
    ok = (
        response.status_code == 200
        and response.headers.get("Content-Type") == "image/png"
        and response.headers.get("X-3waAIHub-Model") == MODEL_HEADER
        and response.headers.get("X-3waAIHub-Device") == args.expect_device
        and elapsed.isdigit()
        and int(elapsed) > 0
        and output_mode == "RGBA"
        and output_size == expected_size
        and alpha_values > 1
    )
    print(json.dumps({
        "ok": ok,
        "status": response.status_code,
        "runtime_level": "L4b-real-inference",
        "device": response.headers.get("X-3waAIHub-Device", ""),
        "elapsed_ms": int(elapsed) if elapsed.isdigit() else 0,
        "model": response.headers.get("X-3waAIHub-Model", ""),
        "input_size": list(expected_size),
        "output_size": list(output_size),
        "alpha_values": alpha_values,
        "error": error,
    }, sort_keys=True))
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
