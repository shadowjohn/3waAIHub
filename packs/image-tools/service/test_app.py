from __future__ import annotations

import asyncio
import hashlib
import io
import json
import os
import stat
import sys
import tempfile
import types
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import call, patch

from PIL import Image
import numpy as np

sys.path.insert(0, str(Path(__file__).parent))

import model_runtime
import colorize_runtime


class _FastAPI:
    def __init__(self, *args, **kwargs) -> None:
        pass

    def get(self, *args, **kwargs):
        return lambda function: function

    def post(self, *args, **kwargs):
        return lambda function: function


class _Response:
    def __init__(self, content=b"", media_type=None, headers=None, status_code=200) -> None:
        self.body = content if isinstance(content, bytes) else str(content).encode()
        self.media_type = media_type
        self.headers = dict(headers or {})
        self.status_code = status_code


class _JSONResponse(_Response):
    def __init__(self, content, status_code=200) -> None:
        super().__init__(json.dumps(content, separators=(",", ":")).encode(), "application/json", status_code=status_code)


fastapi = types.ModuleType("fastapi")
fastapi.FastAPI = _FastAPI
fastapi.File = lambda default=None: default
fastapi.Form = lambda default=None: default
fastapi.UploadFile = object
fastapi_concurrency = types.ModuleType("fastapi.concurrency")
async def _threadpool(function, *args, **kwargs):
    return function(*args, **kwargs)
fastapi_concurrency.run_in_threadpool = _threadpool
fastapi_responses = types.ModuleType("fastapi.responses")
fastapi_responses.JSONResponse = _JSONResponse
fastapi_responses.Response = _Response
sys.modules.update({"fastapi": fastapi, "fastapi.concurrency": fastapi_concurrency, "fastapi.responses": fastapi_responses})


def png_bytes(size: tuple[int, int] = (2, 3), color: tuple[int, int, int] = (255, 0, 0)) -> bytes:
    output = io.BytesIO()
    Image.new("RGB", size, color).save(output, format="PNG")
    return output.getvalue()


class FakeUpload:
    def __init__(self, data: bytes) -> None:
        self.data = data

    async def read(self, _limit: int | None = None) -> bytes:
        return self.data


class FakeUpsampler:
    def __init__(self) -> None:
        self.input_shapes: list[tuple[int, ...]] = []
        self.outscales: list[int] = []

    def enhance(self, pixels: np.ndarray, outscale: int) -> tuple[np.ndarray, None]:
        self.input_shapes.append(pixels.shape)
        self.outscales.append(outscale)
        height, width = pixels.shape[:2]
        return np.full((height * outscale, width * outscale, 3), (30, 20, 10), dtype=np.uint8), None


class ColorizeRuntimeTest(unittest.TestCase):
    def test_colorize_snapshot_requires_the_pinned_marker_and_weight_hash(self) -> None:
        payload = b"ddcolor fixture"
        asset = {
            **colorize_runtime.DDCOLOR_MODEL_ASSET,
            "size": len(payload),
            "sha256": hashlib.sha256(payload).hexdigest(),
        }
        with tempfile.TemporaryDirectory() as temporary, patch.object(colorize_runtime, "DDCOLOR_MODEL_ASSET", asset):
            root = Path(temporary) / "ddcolor"
            root.mkdir()
            (root / str(asset["path"])).write_bytes(payload)
            (root / "ready.json").write_text(json.dumps(colorize_runtime.ready_marker()), encoding="utf-8")
            self.assertEqual(colorize_runtime.ready_marker(), colorize_runtime.verify_ready(root))
            (root / str(asset["path"])).write_bytes(b"tampered")
            with self.assertRaisesRegex(colorize_runtime.ColorizeRuntimeError, "^model_load_failed$"):
                colorize_runtime.verify_ready(root)


