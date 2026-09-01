from __future__ import annotations

import hashlib
import json
import os
import re
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable

from PIL import Image


RECORD_NAME = "manual-vision-acceptance.json"
MIN_REMAINING_VRAM_BYTES = 512 * 1024 * 1024
DEMO_DIR = Path(__file__).resolve().parents[1] / "demo"
CASES_PATH = DEMO_DIR / "acceptance_cases.json"
_EXPECTED_CASES = [
    {"id": "manual-text", "image": "manual_text_page.png", "question": "What is the shutdown temperature?", "answer": "85 °C"},
    {"id": "spec-table", "image": "manual_specs_table.png", "question": "What is the rated capacity?", "answer": "1.2 L"},
    {"id": "labelled-diagram", "image": "manual_labelled_diagram.png", "question": "What component is marked A?", "answer": "Fuse"},
]


def normalize_answer(answer: str) -> str:
    return re.sub(r"[ \t\r\n\f\v]+", " ", answer.strip(" \t\r\n\f\v"))


def load_cases(path: Path = CASES_PATH) -> list[dict[str, str]]:
    try:
        cases = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError, TypeError, json.JSONDecodeError) as exc:
        raise ValueError("invalid acceptance cases") from exc
    if cases != _EXPECTED_CASES:
        raise ValueError("acceptance cases changed")
    return [dict(case) for case in cases]


def _hash_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _write_record(data_root: Path, record: dict[str, Any]) -> None:
    data_root.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix=f".{RECORD_NAME}.", dir=data_root)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            json.dump(record, handle, sort_keys=True, separators=(",", ":"))
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, data_root / RECORD_NAME)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def _measure(infer: Callable[[Image.Image, str], str], image_path: Path, question: str, cuda: Any) -> tuple[str, int, int, int]:
    cuda.reset_peak_memory_stats()
    with Image.open(image_path) as source:
        image = source.convert("RGB")
    started = time.perf_counter()
    answer = normalize_answer(infer(image, question))
    elapsed_ms = max(0, int((time.perf_counter() - started) * 1000))
    peak = int(cuda.max_memory_allocated())
    remaining = int(cuda.mem_get_info()[0])
    if not answer or remaining < MIN_REMAINING_VRAM_BYTES:
        raise RuntimeError("acceptance check failed")
    return answer, elapsed_ms, peak, remaining


def run_acceptance(
    *,
    infer: Callable[[Image.Image, str], str],
    manifest_sha256: str,
    model_revision: str,
    dtype: str,
    data_root: Path,
    cases_path: Path = CASES_PATH,
    fixtures_dir: Path = DEMO_DIR,
    cuda: Any,
    timestamp: str | None = None,
) -> bool:
    timestamp = timestamp or datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")
    try:
        if not re.fullmatch(r"[a-f0-9]{64}", manifest_sha256) or not re.fullmatch(r"[a-f0-9]{40}", model_revision) or dtype != "float16":
            raise ValueError("invalid acceptance identity")
        rows: list[dict[str, Any]] = []
        for case in load_cases(cases_path):
            image_path = fixtures_dir / case["image"]
            expected = normalize_answer(case["answer"])
            cold, cold_ms, cold_peak, cold_remaining = _measure(infer, image_path, case["question"], cuda)
            warm, warm_ms, warm_peak, warm_remaining = _measure(infer, image_path, case["question"], cuda)
            if cold != expected or warm != expected:
                raise RuntimeError("acceptance answer mismatch")
            rows.append({
                "id": case["id"],
                "fixture_sha256": _hash_file(image_path),
                "answer": expected,
                "cold_elapsed_ms": cold_ms,
                "warm_elapsed_ms": warm_ms,
                "peak_vram_bytes": max(cold_peak, warm_peak),
                "remaining_vram_bytes": min(cold_remaining, warm_remaining),
            })
        _write_record(data_root, {
            "accepted": True,
            "manifest_sha256": manifest_sha256,
            "model_revision": model_revision,
            "dtype": dtype,
            "timestamp": timestamp,
            "cases": rows,
        })
        return True
    except Exception:
        _write_record(data_root, {"accepted": False, "manifest_sha256": manifest_sha256, "timestamp": timestamp})
        return False


def run_local_acceptance() -> bool:
    from app import load_runtime, verified_snapshot
    from provision import settings_from_environment
    import torch

    settings = settings_from_environment()
    snapshot = verified_snapshot()
    processor, model = load_runtime(torch_module=torch, require_acceptance=False)
    from app import run_docvqa

    return run_acceptance(
        infer=lambda image, question: run_docvqa(image, question, processor=processor, model=model, torch_module=torch),
        manifest_sha256=snapshot.manifest_sha256,
        model_revision=settings.revision,
        dtype=settings.dtype,
        data_root=Path(os.getenv("MANUAL_VISION_SERVICE_DATA_DIR", "/data/service")),
        cuda=torch.cuda,
    )


if __name__ == "__main__":
    raise SystemExit(0 if run_local_acceptance() else 1)
