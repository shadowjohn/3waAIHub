import threading
import unittest
from concurrent.futures import ThreadPoolExecutor
from io import BytesIO

from fastapi.testclient import TestClient
from PIL import Image

from app import MAX_BODY_BYTES, create_app
from engine import FluxError


class FixtureEngine:
    def __init__(self):
        self.calls = []
        self.reason = None
        self.entered = threading.Event()
        self.release = threading.Event()
        self.release.set()

    def readiness(self):
        return self.reason

    def generate(self, prompt, width, height, seed):
        self.calls.append((prompt, width, height, seed))
        self.entered.set()
        if not self.release.wait(timeout=5):
            raise RuntimeError("Timed out waiting for test")
        if self.reason:
            raise FluxError(self.reason)
        with Image.new("RGB", (width, height), "blue") as image, BytesIO() as output:
            image.save(output, format="PNG")
            return output.getvalue(), 10


class FluxApiTests(unittest.TestCase):
    def setUp(self):
        self.engine = FixtureEngine()
        self.context = TestClient(create_app(self.engine))
        self.client = self.context.__enter__()
        self.addCleanup(self.context.__exit__, None, None, None)

    def test_ready_and_binary_png(self):
        self.assertTrue(self.client.get("/health").json()["ok"])
        response = self.client.post(
            "/generate", json={"prompt": "  黃色小菊  ", "width": 320, "height": 256, "seed": 2}
        )
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.headers["content-type"], "image/png")
        self.assertEqual(response.headers["X-3waAIHub-Device"], "cuda")
        with Image.open(BytesIO(response.content)) as image:
            self.assertEqual(image.size, (320, 256))
        self.assertEqual(self.engine.calls, [("黃色小菊", 320, 256, 2)])

    def test_validates_parameters_and_body(self):
        for fields in ({}, {"seed": -1}, {"seed": True}, {"width": 501}, {"height": 1040}, {"model": "arbitrary"}):
            data = {"prompt": "flower", **fields} if fields else {"prompt": "   "}
            response = self.client.post("/generate", json=data)
            self.assertEqual((response.status_code, response.json()["error"]), (400, "invalid_parameter"))
        self.assertEqual(self.client.post("/generate", data={"prompt": "flower"}).status_code, 415)
        self.assertEqual(
            self.client.post(
                "/generate", content=b"x" * (MAX_BODY_BYTES + 1), headers={"Content-Type": "application/json"}
            ).status_code, 413
        )
        self.assertEqual(self.engine.calls, [])

    def test_readiness_and_oom_codes(self):
        self.engine.reason = "model_not_provisioned"
        response = self.client.get("/health")
        self.assertEqual((response.status_code, response.json()["error"]), (503, "model_not_provisioned"))
        self.engine.reason = "gpu_out_of_memory"
        response = self.client.post("/generate", json={"prompt": "flower"})
        self.assertEqual((response.status_code, response.json()["error"]), (503, "gpu_out_of_memory"))

    def test_concurrent_generate_returns_busy(self):
        self.engine.release.clear()
        with ThreadPoolExecutor(max_workers=1) as executor:
            first = executor.submit(self.client.post, "/generate", json={"prompt": "flower"})
            try:
                self.assertTrue(self.engine.entered.wait(timeout=3))
                second = self.client.post("/generate", json={"prompt": "other"})
                self.assertEqual((second.status_code, second.json()["error"]), (429, "busy"))
            finally:
                self.engine.release.set()
            self.assertEqual(first.result().status_code, 200)
        self.assertEqual(len(self.engine.calls), 1)


if __name__ == "__main__":
    unittest.main()
