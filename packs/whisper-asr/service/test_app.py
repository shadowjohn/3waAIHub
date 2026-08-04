import os
import json
import tempfile
import threading
import types
import unittest
import sys
from pathlib import Path
from unittest.mock import patch


class FastAPI:
    def __init__(self, *args, **kwargs):
        pass

    def get(self, *args, **kwargs):
        return lambda function: function

    def post(self, *args, **kwargs):
        return lambda function: function


class JSONResponse:
    def __init__(self, content, status_code=200):
        self.status_code = status_code
        self.body = json.dumps(content, ensure_ascii=False).encode("utf-8")


def parameter(default=None, **kwargs):
    return default


class UploadFile:
    pass


fastapi = types.ModuleType("fastapi")
fastapi.FastAPI = FastAPI
fastapi.File = parameter
fastapi.Form = parameter
fastapi.Header = parameter
fastapi.UploadFile = UploadFile
fastapi_responses = types.ModuleType("fastapi.responses")
fastapi_responses.JSONResponse = JSONResponse
sys.modules.update({"fastapi": fastapi, "fastapi.responses": fastapi_responses})

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

    def test_resident_cpu_policy_forces_cpu_without_cuda_guard(self):
        os.environ["WHISPER_GPU_SHORTAGE_POLICY"] = "cpu"
        calls = []

        def factory(model_name, device, compute_type, download_root):
            calls.append((device, compute_type))
            return FakeModel()

        with patch.object(app, "default_model_factory", factory), patch.object(app.job, "require_cuda") as require_cuda:
            app.resident_cuda_guard()
            model = app.resident_asr_loader("large-v3")

        self.assertIsInstance(model, FakeModel)
        self.assertEqual([("cpu", "int8")], calls)
        require_cuda.assert_not_called()


class WhisperResidentJobTests(unittest.TestCase):
    token = "resident-asr-token"

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.service_data = Path(self.temporary.name) / "service"
        self.environment = patch.dict(os.environ, {
            "WHISPER_SERVICE_DATA_DIR": str(self.service_data),
            "WHISPER_INTERNAL_JOB_TOKEN": self.token,
        })
        self.environment.start()
        app.MODEL_CACHE.clear()
        app._ACTIVE_JOBS.clear()

    def tearDown(self):
        app._ACTIVE_JOBS.clear()
        app.MODEL_CACHE.clear()
        self.environment.stop()
        self.temporary.cleanup()

    def stage(self, run_id="asr-run-1"):
        input_dir = self.service_data / "resident_jobs" / run_id / "input"
        input_dir.mkdir(parents=True)
        (input_dir / "source").write_bytes(b"RIFFaudio")
        (input_dir / "request.json").write_text(json.dumps({"language": "auto"}), encoding="utf-8")
        (input_dir / "runner_config.json").write_text(json.dumps({"model": {"model": "/models/whisper/asr/large-v3", "label": "large-v3"}}), encoding="utf-8")
        return input_dir.parent

    def test_internal_auth_capacity_and_validation(self):
        self.assertEqual(403, app.internal_capacity("wrong").status_code)
        self.assertEqual({"model_state": "cold", "active_runs": 0}, app.internal_capacity(self.token))
        app.MODEL_CACHE[("large-v3", "cuda", "float16")] = FakeModel()
        self.assertEqual({"model_state": "ready", "active_runs": 0}, app.internal_capacity(self.token))
        self.assertEqual(400, app.internal_job_start({"run_id": "../escape"}, self.token).status_code)
        self.assertEqual(400, app.internal_job_start({"run_id": "missing"}, self.token).status_code)

    def test_resident_job_terminal_status_and_duplicate_guard(self):
        stage = self.stage()
        app._ACTIVE_JOBS["asr-run-1"] = threading.Event()
        self.assertEqual(409, app.internal_job_start({"run_id": "asr-run-1"}, self.token).status_code)
        app._ACTIVE_JOBS.clear()

        with patch.object(app.job, "run_job") as run_job:
            response = app.internal_job_start({"run_id": "asr-run-1"}, self.token)

        self.assertEqual({"run_id": "asr-run-1", "state": "succeeded"}, response)
        self.assertEqual({"state": "succeeded"}, json.loads((stage / "terminal.json").read_text(encoding="utf-8")))
        self.assertEqual({"run_id": "asr-run-1", "state": "succeeded"}, app.internal_job_status("asr-run-1", self.token))
        self.assertEqual(1, run_job.call_count)


if __name__ == "__main__":
    unittest.main()
