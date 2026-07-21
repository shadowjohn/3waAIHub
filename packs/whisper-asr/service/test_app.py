import os
import unittest

import app


class FakeSegment:
    start = 0.0
    end = 1.25
    text = " hello"


class FakeInfo:
    language = "en"


class FakeModel:
    def transcribe(self, audio_path, **kwargs):
        return iter([FakeSegment()]), FakeInfo()


class WhisperInferenceTests(unittest.TestCase):
    def setUp(self):
        self.original_env = os.environ.copy()
        app.MODEL_CACHE.clear()

    def tearDown(self):
        os.environ.clear()
        os.environ.update(self.original_env)
        app.MODEL_CACHE.clear()

    def test_auto_retries_cpu_after_cuda_model_failure(self):
        calls = []

        def factory(model_name, device, compute_type, download_root):
            calls.append((model_name, device, compute_type, download_root))
            if device == "cuda":
                raise RuntimeError("CUDA driver is unavailable")
            return FakeModel()

        result = app.run_real_inference(
            "/tmp/input.wav",
            "auto",
            model_factory=factory,
            model_name="small",
            requested_device="auto",
            requested_compute_type="auto",
        )

        self.assertTrue(result["ok"])
        self.assertFalse(result["mock"])
        self.assertEqual([("small", "cuda", "float16", "/models/whisper"), ("small", "cpu", "int8", "/models/whisper")], calls)
        self.assertEqual("hello", result["text"])
        self.assertEqual("en", result["language"])
        self.assertEqual(
            {"requested": "auto", "effective": "cpu", "compute_type": "int8", "fallback_used": True},
            result["device"],
        )

    def test_all_candidate_failures_return_safe_error(self):
        def factory(model_name, device, compute_type, download_root):
            raise RuntimeError("secret CUDA failure detail")

        result = app.run_real_inference(
            "/tmp/input.wav",
            "auto",
            model_factory=factory,
            requested_device="auto",
            requested_compute_type="auto",
        )

        self.assertFalse(result["ok"])
        self.assertEqual("real_inference_failed", result["error"])
        self.assertEqual(503, result["status_code"])
        self.assertEqual(
            [
                {"device": "cuda", "compute_type": "float16", "error": "RuntimeError"},
                {"device": "cpu", "compute_type": "int8", "error": "RuntimeError"},
            ],
            result["attempts"],
        )
        self.assertNotIn("secret", str(result))


if __name__ == "__main__":
    unittest.main()
