from __future__ import annotations

import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path


SERVICE_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SERVICE_DIR))
import provision  # noqa: E402


def write_snapshot(root: Path, *, marker: bool = True) -> Path:
    snapshot = root / "snapshot"
    snapshot.mkdir(parents=True)
    files = {"config.json": b"{}", "model.safetensors": b"weights"}
    for name, data in files.items():
        (snapshot / name).write_bytes(data)
    if marker:
        (root / "verified-snapshot.json").write_text(json.dumps({
            "snapshot": "snapshot",
            "files": [{"path": name, "sha256": hashlib.sha256(data).hexdigest()} for name, data in sorted(files.items())],
        }), encoding="utf-8")
    return snapshot


class ProvisionTests(unittest.TestCase):
    def test_settings_require_the_pinned_model_immutable_revision_float16_cuda_and_token(self) -> None:
        environment = {
            "MANUAL_VISION_MODEL": provision.MODEL_ID,
            "MANUAL_VISION_MODEL_REVISION": "a" * 40,
            "MANUAL_VISION_TORCH_DTYPE": "float16",
            "MANUAL_VISION_DEVICE": "cuda",
            "HF_TOKEN": "secret",
        }
        self.assertEqual("a" * 40, provision.settings_from_environment(environment).revision)
        for key, value in (
            ("MANUAL_VISION_MODEL_REVISION", "main"),
            ("MANUAL_VISION_MODEL", "other/model"),
            ("MANUAL_VISION_TORCH_DTYPE", "bfloat16"),
            ("MANUAL_VISION_DEVICE", "cpu"),
        ):
            with self.subTest(key=key):
                invalid = dict(environment, **{key: value})
                with self.assertRaises(ValueError):
                    provision.settings_from_environment(invalid)

    def test_verifier_rejects_missing_marker_symlink_and_altered_listed_file(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "model"
            snapshot = write_snapshot(root, marker=False)
            with self.assertRaises(ValueError):
                provision.verify_published_snapshot(root)
            write_snapshot(root / "complete")
            complete = root / "complete"
            self.assertEqual(complete / "snapshot", provision.verify_published_snapshot(complete))
            try:
                (complete / "snapshot" / "linked.json").symlink_to(complete / "snapshot" / "config.json")
            except OSError as exc:
                self.skipTest(f"symlinks unavailable: {exc}")
            with self.assertRaises(ValueError):
                provision.verify_published_snapshot(complete)
            (complete / "snapshot" / "linked.json").unlink()
            (complete / "snapshot" / "model.safetensors").write_bytes(b"altered")
            with self.assertRaises(ValueError):
                provision.verify_published_snapshot(complete)

    def test_publish_writes_marker_only_after_every_staged_file_hash_validates(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            staging = Path(temporary) / "staging"
            snapshot = write_snapshot(staging, marker=False)
            manifest = provision.manifest_for_snapshot(snapshot)
            self.assertFalse((staging / "verified-snapshot.json").exists())
            provision.write_verified_marker(staging, manifest)
            self.assertEqual(snapshot, provision.verify_published_snapshot(staging))
            (snapshot / "model.safetensors").write_bytes(b"changed")
            with self.assertRaises(ValueError):
                provision.write_verified_marker(staging, manifest)

    def test_symlinked_model_root_is_rejected_without_touching_its_target_marker(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            temporary_path = Path(temporary)
            target = temporary_path / "target"
            write_snapshot(target)
            root = temporary_path / "model"
            try:
                root.symlink_to(target, target_is_directory=True)
            except OSError as exc:
                self.skipTest(f"symlinks unavailable: {exc}")
            environment = {
                "MANUAL_VISION_MODEL": provision.MODEL_ID,
                "MANUAL_VISION_MODEL_REVISION": "a" * 40,
                "MANUAL_VISION_TORCH_DTYPE": "float16",
                "MANUAL_VISION_DEVICE": "cuda",
                "HF_TOKEN": "secret",
                "MANUAL_VISION_MODEL_DIR": str(root),
            }
            with self.assertRaises(ValueError):
                provision.provision_snapshot(environment, snapshot_download=lambda **_kwargs: None)
            self.assertTrue((target / "verified-snapshot.json").exists())


if __name__ == "__main__":
    unittest.main()
