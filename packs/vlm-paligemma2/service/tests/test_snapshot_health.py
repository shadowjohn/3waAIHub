from __future__ import annotations

import hashlib
import json
import os
import tempfile
from pathlib import Path

import provision
import app


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    digest.update(path.read_bytes())
    return digest.hexdigest()


def main() -> None:
    with tempfile.TemporaryDirectory() as temporary:
        model_root = Path(temporary) / "models"
        snapshot = model_root / "snapshot"
        snapshot.mkdir(parents=True)
        for name, contents in {
            "config.json": b"{}\n",
            "model.safetensors.index.json": b"{}\n",
            "preprocessor_config.json": b"{}\n",
            "tokenizer.json": b"{}\n",
            "model-00001-of-00001.safetensors": b"fixture-weights\n",
        }.items():
            (snapshot / name).write_bytes(contents)

        files = [
            {
                "path": path.relative_to(snapshot).as_posix(),
                "size_bytes": path.stat().st_size,
                "sha256": sha256(path),
            }
            for path in sorted(snapshot.iterdir())
        ]
        (snapshot / "provision-manifest.json").write_text(
            json.dumps(
                {
                    "model": "google/paligemma2-3b-pt-224",
                    "revision": "96eeb174da13ca1a2b247e4d0867436296c36420",
                    "files": files,
                }
            ),
            encoding="utf-8",
        )
        os.environ["PALIGEMMA2_MODEL_DIR"] = str(model_root)
        os.environ["PALIGEMMA2_MODEL"] = "google/paligemma2-3b-pt-224"
        os.environ["PALIGEMMA2_MODEL_REVISION"] = "96eeb174da13ca1a2b247e4d0867436296c36420"

        original_verify = provision.verify_snapshot
        provision.verify_snapshot = lambda: (_ for _ in ()).throw(AssertionError("full verifier must not run from health"))
        try:
            result = app.lightweight_snapshot_status()
        finally:
            provision.verify_snapshot = original_verify

        assert result["model"] == "google/paligemma2-3b-pt-224"
        assert result["revision"] == "96eeb174da13ca1a2b247e4d0867436296c36420"
        assert result["file_count"] == 5
        assert result["size_bytes"] > 0


if __name__ == "__main__":
    main()
