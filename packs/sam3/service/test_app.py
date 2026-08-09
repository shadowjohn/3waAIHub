from __future__ import annotations

import unittest
from pathlib import Path
from unittest.mock import patch

import app as sam3_app


class Sam3ResidentModelTests(unittest.TestCase):
    def test_resident_loader_reuses_predictor_and_reports_ready_capacity(self) -> None:
        cache = getattr(sam3_app, "_MODEL_CACHE", None)
        self.assertIsInstance(cache, dict)
        assert isinstance(cache, dict)
        cache.clear()
        predictor = object()
        try:
            with patch.object(sam3_app, "current_checkpoint", return_value=Path("/models/sam3/sam3.1_multiplex.pt")), patch.object(sam3_app, "effective_device", return_value="cuda"), patch.object(sam3_app, "load_predictor", return_value=predictor) as loader:
                self.assertIs(predictor, sam3_app.resident_sam3_loader())
                self.assertIs(predictor, sam3_app.resident_sam3_loader())
                loader.assert_called_once()

            with patch.object(sam3_app, "internal_authorized", return_value=True):
                self.assertEqual("ready", sam3_app.internal_capacity("test")["model_state"])
                with sam3_app.model_work():
                    self.assertEqual("running", sam3_app.internal_capacity("test")["model_state"])
        finally:
            cache.clear()


if __name__ == "__main__":
    unittest.main()
