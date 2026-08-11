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

    def test_health_reports_missing_and_verified_assets(self) -> None:
        with patch.object(self.app, "verify_ready", side_effect=self.app.ModelRuntimeError("model_not_present")):
            self.assertFalse(self.app.health()["ready"])
        with patch.object(self.app, "verify_ready", return_value={"commit": "pinned"}):
            health = self.app.health()
        self.assertTrue(health["ready"])
        self.assertEqual("L1-contract", health["runtime_level"])

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


class ProvisionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        import provision_offline_assets as provisioner
        cls.provisioner = provisioner

    def test_stage_is_atomic_private_and_keeps_valid_existing_snapshot_on_failure(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            target = Path(temporary) / "realesrgan"
            target.mkdir()
            (target / "sentinel").write_text("valid", encoding="utf-8")
            payloads = {name: ("weights-" + name).encode() for name in self.provisioner.ASSETS}

            def fetch(url: str, destination: Path) -> None:
                self.assertTrue(url.startswith("https://github.com/xinntao/Real-ESRGAN/releases/download/"))
                destination.write_bytes(payloads[destination.name])

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

            with self.assertRaises(self.provisioner.ProvisionError):
                self.provisioner.provision(model_root=target, fetcher=bad_fetch)
            self.assertEqual(existing, (target / "ready.json").read_bytes())
            self.assertFalse(list(target.parent.glob(target.name + ".stage-*")))

    def test_urls_and_names_are_fixed(self) -> None:
        for name, url in self.provisioner.ASSETS.items():
            self.assertEqual(name, Path(url).name)
            self.assertTrue(url.startswith("https://github.com/xinntao/Real-ESRGAN/releases/download/"))


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
                upscale_runner._upsampler(alias, "cpu", Path("/models/pinned.pth"))
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
            with patch.object(upscale_runner, "model_path_for_alias", return_value=Path("/models/pinned.pth")), patch.object(upscale_runner, "_cuda_available", return_value=False), patch.object(upscale_runner, "_upsampler", return_value=fake):
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
            with patch.object(upscale_runner, "model_path_for_alias", return_value=Path("/models/pinned.pth")), patch.object(upscale_runner, "_cuda_available", return_value=False), patch.object(upscale_runner, "_upsampler", return_value=fake) as constructor:
                report = upscale_runner.run_upscale(source=source, output=output, alias="realesr-animevideov3-x2", backend="cpu", model_dir=Path("/models"))
            self.assertEqual({"model": "realesr-animevideov3-x2", "backend": "cpu", "width": 4, "height": 6}, {key: report[key] for key in ("model", "backend", "width", "height")})
            self.assertEqual(("realesr-animevideov3-x2", "cpu", Path("/models/pinned.pth")), constructor.call_args.args)
            with self.assertRaisesRegex(upscale_runner.ImageToolsError, "^invalid_request$"):
                upscale_runner.run_upscale(source=source, output=workspace.parent / "output.png", alias="realesr-animevideov3-x2", backend="cpu", model_dir=Path("/models"))


if __name__ == "__main__":
    unittest.main()
