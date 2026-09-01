from __future__ import annotations

import argparse
import hashlib
import json
import os
import sys
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path


ACCEPTANCE_RECORD_NAME = "paligemma2-acceptance.json"


def write_acceptance_record(snapshot: dict, image_path: Path, result: dict, gpu_name: str, record_path: str) -> None:
    service_data = Path(os.getenv("PALIGEMMA2_SERVICE_DATA_DIR", "/data/service"))
    if service_data.is_symlink():
        raise RuntimeError("acceptance_record_write_failed")
    service_data.mkdir(parents=True, exist_ok=True)
    target = service_data / ACCEPTANCE_RECORD_NAME
    if Path(record_path) != target or target.is_symlink():
        raise RuntimeError("acceptance_record_write_failed")
    image_hash = hashlib.sha256(image_path.read_bytes()).hexdigest()
    record = {
        "schema_version": 1,
        "ok": True,
        "mock": False,
        "runtime_level": "L4-real-inference",
        "model": snapshot["model"],
        "revision": snapshot["revision"],
        "fixture_sha256": image_hash,
        "gpu": gpu_name,
        "vram_total_bytes": int(result["vram_total_bytes"]),
        "vram_peak_bytes": int(result["vram_peak_bytes"]),
        "elapsed_ms": int(result["elapsed_ms"]),
        "accepted_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
    }
    with tempfile.NamedTemporaryFile("w", encoding="utf-8", dir=service_data, delete=False) as handle:
        json.dump(record, handle, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
        handle.write("\n")
        temporary = Path(handle.name)
    try:
        os.chmod(temporary, 0o600)
        temporary.replace(target)
    except OSError:
        temporary.unlink(missing_ok=True)
        raise RuntimeError("acceptance_record_write_failed")


def main() -> int:
    parser = argparse.ArgumentParser(description="3waAIHub PaliGemma 2 real CUDA acceptance")
    parser.add_argument("--image", required=True, help="Local image file mounted inside the runtime container")
    parser.add_argument("--prompt", default="caption en")
    parser.add_argument("--record-path", default="", help="Controlled service-data acceptance record path")
    args = parser.parse_args()

    os.environ["PALIGEMMA2_REAL_INFERENCE"] = "1"
    image_path = Path(args.image)
    if not image_path.is_file():
        print(json.dumps({"ok": False, "error": "acceptance_image_missing"}), file=sys.stderr)
        return 2

    import torch
    from app import run_paligemma_inference
    from provision import verify_snapshot

    if not torch.cuda.is_available():
        print(json.dumps({"ok": False, "error": "gpu_unavailable"}), file=sys.stderr)
        return 2
    snapshot = verify_snapshot()
    torch.cuda.reset_peak_memory_stats()
    started = time.perf_counter()
    result = run_paligemma_inference(image_path.read_bytes(), args.prompt, task="caption")
    text = str(result.get("text", "")).strip()
    if not result.get("ok") or result.get("mock") or not text:
        print(json.dumps({"ok": False, "error": "acceptance_output_invalid"}), file=sys.stderr)
        return 1
    device = torch.cuda.get_device_properties(0)
    output = {
        "ok": True,
        "mock": False,
        "model": snapshot["model"],
        "revision": snapshot["revision"],
        "gpu": device.name,
        "vram_total_bytes": device.total_memory,
        "vram_peak_bytes": torch.cuda.max_memory_allocated(),
        "elapsed_ms": int((time.perf_counter() - started) * 1000),
        "text": text,
    }
    if args.record_path:
        try:
            write_acceptance_record(snapshot, image_path, output, device.name, args.record_path)
        except RuntimeError as exc:
            print(json.dumps({"ok": False, "error": str(exc)}), file=sys.stderr)
            return 1
    print(json.dumps(output, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
