import importlib
import json

mods = ["fastapi", "pydantic", "requests", "uvicorn"]
missing = []
for name in mods:
    try:
        importlib.import_module(name)
    except Exception as exc:  # pragma: no cover
        missing.append({"module": name, "error": str(exc)})

payload = {
    "ok": not missing,
    "service": "llm-gemma4-12b",
    "runtime_level": "L5-benchmark-ready",
    "missing": missing,
}
print(json.dumps(payload, ensure_ascii=False))
raise SystemExit(0 if not missing else 1)
