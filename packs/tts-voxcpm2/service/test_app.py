import json
import os
import sys
import tempfile
import threading
import types
import unittest
from pathlib import Path
from unittest.mock import patch


class FastAPI:
    def __init__(self, *args, **kwargs):
        pass

    def get(self, *args, **kwargs):
        return lambda function: function

    def post(self, *args, **kwargs):
        return lambda function: function

    def exception_handler(self, *args, **kwargs):
        return lambda function: function


class RequestValidationError(Exception):
    pass


class JSONResponse:
    def __init__(self, content, status_code=200):
        self.status_code = status_code
        self.body = json.dumps(content, ensure_ascii=False).encode("utf-8")


class FileResponse:
    def __init__(self, path, media_type=None):
        self.path = Path(path)
        self.media_type = media_type


class BaseModel:
    def __init__(self, **values):
        for name in self.__class__.__annotations__:
            setattr(self, name, values.get(name, getattr(self.__class__, name, None)))


def Field(default=None, **kwargs):
    return default


def Header(default=None, **kwargs):
    return default


fastapi = types.ModuleType("fastapi")
fastapi.FastAPI = FastAPI
fastapi.Header = Header
fastapi_exceptions = types.ModuleType("fastapi.exceptions")
fastapi_exceptions.RequestValidationError = RequestValidationError
fastapi_responses = types.ModuleType("fastapi.responses")
fastapi_responses.JSONResponse = JSONResponse
fastapi_responses.FileResponse = FileResponse
pydantic = types.ModuleType("pydantic")
pydantic.BaseModel = BaseModel
pydantic.Field = Field
sys.modules.update({
    "fastapi": fastapi,
    "fastapi.exceptions": fastapi_exceptions,
    "fastapi.responses": fastapi_responses,
    "pydantic": pydantic,
})

import app
import job


class FakeModel:
    def __init__(self, error: str | None = None):
        self.error = error
        self.kwargs = None
        self.tts_model = types.SimpleNamespace(sample_rate=48000)

    def generate(self, **kwargs):
        self.kwargs = kwargs
        if self.error:
            raise RuntimeError(self.error)
        return [0.0] * 480


def reset_resident_state():
    if app._IDLE_TIMER is not None:
        app._IDLE_TIMER.cancel()
    app._IDLE_TIMER = None
    app._MODEL_WORK_DEPTH = 0
    app._ACTIVE_JOBS.clear()


