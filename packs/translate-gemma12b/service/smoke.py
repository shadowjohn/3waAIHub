from __future__ import annotations

import json

import fastapi
import requests

import app


def main() -> None:
    print(json.dumps({
        "ok": True,
        "fastapi": getattr(fastapi, "__version__", "unknown"),
        "requests": getattr(requests, "__version__", "unknown"),
        "routes": len(app.app.routes),
        "runtime_level": "L4a-model-present-smoke",
    }))


if __name__ == "__main__":
    main()
