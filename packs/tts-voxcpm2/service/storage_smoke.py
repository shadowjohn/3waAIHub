from __future__ import annotations

import os
import tempfile
from pathlib import Path


def assert_writable(path: str) -> None:
    target = Path(path)
    target.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(prefix=".3waaihub-write-", dir=target, delete=False) as handle:
        test_path = Path(handle.name)
    test_path.unlink(missing_ok=True)


def main() -> None:
    model_dir = os.getenv("VOXCPM2_MODEL_DIR", "/models/voxcpm2")
    cache_dir = os.getenv("VOXCPM2_CACHE_DIR", "/cache/voxcpm2")
    service_data_dir = os.getenv("VOXCPM2_SERVICE_DATA_DIR", "/data/service")
    for path in [
        model_dir,
        f"{model_dir}/huggingface",
        cache_dir,
        f"{cache_dir}/xdg",
        f"{cache_dir}/home",
        service_data_dir,
        f"{service_data_dir}/artifacts",
    ]:
        assert_writable(path)
    voice_profiles = Path("/data/voice_profiles")
    voice_profiles.mkdir(parents=True, exist_ok=True)
    assert voice_profiles.exists()
    print({"ok": True, "service": "tts-voxcpm2", "storage": "ready"})


if __name__ == "__main__":
    main()
