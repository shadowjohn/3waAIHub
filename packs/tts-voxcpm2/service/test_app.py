import json
import sys
import tempfile
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


class BaseModel:
    def __init__(self, **values):
        for name in self.__class__.__annotations__:
            setattr(self, name, values.get(name, getattr(self.__class__, name, None)))


def Field(default=None, **kwargs):
    return default


fastapi = types.ModuleType("fastapi")
fastapi.FastAPI = FastAPI
fastapi_exceptions = types.ModuleType("fastapi.exceptions")
fastapi_exceptions.RequestValidationError = RequestValidationError
fastapi_responses = types.ModuleType("fastapi.responses")
fastapi_responses.JSONResponse = JSONResponse
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


class UltimateCloneTests(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
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


if __name__ == "__main__":
    unittest.main()
