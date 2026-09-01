from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path

import app


MODEL = "google/paligemma2-3b-pt-224"
REVISION = "96eeb174da13ca1a2b247e4d0867436296c36420"


def main() -> None:
    with tempfile.TemporaryDirectory() as temporary:
        service_data = Path(temporary) / "service-data"
        service_data.mkdir()
        os.environ["PALIGEMMA2_SERVICE_DATA_DIR"] = str(service_data)
        os.environ["PALIGEMMA2_MODEL"] = MODEL
        os.environ["PALIGEMMA2_MODEL_REVISION"] = REVISION
        os.environ["PALIGEMMA2_REAL_INFERENCE"] = "1"
        record = {
            "schema_version": 1,
            "ok": True,
            "mock": False,
            "runtime_level": "L4-real-inference",
            "model": MODEL,
            "revision": REVISION,
            "fixture_sha256": "53170e6afeba5c703e1e858c126a582e4494d137fb9592c0b1372c49f4e91f8c",
            "gpu": "NVIDIA GeForce GTX 1080",
            "vram_total_bytes": 8589672448,
            "vram_peak_bytes": 6143119360,
            "elapsed_ms": 35040,
            "accepted_at": "2026-09-01T03:00:00+00:00",
        }
        (service_data / "paligemma2-acceptance.json").write_text(json.dumps(record), encoding="utf-8")

        accepted = app.accepted_runtime_record()

        assert accepted is not None
        assert accepted["model"] == MODEL
        assert accepted["gpu"] == "NVIDIA GeForce GTX 1080"
        assert app.health_readiness(accepted, []) == (True, "ready", "L4-real-inference")


if __name__ == "__main__":
    main()
