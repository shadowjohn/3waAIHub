from __future__ import annotations

import threading
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

import numpy as np
from PIL import Image

import app as sam3_app


class Sam3ResidentModelTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.runtime_dir = tempfile.TemporaryDirectory()
        root = Path(cls.runtime_dir.name)
        cls.environment = patch.dict(os.environ, {
            "SAM3_CACHE_DIR": str(root / "cache"),
            "SAM3_SERVICE_DATA_DIR": str(root / "service"),
        })
        cls.environment.start()

    @classmethod
    def tearDownClass(cls) -> None:
        cls.environment.stop()
        cls.runtime_dir.cleanup()

    def test_shutdown_clears_point_session_cache(self) -> None:
        shutdown = getattr(sam3_app, "shutdown_point_session_cache", None)
        self.assertTrue(callable(shutdown), "point session shutdown hook is required")
        assert callable(shutdown)

        point_cache = MagicMock()
        with patch.object(sam3_app, "_POINT_SESSION_CACHE", point_cache):
            shutdown()

        point_cache.clear.assert_called_once_with()

    def test_point_session_cache_reuses_same_image_and_expires(self) -> None:
        cache_type = getattr(sam3_app, "PointSessionCache", None)
        self.assertTrue(callable(cache_type), "point session cache is required")
        assert callable(cache_type)

        class FakeTimer:
            instances: list["FakeTimer"] = []

            def __init__(self, seconds: float, callback: object) -> None:
                self.seconds = seconds
                self.callback = callback
                self.daemon = False
                self.cancelled = False
                FakeTimer.instances.append(self)

            def start(self) -> None:
                return None

            def cancel(self) -> None:
                self.cancelled = True

            def fire(self) -> None:
                assert callable(self.callback)
                self.callback()

        predictor = object()
        first_session = object()
        second_session = object()
        cache = cache_type(60, FakeTimer, threading.RLock())
        image = Image.new("RGB", (6, 4), "white")
        results = [{"id": 1, "score": 0.9, "mask": np.array([[True]])}]
        with (
            patch.object(sam3_app, "open_point_session", side_effect=[first_session, second_session]) as opener,
            patch.object(sam3_app, "segment_point_session", return_value=results) as segmenter,
            patch.object(sam3_app, "close_point_session") as closer,
        ):
            self.assertEqual(results, cache.segment(predictor, "same-image", image, Path("/tmp"), [[3, 2]], [1]))
            self.assertEqual(results, cache.segment(predictor, "same-image", image, Path("/tmp"), [[4, 2]], [1]))
            self.assertEqual(results, cache.segment(predictor, "next-image", image, Path("/tmp"), [[5, 2]], [1]))

            self.assertEqual(2, opener.call_count)
            self.assertEqual(3, segmenter.call_count)
            closer.assert_called_once_with(predictor, first_session)
            self.assertEqual(60, FakeTimer.instances[-1].seconds)
            FakeTimer.instances[-1].fire()

        self.assertEqual([(predictor, first_session), (predictor, second_session)], [call.args for call in closer.call_args_list])

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
        point_cache = MagicMock()
        point_cache.segment.return_value = results
        with (
            patch.object(sam3_app, "current_checkpoint", return_value=Path("/models/sam3/sam3.1_multiplex.pt")),
            patch.object(sam3_app, "decoded_image", return_value=Image.new("RGB", (1, 1))),
            patch.object(sam3_app, "resident_sam3_image_loader") as image_loader,
            patch.object(sam3_app, "resident_sam3_loader", return_value=video_predictor) as video_loader,
            patch.object(sam3_app, "_POINT_SESSION_CACHE", point_cache),
        ):
            payload = sam3_app.run_sam3(b"fixture", 1, 1, "points", '{"points":[[0,0]]}', "", "", "metadata")

        image_loader.assert_not_called()
        video_loader.assert_called_once()
        point_cache.segment.assert_called_once()
        self.assertEqual(1, len(payload["masks"]))

    def test_switching_to_image_predictor_releases_video_predictor(self) -> None:
        cache = sam3_app._MODEL_CACHE
        cache.clear()
        video_predictor = object()
        image_predictor = object()
        image_loader = getattr(sam3_app, "resident_sam3_image_loader", None)
        self.assertTrue(callable(image_loader), "image resident loader is required")
        assert callable(image_loader)
        point_cache = MagicMock()
        try:
            with (
                patch.object(sam3_app, "current_checkpoint", return_value=Path("/models/sam3/sam3.1_multiplex.pt")),
                patch.object(sam3_app, "effective_device", return_value="cuda"),
                patch.object(sam3_app, "load_predictor", return_value=video_predictor),
                patch.object(sam3_app, "load_image_predictor", return_value=image_predictor) as image_model_loader,
                patch.object(sam3_app, "release_cuda_cache", create=True) as release,
                patch.object(sam3_app, "_POINT_SESSION_CACHE", point_cache),
            ):
                self.assertIs(video_predictor, sam3_app.resident_sam3_loader())
                self.assertIs(image_predictor, image_loader())

            image_model_loader.assert_called_once()
            point_cache.clear.assert_called_once_with()
            release.assert_called_once_with()
            self.assertEqual(1, len(cache))
            self.assertIn(image_predictor, cache.values())
        finally:
            cache.clear()


if __name__ == "__main__":
    unittest.main()