class ColorizeProvisionTest(unittest.TestCase):
    def test_colorize_provision_publishes_a_readable_atomic_snapshot(self) -> None:
        import provision_colorize_assets as provisioner

        payload = b"ddcolor fixture"
        asset = {
            **colorize_runtime.DDCOLOR_MODEL_ASSET,
            "size": len(payload),
            "sha256": hashlib.sha256(payload).hexdigest(),
        }
        with tempfile.TemporaryDirectory() as temporary, patch.object(colorize_runtime, "DDCOLOR_MODEL_ASSET", asset), patch.object(
            provisioner, "DDCOLOR_MODEL_ASSET", asset
        ), patch.object(provisioner, "_download", side_effect=lambda destination: destination.write_bytes(payload)):
            root = Path(temporary) / "ddcolor"
            provisioner.provision(root)
            self.assertEqual(0o755, stat.S_IMODE(root.stat().st_mode))
            self.assertEqual(0o644, stat.S_IMODE((root / "ready.json").stat().st_mode))
            self.assertEqual(0o644, stat.S_IMODE((root / str(asset["path"])).stat().st_mode))


class AppTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        import app
        cls.app = app

    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name) / "models"
        self.root.mkdir()
        self.environment = patch.dict(os.environ, {"IMAGE_TOOLS_MODEL_DIR": str(self.root)}, clear=False)
        self.environment.start()
        self._reset_upsampler_cache()

    def tearDown(self) -> None:
        self._reset_upsampler_cache()
        self.environment.stop()
        self.temp.cleanup()

    def _reset_upsampler_cache(self) -> None:
        self.app._UPSAMPLER_CACHE = None
        self.app._COLORIZER_CACHE = None

    def response_body(self, response):
        return json.loads(response.body)

    def process(self, **kwargs):
        return asyncio.run(self.app.process_image(FakeUpload(png_bytes()), operation="upscale", **kwargs))

    def assert_png_response(self, response, *, model: str, backend: str, size: tuple[int, int]) -> None:
        self.assertEqual(200, response.status_code)
        self.assertEqual("image/png", response.media_type)
        headers = {key.lower(): value for key, value in response.headers.items()}
        self.assertEqual({
            "x-3waaihub-model",
            "x-3waaihub-backend",
            "x-3waaihub-elapsed-ms",
            "x-3waaihub-width",
            "x-3waaihub-height",
        }, set(headers))
        self.assertEqual({
            "x-3waaihub-model": model,
            "x-3waaihub-backend": backend,
            "x-3waaihub-width": str(size[0]),
            "x-3waaihub-height": str(size[1]),
        }, {key: value for key, value in headers.items() if key != "x-3waaihub-elapsed-ms"})
        self.assertTrue(headers["x-3waaihub-elapsed-ms"].isdigit())
        with Image.open(io.BytesIO(response.body)) as rendered:
            self.assertEqual(size, rendered.size)
            self.assertEqual((10, 20, 30), rendered.getpixel((0, 0)))

    def test_health_reports_l1_when_assets_are_missing_and_l5_after_recorded_benchmarks(self) -> None:
        unavailable = {
            "ok": True,
            "service": "image-tools",
            "ready": False,
            "runtime_level": "L1-contract",
            "runtime_ready": False,
        }
        for code in ("model_not_present", "model_load_failed"):
            with self.subTest(code=code), patch.object(self.app, "verify_ready", side_effect=self.app.ModelRuntimeError(code)):
                self.assertEqual(unavailable, self.app.health())
        with patch.object(self.app, "verify_ready", return_value={"commit": "pinned"}), patch.object(
            model_runtime,
            "build_upsampler",
            side_effect=AssertionError("health must not load models"),
        ) as build_upsampler:
            health = self.app.health()
        build_upsampler.assert_not_called()
        self.assertEqual({
            "ok": True,
            "service": "image-tools",
            "ready": True,
            "runtime_level": "L5-benchmark-ready",
            "runtime_ready": True,
        }, health)

    def test_process_rejects_symlinked_model_root_before_building(self) -> None:
        symlink_root = Path(self.temp.name) / "symlink-models"
        symlink_root.symlink_to(self.root, target_is_directory=True)

        def reject_symlink_root(root: Path) -> dict[str, object]:
            if root.is_symlink():
                raise self.app.ModelRuntimeError("model_not_present")
            return {}

        with patch.dict(os.environ, {"IMAGE_TOOLS_MODEL_DIR": str(symlink_root)}, clear=False), patch.object(
            self.app, "verify_ready", side_effect=reject_symlink_root
        ) as verify_ready, patch.object(
            model_runtime, "build_upsampler", side_effect=AssertionError("symlink root must not load a model")
        ) as build_upsampler:
            response = self.process(model="realesrgan-x4plus", backend="cpu")

        self.assertEqual(404, response.status_code)
        self.assertEqual("model_not_present", self.response_body(response)["error"])
        verify_ready.assert_called_once_with(symlink_root)
        build_upsampler.assert_not_called()

    def test_process_rejects_model_root_beneath_symlink_loop_before_building(self) -> None:
        loop = Path(self.temp.name) / "model-loop"
        loop.symlink_to(loop.name, target_is_directory=True)
        looped_root = loop / "models"

        with patch.dict(os.environ, {"IMAGE_TOOLS_MODEL_DIR": str(looped_root)}, clear=False), patch.object(
            self.app, "verify_ready", wraps=self.app.verify_ready
        ) as verify_ready, patch.object(
            model_runtime, "build_upsampler", side_effect=AssertionError("looped root must not load a model")
        ) as build_upsampler:
            response = self.process(model="realesrgan-x4plus", backend="cpu")

        self.assertEqual(404, response.status_code)
        self.assertEqual("model_not_present", self.response_body(response)["error"])
        verify_ready.assert_called_once_with(looped_root)
        build_upsampler.assert_not_called()

    def test_process_validates_canonical_root_below_symlinked_parent(self) -> None:
        canonical_root = Path(self.temp.name) / "canonical" / "models"
        canonical_root.mkdir(parents=True)
        symlinked_parent = Path(self.temp.name) / "symlinked-parent"
        symlinked_parent.symlink_to(canonical_root.parent, target_is_directory=True)
        configured_root = symlinked_parent / "models"
        upsampler = FakeUpsampler()

        with patch.dict(os.environ, {"IMAGE_TOOLS_MODEL_DIR": str(configured_root)}, clear=False), patch.object(
            self.app, "verify_ready", return_value={}
        ) as verify_ready, patch.object(
            model_runtime, "build_upsampler", return_value=upsampler
        ) as build_upsampler:
            response = self.process(model="realesrgan-x4plus", backend="cpu")

        verify_ready.assert_called_once_with(canonical_root)
        build_upsampler.assert_called_once_with("realesrgan-x4plus", "cpu", canonical_root / "RealESRGAN_x4plus.pth")
        self.assert_png_response(response, model="realesrgan-x4plus", backend="cpu", size=(8, 12))

    def test_process_bounds_direct_inference_with_a_cancellable_timer(self) -> None:
        upsampler = FakeUpsampler()
        with patch.object(self.app, "verify_ready", return_value={}), patch.object(
            model_runtime, "build_upsampler", return_value=upsampler
        ), patch.object(self.app.threading, "Timer") as timer, patch.object(
            self.app.os, "_exit", side_effect=AssertionError("timer must not fire in this test")
        ) as exit_container:
            response = self.process(model="realesrgan-x4plus", backend="cpu")

        timer.assert_called_once()
        timeout, callback = timer.call_args.args
        self.assertEqual(900, timeout)
        self.assertEqual("_exit_stalled_inference", callback.__name__)
        self.assertTrue(timer.return_value.daemon)
        timer.return_value.start.assert_called_once_with()
        timer.return_value.cancel.assert_called_once_with()
        exit_container.assert_not_called()
        self.assert_png_response(response, model="realesrgan-x4plus", backend="cpu", size=(8, 12))

    def test_process_reuses_in_process_upsampler_for_matching_selection(self) -> None:
        upsampler = FakeUpsampler()
        with patch.object(self.app, "verify_ready", return_value={}) as verify_ready, patch.object(model_runtime, "verify_ready", return_value={}), patch.object(
            model_runtime, "build_upsampler", return_value=upsampler
        ) as build_upsampler, patch("subprocess.run", side_effect=AssertionError("sync process must not use subprocesses")) as run, patch(
            "tempfile.mkdtemp", wraps=tempfile.mkdtemp
        ) as workspace:
            first = self.process(model="realesrgan-x4plus", backend="cpu", outscale="2")
            second = self.process(model="realesrgan-x4plus", backend="cpu", outscale="2")

        run.assert_not_called()
        workspace.assert_not_called()
        verify_ready.assert_called_once_with(self.root)
        build_upsampler.assert_called_once_with("realesrgan-x4plus", "cpu", self.root / "RealESRGAN_x4plus.pth")
        self.assertEqual([(3, 2, 3), (3, 2, 3)], upsampler.input_shapes)
        self.assertEqual([2, 2], upsampler.outscales)
        self.assert_png_response(first, model="realesrgan-x4plus", backend="cpu", size=(4, 6))
        self.assert_png_response(second, model="realesrgan-x4plus", backend="cpu", size=(4, 6))
        self.assertEqual([], list(self.root.iterdir()))

    def test_process_colorizes_with_the_fixed_ddcolor_modelscope_contract(self) -> None:
        colorized = png_bytes(color=(10, 20, 30))
        with patch.object(self.app, "_run_colorize", return_value=(colorized, {
            "model": "ddcolor-modelscope",
            "backend": "cpu",
            "width": 2,
            "height": 3,
        }), create=True) as colorize:
            response = asyncio.run(self.app.process_image(
                FakeUpload(png_bytes()), operation="colorize", model=None, backend="cpu"
            ))

        colorize.assert_called_once_with(png_bytes(), backend="cpu")
        self.assert_png_response(response, model="ddcolor-modelscope", backend="cpu", size=(2, 3))

    def test_process_replaces_the_single_cached_upsampler_after_a_to_b_to_a(self) -> None:
        first_a, b, second_a = FakeUpsampler(), FakeUpsampler(), FakeUpsampler()
        with patch.object(self.app, "verify_ready", return_value={}) as verify_ready, patch.object(model_runtime, "verify_ready", return_value={}), patch.object(
            model_runtime, "build_upsampler", side_effect=[first_a, b, second_a]
        ) as build_upsampler:
            first_a_response = self.process(model="realesrgan-x4plus", backend="cpu")
            b_response = self.process(model="realesrgan-x4plus-anime", backend="cpu")
            second_a_response = self.process(model="realesrgan-x4plus", backend="cpu")

        self.assertEqual(3, build_upsampler.call_count)
        self.assertEqual([
            call("realesrgan-x4plus", "cpu", self.root / "RealESRGAN_x4plus.pth"),
            call("realesrgan-x4plus-anime", "cpu", self.root / "RealESRGAN_x4plus_anime_6B.pth"),
            call("realesrgan-x4plus", "cpu", self.root / "RealESRGAN_x4plus.pth"),
        ], build_upsampler.call_args_list)
        self.assertEqual([call(self.root), call(self.root), call(self.root)], verify_ready.call_args_list)
        self.assertEqual([4], first_a.outscales)
        self.assertEqual([4], b.outscales)
        self.assertEqual([4], second_a.outscales)
        self.assert_png_response(first_a_response, model="realesrgan-x4plus", backend="cpu", size=(8, 12))
        self.assert_png_response(b_response, model="realesrgan-x4plus-anime", backend="cpu", size=(8, 12))
        self.assert_png_response(second_a_response, model="realesrgan-x4plus", backend="cpu", size=(8, 12))

    def test_process_cache_key_uses_model_dir_and_resolved_backend(self) -> None:
        alternate_root = Path(self.temp.name) / "alternate-models"
        alternate_root.mkdir()
        cpu_first, cpu_second, cuda = FakeUpsampler(), FakeUpsampler(), FakeUpsampler()
        with patch.object(self.app, "verify_ready", return_value={}) as verify_ready, patch.object(model_runtime, "verify_ready", return_value={}), patch.object(
            self.app, "cuda_available", side_effect=[False, False, True]
        ), patch.object(model_runtime, "build_upsampler", side_effect=[cpu_first, cpu_second, cuda]) as build_upsampler:
            cpu_auto = self.process(model="realesrgan-x4plus", backend="auto")
            cpu_spelling = self.process(model="realesrgan-x4plus", backend="cpu")
            with patch.dict(os.environ, {"IMAGE_TOOLS_MODEL_DIR": str(alternate_root)}, clear=False):
                alternate_cpu = self.process(model="realesrgan-x4plus", backend="auto")
                alternate_cuda = self.process(model="realesrgan-x4plus", backend="auto")

        self.assertEqual([
            call("realesrgan-x4plus", "cpu", self.root / "RealESRGAN_x4plus.pth"),
            call("realesrgan-x4plus", "cpu", alternate_root / "RealESRGAN_x4plus.pth"),
            call("realesrgan-x4plus", "cuda", alternate_root / "RealESRGAN_x4plus.pth"),
        ], build_upsampler.call_args_list)
        self.assertEqual([call(self.root), call(alternate_root), call(alternate_root)], verify_ready.call_args_list)
        self.assertEqual([4, 4], cpu_first.outscales)
        self.assertEqual([4], cpu_second.outscales)
        self.assertEqual([4], cuda.outscales)
        self.assert_png_response(cpu_auto, model="realesrgan-x4plus", backend="cpu", size=(8, 12))
        self.assert_png_response(cpu_spelling, model="realesrgan-x4plus", backend="cpu", size=(8, 12))
        self.assert_png_response(alternate_cpu, model="realesrgan-x4plus", backend="cpu", size=(8, 12))
        self.assert_png_response(alternate_cuda, model="realesrgan-x4plus", backend="cuda", size=(8, 12))

    def test_process_forwards_explicit_outscale_and_reports_scaled_dimensions(self) -> None:
        upsampler = FakeUpsampler()
        with patch.object(self.app, "verify_ready", return_value={}), patch.object(model_runtime, "verify_ready", return_value={}), patch.object(
            model_runtime, "build_upsampler", return_value=upsampler
        ) as build_upsampler:
            response = self.process(model="realesrgan-x4plus", backend="cpu", outscale="2")

        build_upsampler.assert_called_once_with("realesrgan-x4plus", "cpu", self.root / "RealESRGAN_x4plus.pth")
        self.assertEqual([2], upsampler.outscales)
        self.assert_png_response(response, model="realesrgan-x4plus", backend="cpu", size=(4, 6))

    def test_process_uses_model_native_default_outscale(self) -> None:
        upsampler = FakeUpsampler()
        with patch.object(self.app, "verify_ready", return_value={}), patch.object(model_runtime, "verify_ready", return_value={}), patch.object(
            model_runtime, "build_upsampler", return_value=upsampler
        ):
            response = self.process(model="realesrgan-x4plus", backend="cpu")

        self.assertEqual([4], upsampler.outscales)
        self.assert_png_response(response, model="realesrgan-x4plus", backend="cpu", size=(8, 12))

    def test_process_maps_model_build_and_enhance_failures_to_sanitized_errors(self) -> None:
        errors = [
            (model_runtime.ModelRuntimeError("model_not_present"), "model_not_present", 404),
            (model_runtime.ModelRuntimeError("model_load_failed"), "model_load_failed", 503),
        ]
        for error, code, status in errors:
            with self.subTest(code=code), patch.object(self.app, "verify_ready", return_value={}), patch.object(model_runtime, "verify_ready", return_value={}), patch.object(
                model_runtime, "build_upsampler", side_effect=error
            ):
                response = self.process(model="realesrgan-x4plus", backend="cpu")
                self._reset_upsampler_cache()
                self.assertEqual(status, response.status_code)
                self.assertEqual(code, self.response_body(response)["error"])
                self.assertNotIn("/", response.body.decode())

        with patch.object(self.app, "cuda_available", return_value=False):
            response = self.process(model="realesrgan-x4plus", backend="cuda")
        self.assertEqual("backend_unavailable", self.response_body(response)["error"])

        class FailingUpsampler:
            def enhance(self, _pixels, outscale):
                raise RuntimeError(f"private /models/path at {outscale}")

        failing_upsampler = FailingUpsampler()
        with patch.object(self.app, "verify_ready", return_value={}), patch.object(model_runtime, "verify_ready", return_value={}), patch.object(
            model_runtime, "build_upsampler", return_value=failing_upsampler
        ) as build_upsampler:
            response = self.process(model="realesrgan-x4plus", backend="cpu")
        build_upsampler.assert_called_once_with("realesrgan-x4plus", "cpu", self.root / "RealESRGAN_x4plus.pth")
        self.assertEqual(500, response.status_code)
        self.assertEqual("inference_failed", self.response_body(response)["error"])
        body = response.body.decode()
        self.assertNotIn("private", body)
        self.assertNotIn("/models/path", body)
        self.assertNotIn("RuntimeError", body)


class ProvisionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        import provision_offline_assets as provisioner
        cls.provisioner = provisioner

    def _assets_fixture(self) -> tuple[dict[str, dict[str, object]], dict[str, bytes]]:
        payloads = {
            "RealESRGAN_x4plus.pth": b"test-x4plus",
            "RealESRGAN_x4plus_anime_6B.pth": b"test-anime",
            "realesr-animevideov3.pth": b"test-video",
        }
        return ({
            name: {
                "url": "https://github.com/xinntao/Real-ESRGAN/releases/download/test/" + name,
                "size": len(payload),
                "sha256": hashlib.sha256(payload).hexdigest(),
            }
            for name, payload in payloads.items()
        }, payloads)

    def test_stage_is_atomic_private_and_keeps_valid_existing_snapshot_on_failure(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            target = Path(temporary) / "realesrgan"
            target.mkdir()
            (target / "sentinel").write_text("valid", encoding="utf-8")
            assets, payloads = self._assets_fixture()

            def fetch(url: str, destination: Path) -> None:
                self.assertEqual(assets[destination.name]["url"], url)
                destination.write_bytes(payloads[destination.name])

            with patch.object(self.provisioner, "ASSETS", assets):
                marker = self.provisioner.provision(model_root=target, fetcher=fetch)
            self.assertEqual(set(self.provisioner.ASSETS), {row["path"] for row in marker["files"]})
            self.assertFalse((target / "sentinel").exists())
            self.assertEqual(0o644, stat.S_IMODE((target / "ready.json").stat().st_mode))
            self.assertEqual(0o644, stat.S_IMODE((target / next(iter(self.provisioner.ASSETS))).stat().st_mode))
            self.assertEqual(marker, json.loads((target / "ready.json").read_text(encoding="utf-8")))
            existing = (target / "ready.json").read_bytes()

            def bad_fetch(_url: str, destination: Path) -> None:
                if destination.name.endswith("anime_6B.pth"):
                    raise RuntimeError("private failure")
                destination.write_bytes(b"replacement")

            with patch.object(self.provisioner, "ASSETS", assets), self.assertRaises(self.provisioner.ProvisionError):
                self.provisioner.provision(model_root=target, fetcher=bad_fetch)
            self.assertEqual(existing, (target / "ready.json").read_bytes())
            self.assertFalse(list(target.parent.glob(target.name + ".stage-*")))

    def test_urls_and_names_are_fixed(self) -> None:
        self.assertEqual(self.provisioner.ASSETS, {
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
        })

    def test_download_requires_expected_content_length_stream_size_and_hash(self) -> None:
        name = "unit-test.pth"
        url = "https://github.com/xinntao/Real-ESRGAN/releases/download/v0.0.0/" + name
        asset = {name: {"url": url, "size": 4, "sha256": hashlib.sha256(b"good").hexdigest()}}

        class Response:
            def __init__(self, payload: bytes, length: str) -> None:
                self.payload = payload
                self.headers = {"Content-Disposition": f'attachment; filename="{name}"', "Content-Length": length}

            def __enter__(self):
                return self

            def __exit__(self, *_args) -> None:
                return None

            def geturl(self) -> str:
                return "https://release-assets.githubusercontent.com/approved/" + name

            def read(self, _size: int) -> bytes:
                payload, self.payload = self.payload, b""
                return payload

        with tempfile.TemporaryDirectory() as temporary, patch.object(self.provisioner, "ASSETS", asset):
            for payload, length, error in ((b"good", "3", "asset_size_mismatch"), (b"goo", "4", "asset_size_mismatch"), (b"evil", "4", "asset_hash_mismatch")):
                with self.subTest(payload=payload, length=length):
                    case = Path(temporary) / (str(len(payload)) + length)
                    case.mkdir()
                    destination = case / name
                    opener = SimpleNamespace(open=lambda *_args, **_kwargs: Response(payload, length))
                    with patch.object(self.provisioner.urllib.request, "build_opener", return_value=opener), self.assertRaisesRegex(self.provisioner.ProvisionError, error):
                        self.provisioner._download(url, destination)
                    self.assertFalse(destination.exists())

    def test_provision_rejects_downloaded_size_or_hash_before_marker_activation(self) -> None:
        name = "unit-test.pth"
        url = "https://github.com/xinntao/Real-ESRGAN/releases/download/v0.0.0/" + name
        assets = {name: {"url": url, "size": 4, "sha256": hashlib.sha256(b"good").hexdigest()}}
        with tempfile.TemporaryDirectory() as temporary, patch.object(self.provisioner, "ASSETS", assets):
            target = Path(temporary) / "realesrgan"
            target.mkdir()
            (target / "sentinel").write_text("valid", encoding="utf-8")
            for payload, error in ((b"wrong", "asset_size_mismatch"), (b"evil", "asset_hash_mismatch")):
                with self.subTest(payload=payload):
                    with self.assertRaisesRegex(self.provisioner.ProvisionError, error):
                        self.provisioner.provision(model_root=target, fetcher=lambda _url, destination: destination.write_bytes(payload))
                    self.assertEqual("valid", (target / "sentinel").read_text(encoding="utf-8"))
                    self.assertFalse(list(target.parent.glob(target.name + ".stage-*")))

    def test_download_and_staging_reject_oversized_assets(self) -> None:
        assets = {
            "unit-test.pth": {
                "url": "https://github.com/xinntao/Real-ESRGAN/releases/download/test/unit-test.pth",
                "size": 4,
                "sha256": hashlib.sha256(b"good").hexdigest(),
            }
        }
        name, expected = next(iter(assets.items()))
        url = str(expected["url"])

        class Response:
            def __init__(self, payload: bytes, length: str) -> None:
                self.payload = payload
                self.headers = {"Content-Disposition": f'attachment; filename="{name}"', "Content-Length": length}

            def __enter__(self):
                return self

            def __exit__(self, *_args) -> None:
                return None

            def geturl(self) -> str:
                return "https://release-assets.githubusercontent.com/approved/" + name

            def read(self, _size: int) -> bytes:
                payload, self.payload = self.payload, b""
                return payload

        with tempfile.TemporaryDirectory() as temporary, patch.object(self.provisioner, "ASSETS", assets), patch.object(self.provisioner, "MAX_ASSET_BYTES", 4):
            destination = Path(temporary) / name
            opener = SimpleNamespace(open=lambda *_args, **_kwargs: Response(b"12345", "5"))
            with patch.object(self.provisioner.urllib.request, "build_opener", return_value=opener), self.assertRaisesRegex(self.provisioner.ProvisionError, "asset_too_large"):
                self.provisioner._download(url, destination)
            self.assertFalse(destination.exists())

            opener = SimpleNamespace(open=lambda *_args, **_kwargs: Response(b"12345", "4"))
            with patch.object(self.provisioner.urllib.request, "build_opener", return_value=opener), self.assertRaisesRegex(self.provisioner.ProvisionError, "asset_too_large"):
                self.provisioner._download(url, destination)
            self.assertFalse(destination.exists())

            target = Path(temporary) / "realesrgan"
            with self.assertRaisesRegex(self.provisioner.ProvisionError, "asset_too_large"):
                self.provisioner.provision(model_root=target, fetcher=lambda _url, path: path.write_bytes(b"12345"))
            self.assertFalse(target.exists())
            self.assertFalse(list(target.parent.glob(target.name + ".stage-*")))


class RunnerTest(unittest.TestCase):
    def test_anime_video_aliases_keep_native_x4_runner_and_select_output_scale(self) -> None:
        import upscale_runner
        engine_calls: list[dict[str, object]] = []
        rrdb = types.ModuleType("basicsr.archs.rrdbnet_arch")
        rrdb.RRDBNet = lambda **kwargs: kwargs
        srvgg = types.ModuleType("realesrgan.archs.srvgg_arch")
        srvgg.SRVGGNetCompact = lambda **kwargs: kwargs
        realesrgan = types.ModuleType("realesrgan")
        realesrgan.RealESRGANer = lambda **kwargs: engine_calls.append(kwargs) or SimpleNamespace()
        with patch.dict(sys.modules, {
            "basicsr": types.ModuleType("basicsr"),
            "basicsr.archs": types.ModuleType("basicsr.archs"),
            "basicsr.archs.rrdbnet_arch": rrdb,
            "realesrgan": realesrgan,
            "realesrgan.archs": types.ModuleType("realesrgan.archs"),
            "realesrgan.archs.srvgg_arch": srvgg,
        }):
            for alias in ("realesr-animevideov3-x2", "realesr-animevideov3-x3", "realesr-animevideov3-x4", "realesrgan-x4plus"):
                upscale_runner.model_runtime.build_upsampler(alias, "cpu", Path("/models/pinned.pth"))
        self.assertEqual([4, 4, 4, 4], [call["scale"] for call in engine_calls])

        with tempfile.TemporaryDirectory() as temporary:
            workspace = Path(temporary)
            source = workspace / "source.bin"
            source.write_bytes(png_bytes((2, 3)))
            output_scales: list[int] = []
            def enhance(_pixels, outscale):
                output_scales.append(outscale)
                return np.zeros((3 * outscale, 2 * outscale, 3), dtype=np.uint8), None
            fake = SimpleNamespace(enhance=enhance)
            with patch.object(upscale_runner, "model_path_for_alias", return_value=Path("/models/pinned.pth")), patch.object(upscale_runner, "_cuda_available", return_value=False), patch.object(upscale_runner.model_runtime, "build_upsampler", return_value=fake):
                for alias in ("realesr-animevideov3-x2", "realesr-animevideov3-x3", "realesr-animevideov3-x4"):
                    upscale_runner.run_upscale(source=source, output=workspace / "output.png", alias=alias, backend="cpu", model_dir=Path("/models"))
            self.assertEqual([2, 3, 4], output_scales)

    def test_runner_uses_local_alias_and_only_writes_workspace_output(self) -> None:
        import upscale_runner
        with tempfile.TemporaryDirectory() as temporary:
            workspace = Path(temporary)
            source = workspace / "source.bin"
            output = workspace / "output.png"
            source.write_bytes(png_bytes((2, 3)))
            fake = SimpleNamespace(enhance=lambda _pixels, outscale: (np.zeros((6, 4, 3), dtype=np.uint8), None))
            with patch.object(upscale_runner, "model_path_for_alias", return_value=Path("/models/pinned.pth")), patch.object(upscale_runner, "_cuda_available", return_value=False), patch.object(upscale_runner.model_runtime, "build_upsampler", return_value=fake) as constructor:
                report = upscale_runner.run_upscale(source=source, output=output, alias="realesr-animevideov3-x2", backend="cpu", model_dir=Path("/models"))
            self.assertEqual({"model": "realesr-animevideov3-x2", "backend": "cpu", "width": 4, "height": 6}, {key: report[key] for key in ("model", "backend", "width", "height")})
            self.assertEqual(("realesr-animevideov3-x2", "cpu", Path("/models/pinned.pth")), constructor.call_args.args)
            with self.assertRaisesRegex(upscale_runner.ImageToolsError, "^invalid_request$"):
                upscale_runner.run_upscale(source=source, output=workspace.parent / "output.png", alias="realesr-animevideov3-x2", backend="cpu", model_dir=Path("/models"))


if __name__ == "__main__":
    unittest.main()
