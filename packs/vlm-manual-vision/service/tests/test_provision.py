from __future__ import annotations

import hashlib
import json
import os
import stat
import sys
import tempfile
import threading
import unittest
from pathlib import Path
from unittest.mock import patch


SERVICE_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SERVICE_DIR))
import provision  # noqa: E402


TEST_REVISION = "f" * 40


def write_snapshot(root: Path, *, marker: bool = True) -> Path:
    snapshot = root / "revisions" / TEST_REVISION / "snapshot"
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
            "snapshot": f"revisions/{TEST_REVISION}/snapshot",
            "files": [{"path": name, "sha256": hashlib.sha256(data).hexdigest()} for name, data in sorted(files.items())],
        }), encoding="utf-8")
    return snapshot


def write_downloaded_snapshot(snapshot: Path) -> None:
    snapshot.mkdir(parents=True, exist_ok=True)
    files = {
        "added_tokens.json": b"{}", "config.json": b"{}", "generation_config.json": b"{}",
        "model-00001-of-00002.safetensors": b"weights-one", "model-00002-of-00002.safetensors": b"weights-two",
        "model.safetensors.index.json": json.dumps({"weight_map": {"one": "model-00001-of-00002.safetensors", "two": "model-00002-of-00002.safetensors"}}).encode(),
        "preprocessor_config.json": b"{}", "special_tokens_map.json": b"{}", "tokenizer.json": b"{}",
        "tokenizer.model": b"tokenizer", "tokenizer_config.json": b"{}",
    }
    for name, data in files.items():
        (snapshot / name).write_bytes(data)


def write_current_marker(root: Path, revision: str) -> None:
    snapshot = root / "revisions" / revision / "snapshot"
    manifest = provision.manifest_for_snapshot(snapshot)
    manifest["snapshot"] = f"revisions/{revision}/snapshot"
    (root / "verified-snapshot.json").write_text(json.dumps(manifest), encoding="utf-8")


