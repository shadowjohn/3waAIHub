from __future__ import annotations

import hashlib
import json
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import ANY, Mock, call, patch

sys.path.insert(0, str(Path(__file__).parent))

import model_runtime
from model_runtime import (
    MODEL_FILES,
    MODEL_URLS,
    REAL_ESRGAN_COMMIT,
    REAL_ESRGAN_REPOSITORY,
    ModelRuntimeError,
    prepare_model,
    verify_ready,
)


EXPECTED_MODEL_ASSETS = {
    "RealESRGAN_x4plus.pth": {
        "url": "https://github.com/xinntao/Real-ESRGAN/releases/download/v0.1.0/RealESRGAN_x4plus.pth",
        "size": 67040989,
        "sha256": "4fa0d38905f75ac06eb49a7951b426670021be3018265fd191d2125df9d682f1",
    },
    "RealESRGAN_x4plus_anime_6B.pth": {
        "url": "https://github.com/xinntao/Real-ESRGAN/releases/download/v0.2.2.4/RealESRGAN_x4plus_anime_6B.pth",
        "size": 17938799,
        "sha256": "f872d837d3c90ed2e05227bed711af5671a6fd1c9f7d7e91c911a61f155e99da",
    },
    "realesr-animevideov3.pth": {
        "url": "https://github.com/xinntao/Real-ESRGAN/releases/download/v0.2.5.0/realesr-animevideov3.pth",
        "size": 2504012,
        "sha256": "b8a8376811077954d82ca3fcf476f1ac3da3e8a68a4f4d71363008000a18b75d",
    },
}
TEST_MODEL_PAYLOADS = {name: ("test-" + name).encode("ascii") for name in MODEL_FILES}
TEST_MODEL_ASSETS = {
    name: {
        "url": MODEL_URLS[name],
        "size": len(payload),
        "sha256": hashlib.sha256(payload).hexdigest(),
    }
    for name, payload in TEST_MODEL_PAYLOADS.items()
}


class FakeModel:
    def __init__(self) -> None:
        self.calls: list[str] = []

    def to(self, backend: str):
        self.calls.append("to:" + backend)
        return self

    def eval(self):
        self.calls.append("eval")
        return self

    def half(self):
        self.calls.append("half")
        return self


def write_ready(root: Path) -> dict:
    files = []
    for name, data in TEST_MODEL_PAYLOADS.items():
        (root / name).write_bytes(data)
        files.append({
            "path": name,
            "size": len(data),
            "sha256": hashlib.sha256(data).hexdigest(),
            "url": MODEL_URLS[name],
        })
    marker = {"repository": REAL_ESRGAN_REPOSITORY, "commit": REAL_ESRGAN_COMMIT, "files": files}
    (root / "ready.json").write_text(json.dumps(marker), encoding="utf-8")
    return marker


class ModelAssetConstantsTest(unittest.TestCase):
    def test_model_assets_pin_the_verified_snapshot_values(self) -> None:
        self.assertEqual(EXPECTED_MODEL_ASSETS, model_runtime.MODEL_ASSETS)

    def test_ready_marker_rejects_metadata_outside_the_fixed_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            write_ready(root)
            with self.assertRaisesRegex(ModelRuntimeError, "^model_load_failed$"):
                verify_ready(root)


