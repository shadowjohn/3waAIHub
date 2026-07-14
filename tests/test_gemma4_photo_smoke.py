import importlib.util
import json
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import patch


class JSONResponse:
    def __init__(self, content, status_code=200):
        self.status_code = status_code
        self.body = json.dumps(content).encode("utf-8")


class FastAPI:
    def __init__(self, *args, **kwargs):
        pass

    def get(self, *args, **kwargs):
        return lambda func: func

    def post(self, *args, **kwargs):
        return lambda func: func


class BaseModel:
    def __init__(self, **kwargs):
        for name, value in self.__class__.__dict__.items():
            if not name.startswith("_") and name not in kwargs and not callable(value):
                setattr(self, name, value)
        for name, value in kwargs.items():
            setattr(self, name, value)


def Field(default=None, **kwargs):
    return default


fastapi = types.ModuleType("fastapi")
fastapi.FastAPI = FastAPI
responses = types.ModuleType("fastapi.responses")
responses.JSONResponse = JSONResponse
pydantic = types.ModuleType("pydantic")
pydantic.BaseModel = BaseModel
pydantic.Field = Field
sys.modules.setdefault("fastapi", fastapi)
sys.modules.setdefault("fastapi.responses", responses)
sys.modules.setdefault("pydantic", pydantic)


APP_PATH = Path(__file__).resolve().parents[1] / "packs/llm-gemma4-12b/service/app.py"
spec = importlib.util.spec_from_file_location("gemma4_app", APP_PATH)
app = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(app)


def body(response):
    return json.loads(response.body.decode("utf-8"))


class Gemma4PhotoSmokeTest(unittest.TestCase):
    def test_unsafe_paths_are_rejected(self):
        with tempfile.TemporaryDirectory() as root:
            app.PHOTO_ROOT = Path(root).resolve()
            outside = Path(root).parent / "escape.jpg"
            outside.write_bytes(b"jpeg")

            self.assertIsNone(app.safe_photo_path("/etc/passwd"))
            self.assertIsNone(app.safe_photo_path(str(Path(root) / "../escape.jpg")))

    def test_mock_photo_returns_schema_without_model_call(self):
        with tempfile.TemporaryDirectory() as root:
            app.PHOTO_ROOT = Path(root).resolve()
            image = app.PHOTO_ROOT / "img_0123456789abcdefghij" / "original"
            image.parent.mkdir()
            image.write_bytes(b"\xff\xd8jpeg")

            with patch.object(app.requests, "post", side_effect=AssertionError("requests.post called")):
                response = app.photo(app.PhotoRequest(
                    image_id="img_0123456789abcdefghij",
                    image_internal_path=str(image),
                    text="describe",
                    real_inference=False,
                ))

            payload = body(response)
            self.assertEqual(200, response.status_code)
            for key in ["ok", "mock", "runtime_level", "model", "image_id", "answer", "caption", "tags", "usage", "elapsed_ms"]:
                self.assertIn(key, payload)

    def test_real_photo_payload_is_non_streaming_multimodal_json(self):
        captured = {}

        class FakeResponse:
            status_code = 200
            text = ""

            def json(self):
                return {
                    "choices": [{"message": {"content": '{"answer":"可見","caption":"圖片","tags":["測試"]}'}}],
                    "usage": {"prompt_tokens": 1, "completion_tokens": 2, "total_tokens": 3},
                }

        def fake_post(url, json, timeout):
            captured.update(url=url, json=json, timeout=timeout)
            return FakeResponse()

        with tempfile.TemporaryDirectory() as root:
            app.PHOTO_ROOT = Path(root).resolve()
            image = app.PHOTO_ROOT / "img_0123456789abcdefghij" / "original"
            image.parent.mkdir()
            image.write_bytes(b"\xff\xd8jpeg")

            with patch.object(app.requests, "post", side_effect=fake_post):
                response = app.photo(app.PhotoRequest(
                    image_id="img_0123456789abcdefghij",
                    image_internal_path=str(image),
                    text="describe",
                    real_inference=True,
                ))

        self.assertEqual(200, response.status_code)
        payload = captured["json"]
        self.assertFalse(payload["stream"])
        content = payload["messages"][0]["content"]
        self.assertEqual("text", content[0]["type"])
        self.assertEqual("image_url", content[1]["type"])
        self.assertTrue(content[1]["image_url"]["url"].startswith("data:image/jpeg;base64,"))


if __name__ == "__main__":
    unittest.main()
