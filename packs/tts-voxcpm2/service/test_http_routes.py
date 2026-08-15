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
    token = "resident-http-test-token"

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.service_data = Path(self.temporary.name) / "service"
        self.environment = patch.dict(os.environ, {
            "VOXCPM2_SERVICE_DATA_DIR": str(self.service_data),
            "VOXCPM2_INTERNAL_JOB_TOKEN": self.token,
            "VOXCPM2_IDLE_UNLOAD_SECONDS": "0",
        })
        self.environment.start()
        app._ACTIVE_JOBS.clear()
        app._MODEL = None
        self.client = TestClient(app.app)

    def tearDown(self):
        if app._IDLE_TIMER is not None:
            app._IDLE_TIMER.cancel()
        app._IDLE_TIMER = None
        app._ACTIVE_JOBS.clear()
        app._MODEL = None
        self.environment.stop()
        self.temporary.cleanup()

    def headers(self):
        return {"X-AIHub-Internal-Token": self.token}

    def stage(self, run_id="run-http-1"):
        input_dir = self.service_data / "resident_jobs" / run_id / "input"
        input_dir.mkdir(parents=True)
        (input_dir / "request.json").write_text(json.dumps({"text": "private prompt"}), encoding="utf-8")
        (input_dir / "runner_config.json").write_text(json.dumps({"model": "private model"}), encoding="utf-8")
        return input_dir.parent

    def test_routes_auth_body_validation_and_safe_json(self):
        paths = self.client.get("/openapi.json").json()["paths"]
        self.assertTrue({"/internal/capacity", "/internal/jobs", "/internal/jobs/{run_id}", "/internal/jobs/{run_id}/cancel"}.issubset(paths))

        denied = self.client.get("/internal/capacity")
        self.assertEqual(403, denied.status_code)
        self.assertEqual("internal_auth_failed", denied.json()["error"])

        malformed = self.client.post("/internal/jobs", headers=self.headers(), json={"run_id": "run-http-1", "extra": True})
        self.assertEqual(400, malformed.status_code)
        self.assertEqual("resident_job_invalid", malformed.json()["error"])

        invalid_body = self.client.post("/internal/jobs", headers=self.headers(), json=["run-http-1"])
        self.assertEqual(400, invalid_body.status_code)
        self.assertEqual("bad_request", invalid_body.json()["error"])

        self.stage()
        with patch.object(job, "run_job") as run_job:
            created = self.client.post("/internal/jobs", headers=self.headers(), json={"run_id": "run-http-1"})
        self.assertEqual(200, created.status_code)
        self.assertEqual({"run_id": "run-http-1", "state": "succeeded"}, created.json())
        self.assertEqual(1, run_job.call_count)

        status = self.client.get("/internal/jobs/run-http-1", headers=self.headers())
        self.assertEqual({"run_id": "run-http-1", "state": "succeeded"}, status.json())
        terminal = (self.service_data / "resident_jobs" / "run-http-1" / "terminal.json").read_text(encoding="utf-8")
        self.assertEqual({"state": "succeeded"}, json.loads(terminal))
        rendered = created.text + status.text + terminal
        for value in ("private prompt", "private model", self.token, "artifact", str(self.service_data)):
            self.assertNotIn(value, rendered)

    def test_failed_resident_job_reports_stable_error_code(self):
        stage = self.stage("run-http-failed")
        with patch.object(job, "run_job", side_effect=RuntimeError("voice_profile_unavailable")):
            created = self.client.post("/internal/jobs", headers=self.headers(), json={"run_id": "run-http-failed"})

        self.assertEqual(200, created.status_code)
        self.assertEqual({"run_id": "run-http-failed", "state": "failed", "error_code": "voice_profile_unavailable"}, created.json())
        status = self.client.get("/internal/jobs/run-http-failed", headers=self.headers())
        self.assertEqual(created.json(), status.json())
        terminal = json.loads((stage / "terminal.json").read_text(encoding="utf-8"))
        self.assertEqual({"state": "failed", "error_code": "voice_profile_unavailable"}, terminal)


if __name__ == "__main__":
    unittest.main()
