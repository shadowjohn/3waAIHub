from __future__ import annotations

import io
import json
import sys
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import Mock, call, patch

sys.path.insert(0, str(Path(__file__).parent))

import model_smoke
from model_runtime import ModelRuntimeError, REAL_ESRGAN_COMMIT


class ModelSmokeTest(unittest.TestCase):
    def invoke(self, argv: list[str]) -> tuple[int, str]:
        output = io.StringIO()
        try:
            with redirect_stdout(output):
                status = model_smoke.main(argv)
        except TypeError as exc:
            self.fail(f"model_smoke.main must accept CLI argv: {exc}")
        return status, output.getvalue()

    def test_cpu_smoke_verifies_before_loading_each_unique_family(self) -> None:
        events: list[str] = []
        loader = Mock(side_effect=lambda alias, _backend, _path: events.append(alias))
        with patch.object(model_smoke, "verify_ready", side_effect=lambda _root: events.append("verify") or {"commit": REAL_ESRGAN_COMMIT}), patch.object(model_smoke, "build_upsampler", loader, create=True):
            status, output = self.invoke(["--model-dir", "/models"])

        self.assertEqual(0, status)
        self.assertEqual(["verify", "realesrgan-x4plus", "realesrgan-x4plus-anime", "realesr-animevideov3-x4"], events)
        self.assertEqual([
            call("realesrgan-x4plus", "cpu", Path("/models/RealESRGAN_x4plus.pth")),
            call("realesrgan-x4plus-anime", "cpu", Path("/models/RealESRGAN_x4plus_anime_6B.pth")),
            call("realesr-animevideov3-x4", "cpu", Path("/models/realesr-animevideov3.pth")),
        ], loader.call_args_list)
        self.assertEqual({
            "ok": True,
            "backend": "cpu",
            "commit": REAL_ESRGAN_COMMIT,
            "families": [
                {"id": "realesrgan-x4plus", "aliases": ["realesrgan-x4plus"]},
                {"id": "realesrgan-x4plus-anime", "aliases": ["realesrgan-x4plus-anime"]},
                {"id": "realesr-animevideov3", "aliases": ["realesr-animevideov3-x2", "realesr-animevideov3-x3", "realesr-animevideov3-x4"]},
            ],
        }, json.loads(output))

    def test_smoke_hides_loader_failure(self) -> None:
        with patch.object(model_smoke, "verify_ready", return_value={"commit": REAL_ESRGAN_COMMIT}), patch.object(model_smoke, "build_upsampler", side_effect=ModelRuntimeError("model_load_failed"), create=True):
            status, output = self.invoke(["--backend", "cuda", "--model-dir", "/models"])

        self.assertEqual(1, status)
        self.assertEqual({"ok": False, "error": "model_load_failed"}, json.loads(output))

    def test_smoke_source_never_decodes_or_enhances_an_image(self) -> None:
        source = Path(model_smoke.__file__).read_text(encoding="utf-8")
        self.assertNotIn("decode_image", source)
        self.assertNotIn(".enhance(", source)


if __name__ == "__main__":
    unittest.main()
