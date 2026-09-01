from __future__ import annotations

import argparse
import json
import os
import sys
import time
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser(description="3waAIHub PaliGemma 2 real CUDA acceptance")
    parser.add_argument("--image", required=True, help="Local image file mounted inside the runtime container")
    parser.add_argument("--prompt", default="caption en")
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
    print(json.dumps({
        "ok": True,
        "mock": False,
        "model": snapshot["model"],
        "revision": snapshot["revision"],
        "gpu": device.name,
        "vram_total_bytes": device.total_memory,
        "vram_peak_bytes": torch.cuda.max_memory_allocated(),
        "elapsed_ms": int((time.perf_counter() - started) * 1000),
        "text": text,
    }, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
