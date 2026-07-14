import json
import os
from pathlib import Path


def check_writable(path: str) -> dict:
    p = Path(path)
    p.mkdir(parents=True, exist_ok=True)
    probe = p / ".3waaihub_gemma4_probe"
    probe.write_text("ok", encoding="utf-8")
    ok = probe.read_text(encoding="utf-8") == "ok"
    probe.unlink(missing_ok=True)
    return {"path": str(p), "exists": p.exists(), "writable": ok}


paths = [
    os.getenv("GEMMA4_CACHE_DIR", "/cache/gemma4"),
    os.getenv("GEMMA4_SERVICE_DATA_DIR", "/data/service"),
]

checks = []
errors = []
for item in paths:
    try:
        checks.append(check_writable(item))
    except Exception as exc:  # pragma: no cover
        errors.append({"path": item, "error": str(exc)})

payload = {
    "ok": not errors,
    "service": "llm-gemma4-12b",
    "runtime_level": "L5-benchmark-ready",
    "checks": checks,
    "errors": errors,
}
print(json.dumps(payload, ensure_ascii=False))
raise SystemExit(0 if not errors else 1)