class UltimateCloneTests(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        reset_resident_state()
        self.reference = Path("/data/voice_profiles/reference.wav")
        self.other = Path("/data/voice_profiles/other.wav")
        self.model = FakeModel()
        app._MODEL = self.model
        self.patches = [
            patch.object(app, "configure_env"),
            patch.object(app, "artifact_dir", return_value=Path(self.temp_dir.name)),
            patch.object(app, "set_runtime_seed"),
            patch.object(app, "validate_reference_path", side_effect=self.resolve_reference),
            patch.object(app.importlib.util, "find_spec", return_value=object()),
            patch.dict(sys.modules, {"soundfile": types.SimpleNamespace(write=lambda path, wav, rate: Path(path).write_bytes(b"wav"))}),
        ]
        for item in self.patches:
            item.start()

    def tearDown(self):
        for item in reversed(self.patches):
            item.stop()
        app._MODEL = None
        reset_resident_state()
        self.temp_dir.cleanup()

    def resolve_reference(self, path):
        return {"reference": self.reference, "prompt": self.reference, "other": self.other}.get(path)

    def ultimate_request(self, **overrides):
        payload = {
            "text": "target text",
            "mode": "ultimate_clone",
            "real_inference": True,
            "reference_wav_path": "reference",
            "prompt_wav_path": "prompt",
            "prompt_text": "private confirmed transcript",
        }
        payload.update(overrides)
        return app.TtsRequest(**payload)

    def response_body(self, response):
        return json.loads(response.body)

    def test_ultimate_clone_passes_managed_prompt_inputs_to_model(self):
        response = app.tts(self.ultimate_request())

        self.assertEqual(200, response.status_code)
        self.assertEqual(str(self.reference), self.model.kwargs["reference_wav_path"])
        self.assertEqual(str(self.reference), self.model.kwargs["prompt_wav_path"])
        self.assertEqual("private confirmed transcript", self.model.kwargs["prompt_text"])

    def test_ultimate_clone_rejects_missing_mismatched_or_empty_prompt_inputs(self):
        for overrides, error in [
            ({"prompt_wav_path": None}, "ultimate_clone_prompt_wav_required"),
            ({"prompt_wav_path": "other"}, "ultimate_clone_prompt_wav_required"),
            ({"prompt_text": " "}, "ultimate_clone_prompt_text_required"),
        ]:
            response = app.tts(self.ultimate_request(**overrides))
            self.assertEqual(400, response.status_code)
            self.assertEqual(error, self.response_body(response)["error"])

    def test_ultimate_clone_inference_error_does_not_echo_prompt_text(self):
        secret = "private confirmed transcript"
        self.model.error = secret

        response = app.tts(self.ultimate_request(prompt_text=secret))

        self.assertEqual(500, response.status_code)
        self.assertNotIn(secret, response.body.decode("utf-8"))

    def test_artifact_download_allows_only_generated_artifacts(self):
        generated = Path(self.temp_dir.name) / "generated.wav"
        generated.write_bytes(b"wav")

        response = app.artifact(generated.name)

        self.assertEqual(generated, response.path)
        self.assertEqual("audio/wav", response.media_type)
        self.assertEqual(404, app.artifact("missing.wav").status_code)
        self.assertEqual(404, app.artifact("../reference.wav").status_code)


class ResidentJobTests(unittest.TestCase):
    token = "resident-test-token"

    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.service_data = Path(self.temp_dir.name) / "service"
        self.environment = patch.dict(os.environ, {
            "VOXCPM2_SERVICE_DATA_DIR": str(self.service_data),
            "VOXCPM2_INTERNAL_JOB_TOKEN": self.token,
            "VOXCPM2_IDLE_UNLOAD_SECONDS": "0",
        })
        self.environment.start()
        reset_resident_state()
        app._MODEL = None

    def tearDown(self):
        reset_resident_state()
        app._MODEL = None
        self.environment.stop()
        self.temp_dir.cleanup()

    def response_body(self, response):
        return json.loads(response.body)

    def stage(self, run_id="run-1", request=None):
        stage = self.service_data / "resident_jobs" / run_id / "input"
        stage.mkdir(parents=True)
        (stage / "request.json").write_text(json.dumps(request or {"text": "private prompt"}), encoding="utf-8")
        (stage / "runner_config.json").write_text(json.dumps({"model": "private model"}), encoding="utf-8")
        return stage.parent

    def test_internal_auth_payload_and_stage_validation(self):
        self.assertEqual(403, app.internal_capacity("wrong").status_code)
        self.assertEqual(403, app.internal_job_start({"run_id": "run-1"}, "wrong").status_code)
        self.assertEqual(400, app.internal_job_start({"run_id": "run-1", "extra": True}, self.token).status_code)
        self.assertEqual(400, app.internal_job_start({"run_id": "../outside"}, self.token).status_code)
        self.assertEqual(400, app.internal_job_start({"run_id": "run-1"}, self.token).status_code)

    def test_duplicate_active_job_and_unknown_restart_status(self):
        self.stage()
        app._ACTIVE_JOBS["run-1"] = threading.Event()
        duplicate = app.internal_job_start({"run_id": "run-1"}, self.token)
        self.assertEqual(409, duplicate.status_code)
        self.assertEqual(404, app.internal_job_cancel("missing", self.token).status_code)
        app._ACTIVE_JOBS.clear()
        self.assertEqual({"run_id": "run-1", "state": "unknown"}, app.internal_job_status("run-1", self.token))

    def test_job_terminal_status_is_atomic_and_private(self):
        stage = self.stage(request={"text": "private prompt and transcript"})
        with patch.object(job, "run_job") as run_job:
            response = app.internal_job_start({"run_id": "run-1"}, self.token)
        self.assertEqual({"run_id": "run-1", "state": "succeeded"}, response)
        self.assertEqual({"state": "succeeded"}, json.loads((stage / "terminal.json").read_text(encoding="utf-8")))
        self.assertEqual({"run_id": "run-1", "state": "succeeded"}, app.internal_job_status("run-1", self.token))
        rendered = json.dumps(response) + (stage / "terminal.json").read_text(encoding="utf-8")
        self.assertNotIn("private", rendered)
        self.assertNotIn("artifact", rendered)
        self.assertEqual(1, run_job.call_count)

    def test_capacity_and_idle_lifecycle(self):
        self.assertEqual({"model_state": "cold", "active_runs": 0}, app.internal_capacity(self.token))
        app._MODEL = FakeModel()
        self.assertEqual({"model_state": "ready", "active_runs": 0}, app.internal_capacity(self.token))
        app._ACTIVE_JOBS["run-1"] = threading.Event()
        self.assertEqual({"model_state": "running", "active_runs": 1}, app.internal_capacity(self.token))
        app._ACTIVE_JOBS.clear()
        with app.model_work():
            self.assertEqual({"model_state": "running", "active_runs": 0}, app.internal_capacity(self.token))
        self.assertIsNone(app._IDLE_TIMER)
        with patch.dict(os.environ, {"VOXCPM2_IDLE_UNLOAD_SECONDS": "1"}):
            with app.model_work():
                pass
            self.assertIsNotNone(app._IDLE_TIMER)

    def test_direct_tts_and_resident_job_reuse_one_model(self):
        self.stage()
        model = FakeModel()
        app._MODEL = model
        with patch.object(app, "configure_env"), patch.object(app, "artifact_dir", return_value=Path(self.temp_dir.name)), patch.object(app, "set_runtime_seed"), patch.object(app.importlib.util, "find_spec", return_value=object()), patch.dict(sys.modules, {"soundfile": types.SimpleNamespace(write=lambda path, wav, rate: Path(path).write_bytes(b"wav"))}):
            direct = app.tts(app.TtsRequest(text="direct", real_inference=True))
        self.assertEqual(200, direct.status_code)

        def resident_run(*args, **kwargs):
            self.assertIs(model, app._MODEL)
            self.assertEqual(1, app._MODEL_WORK_DEPTH)

        with patch.object(job, "run_job", side_effect=resident_run):
            response = app.internal_job_start({"run_id": "run-1"}, self.token)
        self.assertEqual({"run_id": "run-1", "state": "succeeded"}, response)
        self.assertIs(model, app._MODEL)


if __name__ == "__main__":
    unittest.main()
