from __future__ import annotations

import os
import sys

import requests


def main() -> int:
    base_url = os.getenv("OLLAMA_BASE_URL", "http://ollama:11434").rstrip("/")
    model = os.getenv("OLLAMA_MODEL", "translategemma:12b-it-q4_K_M").strip()
    if not model:
        print("OLLAMA_MODEL is empty", file=sys.stderr)
        return 2

    try:
        response = requests.get(f"{base_url}/api/tags", timeout=10)
        response.raise_for_status()
        payload = response.json()
    except requests.RequestException as exc:
        print(f"ollama tags failed: {exc}", file=sys.stderr)
        return 3

    models = payload.get("models", []) if isinstance(payload, dict) else []
    names = [str(item.get("name", "")) for item in models if isinstance(item, dict)]
    if model not in names:
        print(f"model not present: {model}", file=sys.stderr)
        return 4

    print(f"model present: {model}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
