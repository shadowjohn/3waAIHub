from __future__ import annotations

import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from fastapi.testclient import TestClient

import app
import job


class ResidentHttpRoutesTests(unittest.TestCase):
    token = "breezy-resident-http-test-token"

    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.service_data = Path(self.temporary.name) / "service"
        self.environment = patch.dict(os.environ, {
            "BREEZYVOICE_EXECUTION_MODE": "isolated",
            "BREEZYVOICE_SERVICE_DATA_DIR": str(self.service_data),
            "BREEZYVOICE_INTERNAL_JOB_TOKEN": self.token,
        })
        self.environment.start()
        app.reset_resident_state()
        self.client = TestClient(app.app)

    def tearDown(self) -> None:
        app.reset_resident_state()
        self.environment.stop()
        self.temporary.cleanup()

    def headers(self) -> dict[str, str]:
        return {"X-AIHub-Internal-Token": self.token}

    def stage(self, run_id: str) -> Path:
        input_dir = self.service_data / "resident_jobs" / run_id / "input"
        input_dir.mkdir(parents=True)
        (input_dir / "request.json").write_text(json.dumps({"text": "private prompt"}), encoding="utf-8")
        (input_dir / "runner_config.json").write_text(json.dumps({"model": "private model"}), encoding="utf-8")
        (input_dir / "source").write_bytes(b"private reference")
        return input_dir.parent

    def test_internal_resident_routes_dispatch_with_the_preloaded_model(self) -> None:
        runtime = object()
        app._RESIDENT_MODEL = runtime

        denied = self.client.get("/internal/capacity")
        self.assertEqual(403, denied.status_code)

        ready = self.client.get("/internal/capacity", headers=self.headers())
        self.assertEqual({"model_state": "ready", "active_runs": 0}, ready.json())

        self.stage("breezy-run-1")
        with patch.object(job, "run_job") as run_job:
            started = self.client.post("/internal/jobs", headers=self.headers(), json={"run_id": "breezy-run-1"})
        self.assertEqual({"run_id": "breezy-run-1", "state": "succeeded"}, started.json())
        self.assertIs(runtime, run_job.call_args.kwargs["resident_runtime"])


if __name__ == "__main__":
    unittest.main()
