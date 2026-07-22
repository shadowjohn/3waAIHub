from __future__ import annotations

import hashlib
import json
import stat
import tempfile
import unittest
from pathlib import Path

from provision_offline_assets import (
    MODEL_REPOSITORY,
    MODEL_REVISION,
    ProvisionError,
    provision,
)


class ProvisionOfflineAssetsTest(unittest.TestCase):
    def test_provision_downloads_pinned_snapshot_and_publishes_sorted_checksums(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            model_root = Path(temporary)
            calls: list[dict[str, str]] = []

            def fake_download(**kwargs: str) -> str:
                calls.append(kwargs)
                self.assertFalse((model_root / "ready.json").exists())
                snapshot = Path(kwargs["local_dir"])
                (snapshot / "nested").mkdir(parents=True)
                (snapshot / "model.safetensors").write_bytes(b"weights")
                (snapshot / "config.json").write_text("{}", encoding="utf-8")
                (snapshot / "nested" / "tokenizer.json").write_text("tokenizer", encoding="utf-8")
                (snapshot / ".cache").mkdir()
                (snapshot / ".cache" / "ignored").write_text("ignored", encoding="utf-8")
                return str(snapshot)

            ready = provision(model_root=model_root, downloader=fake_download)

            self.assertEqual(calls, [{
                "repo_id": MODEL_REPOSITORY,
                "revision": MODEL_REVISION,
                "local_dir": str(model_root / "snapshot"),
            }])
            self.assertEqual(ready["repository"], MODEL_REPOSITORY)
            self.assertEqual(ready["revision"], MODEL_REVISION)
            self.assertTrue(ready["created_at"].endswith("Z"))
            self.assertEqual(
                [row["path"] for row in ready["files"]],
                ["config.json", "model.safetensors", "nested/tokenizer.json"],
            )
            self.assertEqual(
                ready["files"][1],
                {
                    "path": "model.safetensors",
                    "size": 7,
                    "sha256": hashlib.sha256(b"weights").hexdigest(),
                },
            )
            self.assertEqual(
                json.loads((model_root / "ready.json").read_text(encoding="utf-8")),
                ready,
            )
            self.assertFalse((model_root / "ready.json.tmp").exists())
            self.assertEqual(stat.S_IMODE(model_root.stat().st_mode), 0o755)
            self.assertEqual(stat.S_IMODE((model_root / "ready.json").stat().st_mode), 0o644)
            self.assertEqual(stat.S_IMODE((model_root / "snapshot" / "model.safetensors").stat().st_mode), 0o644)

    def test_provision_rejects_incomplete_snapshot_and_removes_temporary_marker(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            model_root = Path(temporary)
            (model_root / "ready.json.tmp").write_text("stale", encoding="utf-8")

            def fake_download(**kwargs: str) -> str:
                Path(kwargs["local_dir"]).mkdir(parents=True)
                return kwargs["local_dir"]

            with self.assertRaisesRegex(ProvisionError, "incomplete snapshot"):
                provision(model_root=model_root, downloader=fake_download)

            self.assertFalse((model_root / "ready.json").exists())
            self.assertFalse((model_root / "ready.json.tmp").exists())

    def test_provision_rejects_symlink_that_escapes_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            model_root = Path(temporary) / "models"
            outside = Path(temporary) / "outside.safetensors"
            outside.write_bytes(b"weights")

            def fake_download(**kwargs: str) -> str:
                snapshot = Path(kwargs["local_dir"])
                snapshot.mkdir(parents=True)
                (snapshot / "config.json").write_text("{}", encoding="utf-8")
                (snapshot / "model.safetensors").symlink_to(outside)
                return str(snapshot)

            with self.assertRaisesRegex(ProvisionError, "snapshot path escapes model root"):
                provision(model_root=model_root, downloader=fake_download)

            self.assertFalse((model_root / "ready.json").exists())
            self.assertFalse((model_root / "ready.json.tmp").exists())


if __name__ == "__main__":
    unittest.main()
