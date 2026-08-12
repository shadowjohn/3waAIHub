from __future__ import annotations

import base64
import hashlib
import io
import json
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

from PIL import Image

sys.path.insert(0, str(Path(__file__).parent))

from acceptance import AcceptanceUnavailable, _artifact_urls, assert_health, assert_no_raw_base64, main, validate_async_artifacts, validate_sync_response


MODEL = "realesrgan-x4plus"


def png_bytes(size: tuple[int, int]) -> bytes:
    output = io.BytesIO()
    Image.new("RGB", size, "green").save(output, format="PNG")
    return output.getvalue()


def headers(backend: str, width: int, height: int) -> dict[str, str]:
    return {
        "Content-Type": "image/png",
        "X-3waAIHub-Model": MODEL,
        "X-3waAIHub-Backend": backend,
        "X-3waAIHub-Elapsed-Ms": "7",
        "X-3waAIHub-Width": str(width),
        "X-3waAIHub-Height": str(height),
    }


class AcceptanceTest(unittest.TestCase):
    def test_demo_fixture_is_a_small_png(self) -> None:
        fixture = Path(__file__).parent.parent / "demo" / "smoke.png"
        with Image.open(fixture) as image:
            image.load()
            self.assertEqual("PNG", image.format)
            self.assertEqual((2, 3), image.size)

    def test_health_requires_l5_after_recorded_dual_backend_benchmarks(self) -> None:
        assert_health({"ok": True, "service": "image-tools", "ready": True, "runtime_level": "L5-benchmark-ready", "runtime_ready": True})
        with self.assertRaises(AcceptanceUnavailable):
            assert_health({"ok": True, "service": "image-tools", "ready": True, "runtime_level": "L4a-model-init-smoke", "runtime_ready": True})
        with self.assertRaises(AcceptanceUnavailable):
            assert_health({"ok": True, "service": "image-tools", "ready": True, "runtime_level": "L3-offline-assets", "runtime_ready": True})
        with self.assertRaises(AcceptanceUnavailable):
            assert_health({"ok": True, "service": "image-tools", "ready": True, "runtime_level": "L1-contract", "runtime_ready": False})
        with self.assertRaises(AcceptanceUnavailable):
            assert_health({"ok": True, "service": "image-tools", "ready": False, "runtime_level": "L1-contract", "runtime_ready": False})

    def test_sync_cpu_response_is_verified_png_with_exact_metadata(self) -> None:
        payload = png_bytes((8, 12))
        expected_sha256 = hashlib.sha256(payload).hexdigest()
        result = validate_sync_response(200, headers("cpu", 8, 12), payload, backend="cpu", model=MODEL, dimensions=(8, 12), expected_sha256=expected_sha256)
        self.assertEqual(expected_sha256, result["output_sha256"])
        self.assertEqual(7, result["elapsed_time_ms"])

    def test_sync_response_rejects_unexpected_output_sha256(self) -> None:
        with self.assertRaisesRegex(AssertionError, "^unexpected output SHA-256$"):
            validate_sync_response(200, headers("cpu", 8, 12), png_bytes((8, 12)), backend="cpu", model=MODEL, dimensions=(8, 12), expected_sha256="0" * 64)

    def test_cli_rejects_invalid_expected_sha256(self) -> None:
        with patch.object(sys, "argv", ["acceptance.py", "--service-url", "http://example.test", "--fixture", "smoke.png", "--direct-sync", "--expected-cuda-sha256", "A" * 64]):
            with self.assertRaises(SystemExit) as raised:
                main()
        self.assertEqual(2, raised.exception.code)

    def test_cli_forwards_each_expected_digest_to_its_explicit_backend(self) -> None:
        cuda_digest = "a" * 64
        cpu_digest = "b" * 64
        with patch.object(sys, "argv", ["acceptance.py", "--service-url", "http://example.test", "--fixture", "smoke.png", "--direct-sync", "--expected-cuda-sha256", cuda_digest, "--expected-cpu-sha256", cpu_digest]), patch("acceptance._json_response", return_value=({"ok": True, "service": "image-tools", "ready": True, "runtime_level": "L5-benchmark-ready", "runtime_ready": True}, b"{}")), patch("acceptance.run_sync", side_effect=[{"backend": "cuda"}, {"backend": "cpu"}]) as run_sync:
            self.assertEqual(0, main())
        self.assertEqual([
            {"endpoint": "http://example.test", "fixture": Path("smoke.png"), "backend": "cuda", "model": MODEL, "gateway": False, "expected_sha256": cuda_digest},
            {"endpoint": "http://example.test", "fixture": Path("smoke.png"), "backend": "cpu", "model": MODEL, "gateway": False, "expected_sha256": cpu_digest},
        ], [call.kwargs for call in run_sync.call_args_list])

    def test_cli_forwards_each_expected_digest_to_its_gateway_backend(self) -> None:
        cuda_digest = "a" * 64
        cpu_digest = "b" * 64
        health = {"ok": True, "service": "image-tools", "ready": True, "runtime_level": "L5-benchmark-ready", "runtime_ready": True}
        with patch.object(sys, "argv", ["acceptance.py", "--service-url", "http://service.test", "--fixture", "smoke.png", "--gateway-url", "http://gateway.test/api.php", "--token", "test-token", "--expected-cuda-sha256", cuda_digest, "--expected-cpu-sha256", cpu_digest]), patch("acceptance._json_response", return_value=(health, b"{}")), patch("acceptance.run_sync", side_effect=[{"backend": "cuda"}, {"backend": "cpu"}]) as run_sync, patch("acceptance.run_async", side_effect=[{"backend": "cuda"}, {"backend": "cpu"}]) as run_async:
            self.assertEqual(0, main())
        self.assertEqual([
            {"endpoint": "http://gateway.test/api.php", "fixture": Path("smoke.png"), "backend": "cuda", "model": MODEL, "gateway": True, "token": "test-token", "expected_sha256": cuda_digest},
            {"endpoint": "http://gateway.test/api.php", "fixture": Path("smoke.png"), "backend": "cpu", "model": MODEL, "gateway": True, "token": "test-token", "expected_sha256": cpu_digest},
        ], [call.kwargs for call in run_sync.call_args_list])
        self.assertEqual([
            {"gateway_url": "http://gateway.test/api.php", "fixture": Path("smoke.png"), "backend": "cuda", "model": MODEL, "token": "test-token"},
            {"gateway_url": "http://gateway.test/api.php", "fixture": Path("smoke.png"), "backend": "cpu", "model": MODEL, "token": "test-token"},
        ], [call.kwargs for call in run_async.call_args_list])

    def test_sync_cuda_response_rejects_cpu_metadata(self) -> None:
        with self.assertRaises(AssertionError):
            validate_sync_response(200, headers("cpu", 8, 12), png_bytes((8, 12)), backend="cuda", model=MODEL, dimensions=(8, 12))

    def test_async_cpu_artifacts_match_report_and_exclude_raw_base64(self) -> None:
        payload = png_bytes((8, 12))
        report = json.dumps({
            "model": MODEL,
            "backend": "cpu",
            "source_width": 2,
            "source_height": 3,
            "width": 8,
            "height": 12,
            "elapsed_ms": 7,
            "output_sha256": hashlib.sha256(payload).hexdigest(),
        }).encode()
        result = validate_async_artifacts(payload, report, backend="cpu", model=MODEL, dimensions=(8, 12))
        self.assertEqual("cpu", result["backend"])
        raw = base64.b64encode(b"private source bytes").decode()
        assert_no_raw_base64(json.dumps({"input": {"source_upload_path": "staged"}}).encode(), raw)
        with self.assertRaises(AssertionError):
            assert_no_raw_base64(raw.encode(), raw)

    def test_async_cuda_artifacts_reject_wrong_backend(self) -> None:
        payload = png_bytes((8, 12))
        report = json.dumps({
            "model": MODEL,
            "backend": "cpu",
            "source_width": 2,
            "source_height": 3,
            "width": 8,
            "height": 12,
            "elapsed_ms": 7,
            "output_sha256": hashlib.sha256(payload).hexdigest(),
        }).encode()
        with self.assertRaises(AssertionError):
            validate_async_artifacts(payload, report, backend="cuda", model=MODEL, dimensions=(8, 12))

    def test_async_artifact_ids_use_the_existing_gateway_template(self) -> None:
        urls = _artifact_urls({
            "result": {
                "artifacts": [
                    {"id": 7, "type": "upscaled_image", "mime_type": "image/png"},
                    {"id": 8, "type": "upscale_report", "mime_type": "application/json"},
                ]
            }
        }, "http://127.0.0.1:18131/api.php")
        self.assertEqual({
            "upscaled_image.png": "http://127.0.0.1:18131/api.php?mode=artifact&artifact_id=7",
            "upscale_report.json": "http://127.0.0.1:18131/api.php?mode=artifact&artifact_id=8",
        }, urls)


if __name__ == "__main__":
    unittest.main()
