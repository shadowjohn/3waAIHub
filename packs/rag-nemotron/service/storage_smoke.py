from pathlib import Path
import json


def status(path: str) -> dict[str, object]:
    p = Path(path)
    p.mkdir(parents=True, exist_ok=True)
    probe = p / ".write_test"
    probe.write_text("ok", encoding="utf-8")
    probe.unlink()
    return {"path": path, "exists": p.exists(), "readable": True, "writable": True}


if __name__ == "__main__":
    print(json.dumps({
        "ok": True,
        "storage": {
            "models": status("/models/nemotron"),
            "cache": status("/cache/nemotron"),
            "service_data": status("/data/service"),
        },
    }))
