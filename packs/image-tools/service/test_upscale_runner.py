from __future__ import annotations

import tempfile
import unittest
import sys
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import numpy as np
from PIL import Image

sys.path.insert(0, str(Path(__file__).parent))

from image_contract import ImageToolsError, build_upscale_argv, private_job_directory


class UpscaleRunnerBoundaryTest(unittest.TestCase):
    def test_cli_defaults_to_sync_and_passes_only_its_controlled_operation_to_decoder(self) -> None:
        import upscale_runner
        with patch.object(upscale_runner, "run_upscale", return_value={"model": "realesrgan-x4plus", "backend": "cpu", "width": 8, "height": 12, "elapsed_ms": 1}) as runner:
            self.assertEqual(0, upscale_runner.main(["--input", "/workspace/source", "--output", "/workspace/output.png", "--model", "realesrgan-x4plus", "--backend", "cpu"]))
        self.assertEqual("upscale", runner.call_args.kwargs["operation"])

        with tempfile.TemporaryDirectory() as temporary:
            workspace = Path(temporary)
            source = workspace / "source"
            output = workspace / "output.png"
            source.write_bytes(b"source")
            fake = SimpleNamespace(enhance=lambda _pixels, outscale: (np.zeros((12, 8, 3), dtype=np.uint8), None))
            with patch.object(upscale_runner, "decode_image", return_value=Image.new("RGB", (2, 3))) as decoder, patch.object(upscale_runner, "model_path_for_alias", return_value=Path("/models/pinned.pth")), patch.object(upscale_runner, "_cuda_available", return_value=False), patch.object(upscale_runner, "_upsampler", return_value=fake):
                upscale_runner.run_upscale(source=source, output=output, alias="realesrgan-x4plus", backend="cpu", model_dir=Path("/models"), operation="upscale_task")
            decoder.assert_called_once_with(b"source", operation="upscale_task")
            with Image.open(output) as rendered:
                self.assertEqual((8, 12), rendered.size)

    def test_command_is_literal_argv_and_stays_inside_workspace(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            workspace = Path(temporary)
            source = workspace / "source"
            source.write_bytes(b"image")
            argv = build_upscale_argv(
                workspace=workspace,
                source=source,
                output=workspace / "output.png",
                model="realesrgan-x4plus",
                backend="cpu",
                model_dir=Path("/models/image-tools/realesrgan"),
            )
            self.assertEqual(argv, [
                "python3", "/app/upscale_runner.py", "--input", str(source), "--output", str(workspace / "output.png"),
                "--model", "realesrgan-x4plus", "--backend", "cpu", "--model-dir", "/models/image-tools/realesrgan", "--operation", "upscale",
            ])
            with self.assertRaisesRegex(ImageToolsError, "^invalid_request$"):
                build_upscale_argv(workspace=workspace, source=Path(temporary).parent / "outside", output=workspace / "output.png", model="realesrgan-x4plus", backend="cpu", model_dir=Path("/models"))
            with self.assertRaisesRegex(ImageToolsError, "^invalid_request$"):
                build_upscale_argv(workspace=workspace, source=source, output=Path(temporary).parent / "output.png", model="realesrgan-x4plus", backend="cpu", model_dir=Path("/models"))

    def test_private_workspace_is_removed_on_success_and_failure(self) -> None:
        with private_job_directory() as workspace:
            path = workspace
            self.assertTrue(path.is_dir())
            self.assertEqual(path.stat().st_mode & 0o777, 0o700)
        self.assertFalse(path.exists())
        with self.assertRaisesRegex(RuntimeError, "boom"):
            with private_job_directory() as workspace:
                path = workspace
                raise RuntimeError("boom")
        self.assertFalse(path.exists())


if __name__ == "__main__":
    unittest.main()