class ModelRuntimeTest(unittest.TestCase):
    def setUp(self) -> None:
        self.asset_patch = patch.object(model_runtime, "MODEL_ASSETS", TEST_MODEL_ASSETS)
        self.file_patch = patch.object(model_runtime, "MODEL_FILES", tuple(TEST_MODEL_ASSETS))
        self.asset_patch.start()
        self.file_patch.start()

    def tearDown(self) -> None:
        self.file_patch.stop()
        self.asset_patch.stop()

    def assert_code(self, code: str, callback) -> None:
        with self.assertRaisesRegex(ModelRuntimeError, f"^{code}$"):
            callback()

    def test_ready_marker_requires_exact_pinned_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            marker = write_ready(root)
            self.assertEqual(verify_ready(root), marker)
            self.assertEqual(marker["commit"], "a4abfb2979a7bbff3f69f58f58ae324608821e27")
            self.assertEqual(set(row["path"] for row in marker["files"]), set(MODEL_FILES))

    def test_ready_marker_rejects_missing_or_tampered_assets_before_load(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.assert_code("model_not_present", lambda: verify_ready(root))
            marker = write_ready(root)
            cases = [
                ("repository", "other"),
                ("commit", "0" * 40),
            ]
            for key, value in cases:
                with self.subTest(key=key):
                    changed = dict(marker)
                    changed[key] = value
                    (root / "ready.json").write_text(json.dumps(changed), encoding="utf-8")
                    self.assert_code("model_load_failed", lambda: verify_ready(root))
            (root / "ready.json").write_text(json.dumps(marker), encoding="utf-8")
            (root / MODEL_FILES[0]).write_bytes(b"altered")
            self.assert_code("model_load_failed", lambda: verify_ready(root))

    def test_ready_marker_rejects_wrong_name_size_checksum_symlink_and_unlisted_file(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            marker = write_ready(root)
            for field, value in (("path", "wrong.pth"), ("size", 999), ("sha256", "0" * 64), ("url", "http://example.invalid/model.pth")):
                with self.subTest(field=field):
                    changed = json.loads(json.dumps(marker))
                    changed["files"][0][field] = value
                    (root / "ready.json").write_text(json.dumps(changed), encoding="utf-8")
                    self.assert_code("model_load_failed", lambda: verify_ready(root))
            (root / "ready.json").write_text(json.dumps(marker), encoding="utf-8")
            (root / "extra.pth").write_bytes(b"extra")
            self.assert_code("model_load_failed", lambda: verify_ready(root))
            (root / "extra.pth").unlink()
            target = root / MODEL_FILES[0]
            payload = target.read_bytes()
            target.unlink()
            (root / "outside.pth").write_bytes(payload)
            target.symlink_to(root / "outside.pth")
            self.assert_code("model_load_failed", lambda: verify_ready(root))

    def test_only_cuda_uses_half_precision(self) -> None:
        cpu = FakeModel()
        self.assertIs(prepare_model(cpu, "cpu"), cpu)
        self.assertEqual(cpu.calls, ["to:cpu", "eval"])
        cuda = FakeModel()
        self.assertIs(prepare_model(cuda, "cuda"), cuda)
        self.assertEqual(cuda.calls, ["to:cuda", "eval", "half"])
        self.assert_code("invalid_backend", lambda: prepare_model(FakeModel(), "auto"))

    def test_build_upsampler_uses_only_known_architectures(self) -> None:
        self.assertTrue(hasattr(model_runtime, "build_upsampler"), "model_runtime must expose the shared upsampler loader")
        rrdbnet = Mock(name="RRDBNet")
        srvgg = Mock(name="SRVGGNetCompact")
        realesrganer = Mock(name="RealESRGANer")
        modules = {
            "basicsr": types.ModuleType("basicsr"),
            "basicsr.archs": types.ModuleType("basicsr.archs"),
            "basicsr.archs.rrdbnet_arch": types.ModuleType("basicsr.archs.rrdbnet_arch"),
            "realesrgan": types.ModuleType("realesrgan"),
            "realesrgan.archs": types.ModuleType("realesrgan.archs"),
            "realesrgan.archs.srvgg_arch": types.ModuleType("realesrgan.archs.srvgg_arch"),
        }
        modules["basicsr.archs.rrdbnet_arch"].RRDBNet = rrdbnet
        modules["realesrgan"].RealESRGANer = realesrganer
        modules["realesrgan.archs.srvgg_arch"].SRVGGNetCompact = srvgg
        model_path = Path("/models/pinned.pth")

        with patch.dict(sys.modules, modules):
            for alias in ("realesrgan-x4plus", "realesrgan-x4plus-anime", "realesr-animevideov3-x4"):
                for backend, half in (("cpu", False), ("cuda", True)):
                    model_runtime.build_upsampler(alias, backend, model_path)
                    self.assertEqual(
                        call(scale=4, model_path=str(model_path), model=ANY, tile=0, tile_pad=10, pre_pad=0, half=half, device=backend),
                        realesrganer.call_args,
                    )

            self.assertEqual([
                call(num_in_ch=3, num_out_ch=3, num_feat=64, num_block=23, num_grow_ch=32, scale=4),
                call(num_in_ch=3, num_out_ch=3, num_feat=64, num_block=23, num_grow_ch=32, scale=4),
                call(num_in_ch=3, num_out_ch=3, num_feat=64, num_block=6, num_grow_ch=32, scale=4),
                call(num_in_ch=3, num_out_ch=3, num_feat=64, num_block=6, num_grow_ch=32, scale=4),
            ], rrdbnet.call_args_list)
            self.assertEqual([
                call(num_in_ch=3, num_out_ch=3, num_feat=64, num_conv=16, upscale=4, act_type="prelu"),
                call(num_in_ch=3, num_out_ch=3, num_feat=64, num_conv=16, upscale=4, act_type="prelu"),
            ], srvgg.call_args_list)
            with self.assertRaisesRegex(ModelRuntimeError, "^invalid_backend$"):
                model_runtime.build_upsampler("realesrgan-x4plus", "auto", model_path)

    def test_docker_installs_pinned_torch_before_disabling_build_isolation(self) -> None:
        dockerfile = (Path(__file__).parent / "Dockerfile").read_text(encoding="utf-8")
        requirements = (Path(__file__).parent / "requirements.txt").read_text(encoding="utf-8")
        torch_install = "python3 -m pip install --extra-index-url https://download.pytorch.org/whl/cu128 torch==2.9.1+cu128 torchvision==0.24.1+cu128"
        source_install = "python3 -m pip install --no-build-isolation -r requirements.txt"
        self.assertIn(torch_install, dockerfile)
        self.assertIn(source_install, dockerfile)
        self.assertLess(dockerfile.index(torch_install), dockerfile.index(source_install))
        self.assertIn("COPY Dockerfile image_contract.py", dockerfile)
        self.assertIn("libgl1 libglib2.0-0", dockerfile)
        self.assertIn("from torchvision.transforms.functional_tensor import rgb_to_grayscale", dockerfile)
        self.assertIn("from torchvision.transforms.functional import rgb_to_grayscale", dockerfile)
        self.assertIn("python3 -c 'import cv2; import realesrgan'", dockerfile)
        self.assertLess(dockerfile.index(source_install), dockerfile.index("functional_tensor"))
        self.assertLess(dockerfile.index("functional_tensor"), dockerfile.index("import cv2; import realesrgan"))
        self.assertNotIn("pip install --upgrade pip setuptools wheel", dockerfile)
        for requirement in (
            "basicsr==1.4.2",
            "facexlib==0.3.0",
            "gfpgan==1.3.8",
            "opencv-python==4.10.0.84",
            "tqdm==4.67.1",
            "addict==2.4.0",
            "future==1.0.0",
            "lmdb==1.6.2",
            "pyyaml==6.0.2",
            "requests==2.32.3",
            "scikit-image==0.24.0",
            "scipy==1.13.1",
            "tb-nightly==2.18.0a20240827",
            "yapf==0.43.0",
            "cython==3.0.11",
        ):
            self.assertIn(requirement, requirements)


if __name__ == "__main__":
    unittest.main()
