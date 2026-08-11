from __future__ import annotations

import tempfile
import unittest
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

from image_contract import ImageToolsError, build_upscale_argv, private_job_directory


class UpscaleRunnerBoundaryTest(unittest.TestCase):
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
                "--model", "realesrgan-x4plus", "--backend", "cpu", "--model-dir", "/models/image-tools/realesrgan",
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
