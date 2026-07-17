from __future__ import annotations

import importlib
import importlib.metadata
import json


def version(module: object, package: str) -> str:
    return str(getattr(module, "__version__", importlib.metadata.version(package)))


def main() -> None:
    fastapi = importlib.import_module("fastapi")
    ultralytics = importlib.import_module("ultralytics")
    pi_heif = importlib.import_module("pi_heif")
    print(json.dumps({
        "ok": True,
        "message": "smoke.py import ultralytics OK",
        "runtime_level": "L2-deps-import",
        "fastapi": version(fastapi, "fastapi"),
        "ultralytics": version(ultralytics, "ultralytics"),
        "pi_heif": version(pi_heif, "pi-heif"),
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
