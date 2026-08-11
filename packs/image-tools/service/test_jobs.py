from __future__ import annotations

import hashlib
import io
import json
import sys
import tempfile
import unittest
from contextlib import contextmanager
from pathlib import Path
from unittest.mock import patch

from PIL import Image

sys.path.insert(0, str(Path(__file__).parent))


def png_bytes(size: tuple[int, int] = (2, 3)) -> bytes:
    image = Image.new("RGB", size, "navy")
    buffer = io.BytesIO()
    image.save(buffer, format="PNG")
    return buffer.getvalue()


class ImageToolsJobTests(unittest.TestCase):
    @contextmanager
    def fixture(self, *, backend: str = "cpu", request: dict[str, object] | None = None):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            input_dir = root / "workspace" / "input"
            output_dir = root / "workspace" / "output"
            input_dir.mkdir(parents=True)
            output_dir.mkdir()
            (input_dir / "source").write_bytes(png_bytes())
            (input_dir / "request.json").write_text(json.dumps(request or {"model": "realesrgan-x4plus", "backend": backend}), encoding="utf-8")
            yield input_dir / "request.json", input_dir / "source", output_dir

    def test_fixed_cpu_and_gpu_jobs_call_shared_cli_once_and_publish_exact_artifacts(self) -> None:
        import jobs
        for backend in ("cpu", "cuda"):
            with self.subTest(backend=backend), self.fixture(backend=backend) as (request, source, output_dir):
                calls: list[list[str]] = []

                def invoke(argv: list[str]) -> str:
                    calls.append(argv)
                    output = Path(argv[argv.index("--output") + 1])
                    output.write_bytes(png_bytes((8, 12)))
                    return json.dumps({"model": "realesrgan-x4plus", "backend": backend, "width": 8, "height": 12, "elapsed_ms": 7})

                jobs.run_job(request_path=request, source_path=source, output_dir=output_dir, backend=backend, model_dir=Path("/models/image-tools/realesrgan"), invoke=invoke)

                self.assertEqual(1, len(calls))
                self.assertEqual(backend, calls[0][calls[0].index("--backend") + 1])
                self.assertEqual("realesrgan-x4plus", calls[0][calls[0].index("--model") + 1])
                self.assertEqual(["upscale_report.json", "upscaled_image.png"], sorted(path.name for path in output_dir.iterdir()))
                output = output_dir / "upscaled_image.png"
                report = json.loads((output_dir / "upscale_report.json").read_text(encoding="utf-8"))
                self.assertEqual({"model", "backend", "source_width", "source_height", "width", "height", "elapsed_ms", "output_sha256"}, set(report))
                self.assertEqual(hashlib.sha256(output.read_bytes()).hexdigest(), report["output_sha256"])

    def test_rejects_untrusted_request_fields_and_backend_job_mismatch_before_cli(self) -> None:
        import jobs
        cases = [
            {"model": "realesrgan-x4plus", "backend": "cpu", "output": "/tmp/no"},
            {"model": "realesrgan-x4plus", "backend": "cpu", "command": "id"},
            {"model": "realesrgan-x4plus", "backend": "cpu", "source": "/tmp/no"},
            {"model": "realesrgan-x4plus", "backend": "cuda"},
        ]
        for request_body in cases:
            with self.subTest(request_body=request_body), self.fixture(request=request_body) as (request, source, output_dir):
                def never(_: list[str]) -> str:
                    raise AssertionError("CLI must not run")

                with self.assertRaisesRegex(jobs.JobError, "invalid_request"):
                    jobs.run_job(request_path=request, source_path=source, output_dir=output_dir, backend="cpu", invoke=never)

    def test_rejects_invalid_source_and_untrusted_cli_output(self) -> None:
        import jobs
        cases = [
            (b"not an image", lambda output: output.write_bytes(png_bytes((8, 12))), '{"model":"realesrgan-x4plus","backend":"cpu","width":8,"height":12,"elapsed_ms":1}'),
            (png_bytes(), lambda output: output.write_bytes(b"not png"), '{"model":"realesrgan-x4plus","backend":"cpu","width":8,"height":12,"elapsed_ms":1}'),
            (png_bytes(), lambda output: output.write_bytes(png_bytes((8, 12))), '{"model":"realesrgan-x4plus","backend":"cuda","width":8,"height":12,"elapsed_ms":1}'),
            (png_bytes(), lambda output: output.write_bytes(png_bytes((8, 12))), '{"model":"realesrgan-x4plus","backend":"cpu","width":8,"height":12,"elapsed_ms":1,"extra":true}'),
        ]
        for source_bytes, render, stdout in cases:
            with self.subTest(stdout=stdout), self.fixture() as (request, source, output_dir):
                source.write_bytes(source_bytes)

                def invoke(argv: list[str]) -> str:
                    render(Path(argv[argv.index("--output") + 1]))
                    return stdout

                with self.assertRaisesRegex(jobs.JobError, "inference_failed|invalid_image"):
                    jobs.run_job(request_path=request, source_path=source, output_dir=output_dir, backend="cpu", invoke=invoke)
                self.assertEqual([], list(output_dir.iterdir()))

    def test_rejects_extra_or_preexisting_artifacts(self) -> None:
        import jobs
        with self.fixture() as (request, source, output_dir):
            (output_dir / "unexpected").write_text("no", encoding="utf-8")
            with self.assertRaisesRegex(jobs.JobError, "invalid_request"):
                jobs.run_job(request_path=request, source_path=source, output_dir=output_dir, backend="cpu")

    def test_rejects_cli_extra_workspace_output(self) -> None:
        import jobs
        with self.fixture() as (request, source, output_dir):
            def invoke(argv: list[str]) -> str:
                output = Path(argv[argv.index("--output") + 1])
                output.write_bytes(png_bytes((8, 12)))
                (output.parent / "unexpected.png").write_bytes(png_bytes())
                return '{"model":"realesrgan-x4plus","backend":"cpu","width":8,"height":12,"elapsed_ms":1}'

            with self.assertRaisesRegex(jobs.JobError, "inference_failed"):
                jobs.run_job(request_path=request, source_path=source, output_dir=output_dir, backend="cpu", invoke=invoke)
            self.assertEqual([], list(output_dir.iterdir()))

    def test_rejects_oversized_or_dimension_mismatched_png(self) -> None:
        import jobs
        with self.fixture() as (request, source, output_dir):
            def wrong_dimensions(argv: list[str]) -> str:
                output = Path(argv[argv.index("--output") + 1])
                output.write_bytes(png_bytes((7, 12)))
                return '{"model":"realesrgan-x4plus","backend":"cpu","width":7,"height":12,"elapsed_ms":1}'

            with self.assertRaisesRegex(jobs.JobError, "inference_failed"):
                jobs.run_job(request_path=request, source_path=source, output_dir=output_dir, backend="cpu", invoke=wrong_dimensions)

        with self.fixture() as (request, source, output_dir):
            def valid_png(argv: list[str]) -> str:
                output = Path(argv[argv.index("--output") + 1])
                output.write_bytes(png_bytes((8, 12)))
                return '{"model":"realesrgan-x4plus","backend":"cpu","width":8,"height":12,"elapsed_ms":1}'

            with patch.object(jobs, "MAX_OUTPUT_BYTES", 1), self.assertRaisesRegex(jobs.JobError, "inference_failed"):
                jobs.run_job(request_path=request, source_path=source, output_dir=output_dir, backend="cpu", invoke=valid_png)

    def test_image_build_includes_and_checks_the_async_runner(self) -> None:
        dockerfile = (Path(__file__).parent / "Dockerfile").read_text(encoding="utf-8")
        self.assertIn("jobs.py", dockerfile)
        self.assertIn("test_jobs.py", dockerfile)


if __name__ == "__main__":
    unittest.main()
