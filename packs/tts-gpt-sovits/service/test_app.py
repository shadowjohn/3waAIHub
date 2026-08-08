from __future__ import annotations

import hashlib
import json
import os
import sys
import tempfile
import threading
import types
import unittest
import wave
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
        self.body = json.dumps(content).encode("utf-8")


def Header(default=None, **kwargs):
    return default


fastapi = types.ModuleType("fastapi")
fastapi.FastAPI = FastAPI
fastapi.Header = Header
fastapi_responses = types.ModuleType("fastapi.responses")
fastapi_responses.JSONResponse = JSONResponse
sys.modules.update({"fastapi": fastapi, "fastapi.responses": fastapi_responses})
sys.path.insert(0, str(Path(__file__).resolve().parent))

import app
import job


class GptSoVitsResidentTest(unittest.TestCase):
    token = "resident-test-token"

    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.service_data = Path(self.temporary.name) / "service"
        self.environment = patch.dict(os.environ, {
            "GPT_SOVITS_SERVICE_DATA_DIR": str(self.service_data),
            "GPT_SOVITS_INTERNAL_JOB_TOKEN": self.token,
            "GPT_SOVITS_IDLE_UNLOAD_SECONDS": "0",
        })
        self.environment.start()
        app.reset_resident_state()

    def tearDown(self) -> None:
        app.reset_resident_state()
        self.environment.stop()
        self.temporary.cleanup()

    def payload(self, response):
        return json.loads(response.body)

    def stage(self, run_id: str = "resident-a") -> Path:
        input_dir = self.service_data / "resident_jobs" / run_id / "input"
        input_dir.mkdir(parents=True)
        source = input_dir / "source"
        with wave.open(str(source), "wb") as output:
            output.setnchannels(1)
            output.setsampwidth(2)
            output.setframerate(32000)
            output.writeframes(b"\x00\x00" * 32000 * 3)
        digest = hashlib.sha256(source.read_bytes()).hexdigest()
        request = {
            "text": "測試",
            "mode": "clone",
            "voice_profile_id": 7,
            "model": "gpt_sovits_v2",
            "language": "zh_tw",
            "voice_context": {
                "mode": "clone",
                "voice_profile_id": 7,
                "reference_audio_sha256": digest,
                "container_path": "/data/voice_profiles/reference.wav",
            },
        }
        (input_dir / "request.json").write_text(json.dumps(request), encoding="utf-8")
        (input_dir / "runner_config.json").write_text("{}", encoding="utf-8")
        return input_dir.parent

    def test_internal_job_requires_secret_and_valid_run(self) -> None:
        self.assertEqual(403, app.internal_job_start({"run_id": "resident-a"}, "wrong").status_code)
        self.assertEqual(400, app.internal_job_start({"run_id": "../bad"}, self.token).status_code)
        self.assertEqual(400, app.internal_job_start({"run_id": "resident-a"}, self.token).status_code)

    def test_capacity_is_cold_then_ready_and_job_uses_staged_copy(self) -> None:
        self.assertEqual({"model_state": "cold", "active_runs": 0}, app.internal_capacity(self.token))
        app._MODEL = object()
        self.assertEqual({"model_state": "ready", "active_runs": 0}, app.internal_capacity(self.token))
        stage = self.stage()
        with patch.object(job, "run_job") as run_job:
            response = app.internal_job_start({"run_id": "resident-a"}, self.token)
        self.assertEqual({"run_id": "resident-a", "state": "succeeded"}, response)
        self.assertEqual(1, run_job.call_count)
        self.assertEqual(stage / "input" / "source", run_job.call_args.kwargs["managed_reference_path"])
        self.assertEqual({"state": "succeeded"}, json.loads((stage / "terminal.json").read_text(encoding="utf-8")))

    def test_cancel_only_marks_an_active_run(self) -> None:
        app._ACTIVE_JOBS["resident-a"] = threading.Event()
        self.assertEqual({"run_id": "resident-a", "state": "running"}, app.internal_job_cancel("resident-a", self.token))
        self.assertTrue(app._ACTIVE_JOBS["resident-a"].is_set())
        self.assertEqual(404, app.internal_job_cancel("missing", self.token).status_code)

    def test_health_requires_complete_local_model_assets(self) -> None:
        with patch.object(job, "local_model_paths", side_effect=RuntimeError("model_assets_unavailable")):
            self.assertFalse(app.health()["ready"])
        with patch.object(job, "local_model_paths", return_value={}):
            self.assertTrue(app.health()["ready"])


if __name__ == "__main__":
    unittest.main()
