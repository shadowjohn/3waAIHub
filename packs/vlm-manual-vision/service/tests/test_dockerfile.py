from __future__ import annotations

import unittest
from pathlib import Path


SERVICE_DIR = Path(__file__).resolve().parents[1]
DOCKERFILE = SERVICE_DIR / "Dockerfile"


class DockerfileTests(unittest.TestCase):
    def test_image_includes_private_jobs_but_not_demo_fixtures_or_model_data(self) -> None:
        source = DOCKERFILE.read_text(encoding="utf-8")
        self.assertIn("provision.py", source)
        self.assertIn("acceptance.py", source)
        self.assertIn("gpu_smoke.py", source)
        self.assertNotIn("demo/", source)
        self.assertNotIn("*.safetensors", source)


if __name__ == "__main__":
    unittest.main()
