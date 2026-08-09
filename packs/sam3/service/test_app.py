from __future__ import annotations

import unittest
from pathlib import Path
from unittest.mock import patch

import numpy as np
from PIL import Image

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

    def test_text_request_uses_image_predictor_instead_of_video_session(self) -> None:
        results = [{"id": 1, "score": 0.9, "mask": np.array([[True]])}]
        image_predictor = object()
        with (
            patch.object(sam3_app, "current_checkpoint", return_value=Path("/models/sam3/sam3.1_multiplex.pt")),
            patch.object(sam3_app, "decoded_image", return_value=Image.new("RGB", (1, 1))),
            patch.object(sam3_app, "resident_sam3_image_loader", return_value=image_predictor) as image_loader,
            patch.object(sam3_app, "segment_single_image_text", return_value=results) as fast_segment,
            patch.object(sam3_app, "segment_single_image", return_value=results),
            patch.object(sam3_app, "resident_sam3_loader") as video_loader,
        ):
            payload = sam3_app.run_sam3(b"fixture", 1, 1, "text", "", "", "bird", "metadata")

        image_loader.assert_called_once()
        fast_segment.assert_called_once_with(image_predictor, unittest.mock.ANY, prompt_type="text", text_prompts=["bird"])
        video_loader.assert_not_called()
        self.assertEqual(1, len(payload["masks"]))

    def test_points_request_keeps_multiplex_video_predictor(self) -> None:
        results = [{"id": 1, "score": 0.9, "mask": np.array([[True]])}]
        video_predictor = object()
        with (
            patch.object(sam3_app, "current_checkpoint", return_value=Path("/models/sam3/sam3.1_multiplex.pt")),
            patch.object(sam3_app, "decoded_image", return_value=Image.new("RGB", (1, 1))),
            patch.object(sam3_app, "resident_sam3_image_loader") as image_loader,
            patch.object(sam3_app, "segment_single_image", return_value=results) as video_segment,
            patch.object(sam3_app, "resident_sam3_loader", return_value=video_predictor) as video_loader,
        ):
            payload = sam3_app.run_sam3(b"fixture", 1, 1, "points", '{"points":[[0,0]]}', "", "", "metadata")

        image_loader.assert_not_called()
        video_loader.assert_called_once()
        video_segment.assert_called_once()
        self.assertEqual(1, len(payload["masks"]))

    def test_switching_to_image_predictor_releases_video_predictor(self) -> None:
        cache = sam3_app._MODEL_CACHE
        cache.clear()
        video_predictor = object()
        image_predictor = object()
        image_loader = getattr(sam3_app, "resident_sam3_image_loader", None)
        self.assertTrue(callable(image_loader), "image resident loader is required")
        assert callable(image_loader)
        try:
            with (
                patch.object(sam3_app, "current_checkpoint", return_value=Path("/models/sam3/sam3.1_multiplex.pt")),
                patch.object(sam3_app, "effective_device", return_value="cuda"),
                patch.object(sam3_app, "load_predictor", return_value=video_predictor),
                patch.object(sam3_app, "load_image_predictor", return_value=image_predictor) as image_model_loader,
                patch.object(sam3_app, "release_cuda_cache", create=True) as release,
            ):
                self.assertIs(video_predictor, sam3_app.resident_sam3_loader())
                self.assertIs(image_predictor, image_loader())

            image_model_loader.assert_called_once()
            release.assert_called_once_with()
            self.assertEqual(1, len(cache))
            self.assertIn(image_predictor, cache.values())
        finally:
            cache.clear()


if __name__ == "__main__":
    unittest.main()
