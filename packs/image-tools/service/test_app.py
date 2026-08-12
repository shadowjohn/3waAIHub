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
from unittest.mock import patch

from PIL import Image
import numpy as np

sys.path.insert(0, str(Path(__file__).parent))


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


def png_bytes(size: tuple[int, int] = (2, 3)) -> bytes:
    output = io.BytesIO()
    Image.new("RGB", size, "red").save(output, format="PNG")
    return output.getvalue()


class FakeUpload:
    def __init__(self, data: bytes) -> None:
        self.data = data

    async def read(self, _limit: int | None = None) -> bytes:
        return self.data


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

    def tearDown(self) -> None:
        self.environment.stop()
        self.temp.cleanup()

    def response_body(self, response):
        return json.loads(response.body)

    def test_health_reports_l1_when_assets_are_missing_and_l4a_when_marker_verifies(self) -> None:
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
        with patch.object(self.app, "verify_ready", return_value={"commit": "pinned"}):
            health = self.app.health()
        self.assertEqual({
            "ok": True,
            "service": "image-tools",
            "ready": True,
            "runtime_level": "L4a-model-init-smoke",
            "runtime_ready": True,
        }, health)

    def test_process_returns_png_exact_metadata_and_cleans_workspace(self) -> None:
        workspaces: list[Path] = []

        def run(argv, **kwargs):
            self.assertIsInstance(argv, list)
            self.assertFalse(kwargs["shell"])
            self.assertFalse(kwargs["check"])
            self.assertIn("--fp32", argv)
            self.assertEqual("realesrgan-x4plus", argv[argv.index("--model") + 1])
            output = Path(argv[argv.index("--output") + 1])
            workspaces.append(output.parent)
            output.write_bytes(png_bytes((8, 12)))
            return SimpleNamespace(returncode=0, stdout=json.dumps({"model": "realesrgan-x4plus", "backend": "cpu", "width": 8, "height": 12, "elapsed_ms": 7}), stderr="")

        with patch.object(self.app, "verify_ready", return_value={}), patch.object(self.app, "cuda_available", return_value=False), patch.object(self.app.subprocess, "run", side_effect=run):
            response = asyncio.run(self.app.process_image(FakeUpload(png_bytes()), operation="upscale", model="realesrgan-x4plus", backend="cpu"))

        self.assertEqual(200, response.status_code)
        self.assertEqual("image/png", response.media_type)
        headers = {key.lower(): value for key, value in response.headers.items() if key.lower().startswith("x-3waaihub-")}
        self.assertEqual({
            "x-3waaihub-model": "realesrgan-x4plus",
            "x-3waaihub-backend": "cpu",
            "x-3waaihub-width": "8",
            "x-3waaihub-height": "12",
        }, {key: value for key, value in headers.items() if key != "x-3waaihub-elapsed-ms"})
        self.assertTrue(headers["x-3waaihub-elapsed-ms"].isdigit())
        self.assertNotIn("x-3waaihub-device", {key.lower() for key in response.headers})
        self.assertEqual((8, 12), Image.open(io.BytesIO(response.body)).size)
        self.assertTrue(workspaces)
        self.assertTrue(all(not path.exists() for path in workspaces))

    def test_cuda_has_no_fp32_and_stable_sanitized_errors(self) -> None:
        errors = [
            (self.app.ModelRuntimeError("model_not_present"), "model_not_present"),
            (self.app.ModelRuntimeError("model_load_failed"), "model_load_failed"),
        ]
        for error, code in errors:
            with self.subTest(code=code), patch.object(self.app, "verify_ready", side_effect=error):
                response = asyncio.run(self.app.process_image(FakeUpload(png_bytes()), operation="upscale", model="realesrgan-x4plus", backend="cpu"))
                self.assertEqual(code, self.response_body(response)["error"])
                self.assertNotIn("/", response.body.decode())

        with patch.object(self.app, "cuda_available", return_value=False):
            response = asyncio.run(self.app.process_image(FakeUpload(png_bytes()), operation="upscale", model="realesrgan-x4plus", backend="cuda"))
        self.assertEqual("backend_unavailable", self.response_body(response)["error"])

        def failed_run(argv, **kwargs):
            self.assertNotIn("--fp32", argv)
            return SimpleNamespace(returncode=1, stdout="", stderr="private /models/path")

        with patch.object(self.app, "verify_ready", return_value={}), patch.object(self.app, "cuda_available", return_value=True), patch.object(self.app.subprocess, "run", side_effect=failed_run):
            response = asyncio.run(self.app.process_image(FakeUpload(png_bytes()), operation="upscale", model="realesrgan-x4plus", backend="cuda"))
        self.assertEqual("inference_failed", self.response_body(response)["error"])
        self.assertNotIn("private", response.body.decode())

    def test_cli_stable_errors_are_allowlisted_and_malformed_output_is_hidden(self) -> None:
        cases = [
            ("{\"error\":\"model_load_failed\"}", "model_load_failed", 503),
            ("{\"error\":\"model_not_present\"}", "model_not_present", 404),
            ("{\"error\":\"backend_unavailable\"}", "backend_unavailable", 503),
            ("{\"error\":\"private /models/path\"}", "inference_failed", 500),
            ("not json", "inference_failed", 500),
        ]
        for stdout, code, status in cases:
            with self.subTest(stdout=stdout), patch.object(self.app, "verify_ready", return_value={}), patch.object(self.app, "cuda_available", return_value=False), patch.object(self.app.subprocess, "run", return_value=SimpleNamespace(returncode=1, stdout=stdout, stderr="private stderr")):
                response = asyncio.run(self.app.process_image(FakeUpload(png_bytes()), operation="upscale", model="realesrgan-x4plus", backend="cpu"))
            self.assertEqual(status, response.status_code)
            self.assertEqual(code, self.response_body(response)["error"])
            self.assertNotIn("private", response.body.decode())


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
