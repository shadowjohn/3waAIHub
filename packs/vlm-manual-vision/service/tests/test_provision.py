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
    files = {
        "added_tokens.json": b"{}",
        "config.json": b"{}",
        "generation_config.json": b"{}",
        "model-00001-of-00002.safetensors": b"weights-one",
        "model-00002-of-00002.safetensors": b"weights-two",
        "model.safetensors.index.json": json.dumps({"weight_map": {
            "language_model.embed_tokens.weight": "model-00001-of-00002.safetensors",
            "vision_tower.embeddings.patch_embedding.weight": "model-00002-of-00002.safetensors",
        }}).encode(),
        "preprocessor_config.json": b"{}",
        "special_tokens_map.json": b"{}",
        "tokenizer.json": b"{}",
        "tokenizer.model": b"tokenizer",
        "tokenizer_config.json": b"{}",
    }
    for name, data in files.items():
        (snapshot / name).write_bytes(data)
    if marker:
        (root / "verified-snapshot.json").write_text(json.dumps({
            "snapshot": "snapshot",
            "files": [{"path": name, "sha256": hashlib.sha256(data).hexdigest()} for name, data in sorted(files.items())],
        }), encoding="utf-8")
    return snapshot


class ProvisionTests(unittest.TestCase):
    def test_settings_require_the_pinned_model_immutable_revision_float16_cuda_but_not_runtime_token(self) -> None:
        environment = {
            "MANUAL_VISION_MODEL": provision.MODEL_ID,
            "MANUAL_VISION_MODEL_REVISION": "a" * 40,
            "MANUAL_VISION_TORCH_DTYPE": "float16",
            "MANUAL_VISION_DEVICE": "cuda",
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
        with self.assertRaises(ValueError):
            provision.provision_snapshot(environment, snapshot_download=lambda **_kwargs: None)

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
            (complete / "snapshot" / "model-00001-of-00002.safetensors").write_bytes(b"altered")
            with self.assertRaises(ValueError):
                provision.verify_published_snapshot(complete)

    def test_verifier_requires_processor_tokenizer_and_every_indexed_safetensors_shard(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "model"
            snapshot = write_snapshot(root)
            (snapshot / "tokenizer.model").unlink()
            with self.assertRaises(ValueError):
                provision.verify_published_snapshot(root)
            write_snapshot(root / "complete")
            complete = root / "complete"
            (complete / "snapshot" / "preprocessor_config.json").unlink()
            with self.assertRaises(ValueError):
                provision.verify_published_snapshot(complete)
            write_snapshot(root / "shards")
            shards = root / "shards"
            (shards / "snapshot" / "model-00002-of-00002.safetensors").unlink()
            with self.assertRaises(ValueError):
                provision.verify_published_snapshot(shards)

    def test_verifier_rejects_traversal_or_extra_unindexed_safetensors(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "model"
            snapshot = write_snapshot(root)
            index = snapshot / "model.safetensors.index.json"
            index.write_text(json.dumps({"weight_map": {"x": "../escape.safetensors"}}), encoding="utf-8")
            with self.assertRaises(ValueError):
                provision.manifest_for_snapshot(snapshot)
            write_snapshot(root / "extra")
            extra = root / "extra"
            (extra / "snapshot" / "unindexed.safetensors").write_bytes(b"extra")
            with self.assertRaises(ValueError):
                provision.manifest_for_snapshot(extra / "snapshot")

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
