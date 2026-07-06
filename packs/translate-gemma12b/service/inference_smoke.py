from __future__ import annotations

import os
import sys

import requests


def main() -> int:
    base_url = os.getenv("TRANSLATE_BASE_URL", "http://127.0.0.1:8000").rstrip("/")
    try:
        response = requests.post(
            f"{base_url}/translate",
            json={
                "source_lang": "en",
                "target_lang": "zh-TW",
                "text": "That was a wonderful time.",
                "real_inference": True,
            },
            timeout=240,
        )
        payload = response.json()
    except (requests.RequestException, ValueError) as exc:
        print(f"translate request failed: {exc}", file=sys.stderr)
        return 2

    if not response.ok or not payload.get("ok") or payload.get("mock") is not False:
        print(f"translate failed: {payload}", file=sys.stderr)
        return 3
    if not str(payload.get("text", "")).strip():
        print("translation text is empty", file=sys.stderr)
        return 4

    print(payload["text"])
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
