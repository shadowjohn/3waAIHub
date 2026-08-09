from __future__ import annotations

import hashlib
import json
import os
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

import model_smoke


class Sam3ModelStatusTests(unittest.TestCase):
    def write_model(self, root: Path, content: bytes) -> None:
        checkpoint = root / model_smoke.CHECKPOINT_NAME
        checkpoint.write_bytes(content)
        digest = hashlib.sha256(content).hexdigest()
        (root / model_smoke.MANIFEST_NAME).write_text(
            json.dumps({
                "upstream_commit": model_smoke.UPSTREAM_COMMIT,
                "repository": model_smoke.REPOSITORY,
                "files": {model_smoke.CHECKPOINT_NAME: digest},
            }),
            encoding="utf-8",
        )

    def test_reuses_verified_digest_until_model_or_manifest_changes(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.write_model(root, b"first-model")
            with patch.dict(os.environ, {"SAM3_MODEL_DIR": str(root)}), patch.object(model_smoke, "sha256", wraps=model_smoke.sha256) as digest:
                self.assertTrue(model_smoke.model_status()["ok"])
                self.assertTrue(model_smoke.model_status()["ok"])
                self.assertEqual(1, digest.call_count)

                time.sleep(0.001)
                self.write_model(root, b"second-model")
                self.assertTrue(model_smoke.model_status()["ok"])

            self.assertEqual(2, digest.call_count)


if __name__ == "__main__":
    unittest.main()
