import importlib
import json


def main() -> None:
    fastapi = importlib.import_module("fastapi")
    requests = importlib.import_module("requests")
    print(json.dumps({
        "ok": True,
        "service": "rag-nemotron",
        "fastapi": getattr(fastapi, "__version__", "unknown"),
        "requests": getattr(requests, "__version__", "unknown"),
    }))


if __name__ == "__main__":
    main()