class ProvisionTests(unittest.TestCase):
    def setUp(self) -> None:
        self.root_user = patch.object(provision.os, "geteuid", return_value=0)
        self.root_user.start()

    def tearDown(self) -> None:
        self.root_user.stop()

    def test_unprivileged_provision_fails_before_creating_the_model_root(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            environment = {
                "MANUAL_VISION_MODEL": provision.MODEL_ID, "MANUAL_VISION_MODEL_REVISION": "a" * 40,
                "MANUAL_VISION_TORCH_DTYPE": "float16", "MANUAL_VISION_DEVICE": "cuda", "HF_TOKEN": "secret",
                "MANUAL_VISION_MODEL_DIR": str(root),
            }
            with patch.object(provision.os, "geteuid", return_value=10001), \
                 self.assertRaisesRegex(PermissionError, "root one-shot"):
                provision.provision_snapshot(environment, snapshot_download=lambda **_kwargs: self.fail("download must not start"))
            self.assertFalse(root.exists())

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
            self.assertEqual(complete / "revisions" / TEST_REVISION / "snapshot", provision.verify_published_snapshot(complete))
            try:
                (complete / "revisions" / TEST_REVISION / "snapshot" / "linked.json").symlink_to(complete / "revisions" / TEST_REVISION / "snapshot" / "config.json")
            except OSError as exc:
                self.skipTest(f"symlinks unavailable: {exc}")
            with self.assertRaises(ValueError):
                provision.verify_published_snapshot(complete)
            (complete / "revisions" / TEST_REVISION / "snapshot" / "linked.json").unlink()
            (complete / "revisions" / TEST_REVISION / "snapshot" / "model-00001-of-00002.safetensors").write_bytes(b"altered")
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
            (complete / "revisions" / TEST_REVISION / "snapshot" / "preprocessor_config.json").unlink()
            with self.assertRaises(ValueError):
                provision.verify_published_snapshot(complete)
            write_snapshot(root / "shards")
            shards = root / "shards"
            (shards / "revisions" / TEST_REVISION / "snapshot" / "model-00002-of-00002.safetensors").unlink()
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
            (extra / "revisions" / TEST_REVISION / "snapshot" / "unindexed.safetensors").write_bytes(b"extra")
            with self.assertRaises(ValueError):
                provision.manifest_for_snapshot(extra / "revisions" / TEST_REVISION / "snapshot")

    def test_publish_writes_marker_only_after_every_staged_file_hash_validates(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            staging = Path(temporary) / "staging"
            snapshot = write_snapshot(staging, marker=False)
            manifest = provision.manifest_for_snapshot(snapshot, f"revisions/{TEST_REVISION}/snapshot")
            self.assertFalse((staging / "verified-snapshot.json").exists())
            provision.write_verified_marker(staging, manifest)
            self.assertEqual(snapshot, provision.verify_published_snapshot(staging))
            (snapshot / "model-00001-of-00002.safetensors").write_bytes(b"changed")
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

    def test_candidate_failure_preserves_old_revision_and_marker_while_success_publishes_only_current_marker(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            old_revision, new_revision = "a" * 40, "b" * 40
            write_downloaded_snapshot(root / "revisions" / old_revision / "snapshot")
            write_current_marker(root, old_revision)
            old_marker = (root / "verified-snapshot.json").read_bytes()
            environment = {
                "MANUAL_VISION_MODEL": provision.MODEL_ID, "MANUAL_VISION_MODEL_REVISION": new_revision,
                "MANUAL_VISION_TORCH_DTYPE": "float16", "MANUAL_VISION_DEVICE": "cuda", "HF_TOKEN": "secret",
                "MANUAL_VISION_MODEL_DIR": str(root),
            }
            with patch.object(provision.os, "chown"), patch.object(provision.os, "chmod"), self.assertRaises(RuntimeError):
                provision.provision_snapshot(environment, snapshot_download=lambda **_kwargs: (_ for _ in ()).throw(RuntimeError("download failed")))
            self.assertEqual(old_marker, (root / "verified-snapshot.json").read_bytes())
            self.assertTrue((root / "revisions" / old_revision / "snapshot" / "config.json").is_file())

            def download(**kwargs: object) -> None:
                write_downloaded_snapshot(Path(str(kwargs["local_dir"])))

            with patch.object(provision.os, "chown"), patch.object(provision.os, "chmod"):
                provision.provision_snapshot(environment, snapshot_download=download)
            marker = json.loads((root / "verified-snapshot.json").read_text(encoding="utf-8"))
            self.assertEqual(f"revisions/{new_revision}/snapshot", marker["snapshot"])
            self.assertTrue((root / "revisions" / old_revision / "snapshot").is_dir())
            self.assertTrue((root / "revisions" / new_revision / "snapshot").is_dir())

    def test_publisher_serializes_and_makes_published_revision_readable_by_runtime_user(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            root.mkdir()
            entered = threading.Event()

            def contender() -> None:
                with provision.publisher_lock(root):
                    entered.set()

            with provision.publisher_lock(root):
                thread = threading.Thread(target=contender)
                thread.start()
                self.assertFalse(entered.wait(0.1))
            thread.join(timeout=1)
            self.assertTrue(entered.is_set())

            revision = "c" * 40
            environment = {
                "MANUAL_VISION_MODEL": provision.MODEL_ID, "MANUAL_VISION_MODEL_REVISION": revision,
                "MANUAL_VISION_TORCH_DTYPE": "float16", "MANUAL_VISION_DEVICE": "cuda", "HF_TOKEN": "secret",
                "MANUAL_VISION_MODEL_DIR": str(root),
            }
            owners: list[tuple[str, int, int]] = []
            modes: list[tuple[str, int]] = []
            with patch.object(provision.os, "chown", side_effect=lambda path, uid, gid: owners.append((str(path), uid, gid))), \
                 patch.object(provision.os, "chmod", side_effect=lambda path, mode: modes.append((str(path), mode))):
                provision.provision_snapshot(environment, snapshot_download=lambda **kwargs: write_downloaded_snapshot(Path(str(kwargs["local_dir"]))))
            self.assertTrue(owners and all((uid, gid) == (10001, 10001) for _path, uid, gid in owners))
            self.assertIn(0o550, [mode for _path, mode in modes])
            self.assertIn(0o440, [mode for _path, mode in modes])

    def test_runtime_modes_are_traversable_readable_and_never_world_writable_without_mocking_chown(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            file_path = root / "revisions" / ("e" * 40) / "snapshot" / "config.json"
            file_path.parent.mkdir(parents=True)
            file_path.write_text("{}", encoding="utf-8")
            provision._make_runtime_readable(root, owner_uid=os.getuid(), owner_gid=os.getgid())
            for directory in (root, root / "revisions", file_path.parents[1], file_path.parent):
                info = directory.stat()
                self.assertEqual((os.getuid(), os.getgid()), (info.st_uid, info.st_gid))
                self.assertEqual(0o550, stat.S_IMODE(info.st_mode))
            info = file_path.stat()
            self.assertEqual((os.getuid(), os.getgid()), (info.st_uid, info.st_gid))
            self.assertEqual(0o440, stat.S_IMODE(info.st_mode))

    def test_post_publish_verification_failure_cannot_remove_the_marker_target(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            revision = "d" * 40
            environment = {
                "MANUAL_VISION_MODEL": provision.MODEL_ID, "MANUAL_VISION_MODEL_REVISION": revision,
                "MANUAL_VISION_TORCH_DTYPE": "float16", "MANUAL_VISION_DEVICE": "cuda", "HF_TOKEN": "secret",
                "MANUAL_VISION_MODEL_DIR": str(root),
            }
            with patch.object(provision.os, "chown"), patch.object(provision.os, "chmod"), \
                 patch.object(provision, "verify_published_snapshot", side_effect=AssertionError("no post-publish reverify")):
                published = provision.provision_snapshot(
                    environment,
                    snapshot_download=lambda **kwargs: write_downloaded_snapshot(Path(str(kwargs["local_dir"]))),
                )
            self.assertEqual(root / "revisions" / revision / "snapshot", published)
            marker = json.loads((root / "verified-snapshot.json").read_text(encoding="utf-8"))
            self.assertEqual(f"revisions/{revision}/snapshot", marker["snapshot"])
            self.assertTrue((root / marker["snapshot"] / "config.json").is_file())

    def test_orphaned_final_revision_is_recovered_without_deleting_a_live_marker_target(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            revision = "e" * 40
            write_downloaded_snapshot(root / "revisions" / revision / "snapshot")
            environment = {
                "MANUAL_VISION_MODEL": provision.MODEL_ID, "MANUAL_VISION_MODEL_REVISION": revision,
                "MANUAL_VISION_TORCH_DTYPE": "float16", "MANUAL_VISION_DEVICE": "cuda", "HF_TOKEN": "secret",
                "MANUAL_VISION_MODEL_DIR": str(root),
            }
            with patch.object(provision.os, "chown"), patch.object(provision.os, "chmod"):
                provision.provision_snapshot(
                    environment,
                    snapshot_download=lambda **kwargs: write_downloaded_snapshot(Path(str(kwargs["local_dir"]))),
                )
            marker = json.loads((root / "verified-snapshot.json").read_text(encoding="utf-8"))
            self.assertEqual(f"revisions/{revision}/snapshot", marker["snapshot"])
            self.assertTrue((root / marker["snapshot"] / "config.json").is_file())

    def test_published_final_revision_is_retained_on_same_revision_retry(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            revision = "f" * 40
            write_downloaded_snapshot(root / "revisions" / revision / "snapshot")
            write_current_marker(root, revision)
            environment = {
                "MANUAL_VISION_MODEL": provision.MODEL_ID, "MANUAL_VISION_MODEL_REVISION": revision,
                "MANUAL_VISION_TORCH_DTYPE": "float16", "MANUAL_VISION_DEVICE": "cuda", "HF_TOKEN": "secret",
                "MANUAL_VISION_MODEL_DIR": str(root),
            }
            with patch.object(provision.os, "chown"), patch.object(provision.os, "chmod"), \
                 self.assertRaisesRegex(ValueError, "already published"):
                provision.provision_snapshot(environment, snapshot_download=lambda **_kwargs: self.fail("download must not start"))
            self.assertTrue((root / "revisions" / revision / "snapshot" / "config.json").is_file())


if __name__ == "__main__":
    unittest.main()
