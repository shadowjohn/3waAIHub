from __future__ import annotations

import hashlib
import importlib.util
import io
import json
import subprocess
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


SERVICE_DIR = Path(__file__).resolve().parent
PROVISION_FILE = SERVICE_DIR / "provision_offline_assets.py"
WRAPPER_FILE = SERVICE_DIR.parent / "jobs" / "provision_offline_models.sh"


def load_provisioner():
    spec = importlib.util.spec_from_file_location("speech_fast_zh_provision", PROVISION_FILE)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


class ProvisionOfflineAssetsTests(unittest.TestCase):
    def provisioner(self):
        if not PROVISION_FILE.exists():
            self.skipTest("provisioner has not been implemented")
        return load_provisioner()

    def make_archive(self, path: Path, module, *, extra: str | None = None, symlink: bool = False) -> dict[str, str]:
        contents = {
            "README.md": b"readme",
            "tokens.txt": b"tokens",
            "add-model-metadata.py": b"metadata",
            "config.yaml": b"config",
            "generate-tokens.py": b"tokens",
            "test_wavs/2-zh-en.wav": b"wav",
            "test_wavs/5-henan.wav": b"wav",
            "test_wavs/8k.wav": b"wav",
            "test_wavs/0.wav": b"wav",
            "test_wavs/1.wav": b"wav",
            "test_wavs/4-tianjin.wav": b"wav",
            "test_wavs/3-sichuan.wav": b"wav",
            "am.mvn": b"mvn",
            "model.int8.onnx": b"model",
        }
        with tarfile.open(path, "w:bz2") as archive:
            for directory in (module.ARCHIVE_ROOT + "/", module.ARCHIVE_ROOT + "/test_wavs/"):
                info = tarfile.TarInfo(directory)
                info.type = tarfile.DIRTYPE
                archive.addfile(info)
            for name, content in contents.items():
                archive_name = f"{module.ARCHIVE_ROOT}/{name}"
                info = tarfile.TarInfo(archive_name)
                if symlink and name == "tokens.txt":
                    info.type = tarfile.SYMTYPE
                    info.linkname = "elsewhere"
                    archive.addfile(info)
                else:
                    info.size = len(content)
                    archive.addfile(info, io.BytesIO(content))
            if extra is not None:
                content = b"unexpected"
                info = tarfile.TarInfo(extra)
                info.size = len(content)
                archive.addfile(info, io.BytesIO(content))
        return {
            "model.int8.onnx": hashlib.sha256(contents["model.int8.onnx"]).hexdigest(),
            "tokens.txt": hashlib.sha256(contents["tokens.txt"]).hexdigest(),
        }

    def write_ready_model(self, root: Path, module, *, model: bytes = b"old-model", tokens: bytes = b"old-tokens") -> dict[str, str]:
        root.mkdir()
        (root / "model.int8.onnx").write_bytes(model)
        (root / "tokens.txt").write_bytes(tokens)
        hashes = {
            "model.int8.onnx": hashlib.sha256(model).hexdigest(),
            "tokens.txt": hashlib.sha256(tokens).hexdigest(),
        }
        marker = {
            "schema": "aihub-speech-fast-zh/v1",
            "model": module.MODEL,
            "model_sha256": hashes["model.int8.onnx"],
            "tokens_sha256": hashes["tokens.txt"],
        }
        (root / module.MARKER_NAME).write_text(json.dumps(marker), encoding="utf-8")
        return hashes

    def test_cli_exists_and_accepts_an_archive_option(self) -> None:
        result = subprocess.run(
            [sys.executable, str(PROVISION_FILE), "--help"],
            check=False,
            capture_output=True,
            text=True,
        )

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("--archive", result.stdout)

    def test_provision_validates_then_publishes_files_and_ready_marker(self) -> None:
        module = self.provisioner()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            archive = Path(temporary) / "model.tar.bz2"
            expected_files = self.make_archive(archive, module)
            archive_hash = hashlib.sha256(archive.read_bytes()).hexdigest()

            with patch.object(module, "ARCHIVE_SHA256", archive_hash), patch.object(module, "MODEL_HASHES", expected_files):
                marker = module.provision(root, archive)

            self.assertEqual(marker, {
                "schema": "aihub-speech-fast-zh/v1",
                "model": "sherpa-onnx-paraformer-zh-small-2024-03-09",
                "model_sha256": expected_files["model.int8.onnx"],
                "tokens_sha256": expected_files["tokens.txt"],
            })
            self.assertEqual((root / "model.int8.onnx").read_bytes(), b"model")
            self.assertEqual((root / "tokens.txt").read_bytes(), b"tokens")
            self.assertEqual(json.loads((root / module.MARKER_NAME).read_text(encoding="utf-8")), marker)
            self.assertEqual((root.stat().st_mode & 0o777), 0o755)
            self.assertEqual(((root / "model.int8.onnx").stat().st_mode & 0o777), 0o644)
            self.assertEqual(((root / "tokens.txt").stat().st_mode & 0o777), 0o644)
            self.assertFalse(list(root.glob(".stage-*")))

    def test_failed_reprovision_preserves_the_existing_ready_model(self) -> None:
        module = self.provisioner()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            old_hashes = self.write_ready_model(root, module)
            before = {path.name: path.read_bytes() for path in root.iterdir()}
            archive = Path(temporary) / "invalid.tar.bz2"
            self.make_archive(archive, module, extra="unexpected")
            archive_hash = hashlib.sha256(archive.read_bytes()).hexdigest()

            with patch.object(module, "ARCHIVE_SHA256", archive_hash), patch.object(module, "MODEL_HASHES", old_hashes):
                with self.assertRaisesRegex(RuntimeError, "^archive_layout_invalid$"):
                    module.provision(root, archive)

            self.assertEqual({path.name: path.read_bytes() for path in root.iterdir()}, before)

    def test_successful_reprovision_uses_a_complete_directory_publisher(self) -> None:
        module = self.provisioner()
        self.assertTrue(hasattr(module, "publish_model_directory"))
        if not hasattr(module, "publish_model_directory"):
            return
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            self.write_ready_model(root, module)
            archive = Path(temporary) / "model.tar.bz2"
            new_hashes = self.make_archive(archive, module)
            archive_hash = hashlib.sha256(archive.read_bytes()).hexdigest()
            observed: list[dict[str, bytes]] = []
            publisher = module.publish_model_directory

            def publish(stage: Path, destination: Path) -> None:
                observed.append({path.name: path.read_bytes() for path in destination.iterdir()})
                publisher(stage, destination)
                observed.append({path.name: path.read_bytes() for path in destination.iterdir()})

            with patch.object(module, "ARCHIVE_SHA256", archive_hash), patch.object(module, "MODEL_HASHES", new_hashes), patch.object(module, "publish_model_directory", side_effect=publish):
                module.provision(root, archive)

            self.assertEqual(observed[0]["model.int8.onnx"], b"old-model")
            self.assertEqual(observed[0]["tokens.txt"], b"old-tokens")
            self.assertIn(module.MARKER_NAME, observed[0])
            self.assertEqual(observed[1]["model.int8.onnx"], b"model")
            self.assertEqual(observed[1]["tokens.txt"], b"tokens")
            self.assertEqual(json.loads((root / module.MARKER_NAME).read_text(encoding="utf-8")), {
                "schema": "aihub-speech-fast-zh/v1",
                "model": module.MODEL,
                "model_sha256": new_hashes["model.int8.onnx"],
                "tokens_sha256": new_hashes["tokens.txt"],
            })

    def test_provision_rejects_unsafe_symlink_members_before_publishing(self) -> None:
        module = self.provisioner()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            archive = Path(temporary) / "unsafe.tar.bz2"
            self.make_archive(archive, module, symlink=True)
            archive_hash = hashlib.sha256(archive.read_bytes()).hexdigest()

            with patch.object(module, "ARCHIVE_SHA256", archive_hash):
                with self.assertRaisesRegex(RuntimeError, "^archive_layout_invalid$"):
                    module.provision(root, archive)

            self.assertFalse((root / module.MARKER_NAME).exists())

    def test_provision_rejects_a_bad_archive_hash_before_extracting(self) -> None:
        module = self.provisioner()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            archive = Path(temporary) / "model.tar.bz2"
            self.make_archive(archive, module)

            with self.assertRaisesRegex(RuntimeError, "^archive_hash_invalid$"):
                module.provision(root, archive)

    def test_local_archive_must_be_absolute_and_regular(self) -> None:
        module = self.provisioner()

        with self.assertRaisesRegex(RuntimeError, "^archive_invalid$"):
            module.require_local_archive(Path("relative.tar.bz2"))

        with tempfile.TemporaryDirectory() as temporary:
            target = Path(temporary) / "archive.tar.bz2"
            target.write_bytes(b"archive")
            link = Path(temporary) / "archive-link.tar.bz2"
            link.symlink_to(target)
            with self.assertRaisesRegex(RuntimeError, "^archive_invalid$"):
                module.require_local_archive(link)

    def test_wrapper_rejects_symlinked_model_storage_before_docker(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            models = Path(temporary) / "models"
            models.mkdir()
            target = Path(temporary) / "target"
            target.mkdir()
            (models / "speech-fast-zh").symlink_to(target, target_is_directory=True)
            result = subprocess.run(
                ["bash", str(WRAPPER_FILE)],
                check=False,
                capture_output=True,
                text=True,
                env={"AIHUB_MODELS_DIR": str(models), "PATH": "/usr/bin:/bin"},
            )

        self.assertEqual(result.returncode, 64)
        self.assertIn("model storage path is unsafe", result.stderr)

    def test_wrapper_rejects_a_symlinked_final_model_directory(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            models = Path(temporary) / "models"
            parent = models / "speech-fast-zh"
            parent.mkdir(parents=True)
            target = Path(temporary) / "target"
            target.mkdir()
            (parent / "paraformer-zh-small-2024-03-09").symlink_to(target, target_is_directory=True)
            result = subprocess.run(
                ["bash", str(WRAPPER_FILE)],
                check=False,
                capture_output=True,
                text=True,
                env={"AIHUB_MODELS_DIR": str(models), "PATH": "/usr/bin:/bin"},
            )

        self.assertEqual(result.returncode, 64)
        self.assertIn("model storage path is unsafe", result.stderr)

    def test_wrapper_mounts_the_trusted_parent_not_the_final_model_directory(self) -> None:
        wrapper = WRAPPER_FILE.read_text(encoding="utf-8")

        self.assertIn('model_parent="$AIHUB_MODELS_DIR/speech-fast-zh"', wrapper)
        self.assertIn('src=$model_parent,dst=/models/speech-fast-zh', wrapper)
        self.assertIn('--model-dir /models/speech-fast-zh/paraformer-zh-small-2024-03-09', wrapper)
        self.assertIn('docker run --pull=never --rm', wrapper)


if __name__ == "__main__":
    unittest.main()
