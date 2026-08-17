from __future__ import annotations

import tempfile
import unittest
from pathlib import Path
from types import ModuleType
from unittest.mock import patch

import numpy as np
from PIL import Image

import sam31
from sam31 import Sam31Error, _patch_multiplex_init_state, load_predictor, segment_single_image


class FakePredictor:
    def __init__(self, response: object | None = None, fail_add: bool = False) -> None:
        self.calls: list[dict[str, object]] = []
        self.session_path: Path | None = None
        self.response = response if response is not None else {
            "outputs": {
                "out_obj_ids": [9],
                "out_probs": [0.75],
                "out_boxes_xywh": [[2, 1, 3, 2]],
                "out_binary_masks": np.array([[[0, 0, 0, 0, 0, 0], [0, 0, 1, 1, 1, 0], [0, 0, 1, 1, 1, 0], [0, 0, 0, 0, 0, 0]]]),
            }
        }
        self.fail_add = fail_add

    def handle_request(self, request: dict[str, object]) -> object:
        self.calls.append(request)
        if request["type"] == "start_session":
            self.session_path = Path(str(request["resource_path"]))
            self.assert_frame_exists()
            return {"session_id": "session-1"}
        if request["type"] == "add_prompt":
            if self.fail_add:
                raise RuntimeError("predictor failed")
            return self.response
        return {"ok": True}

    def assert_frame_exists(self) -> None:
        assert self.session_path is not None
        assert (self.session_path / "000000.jpg").is_file()


class FakeImagePredictor:
    def __init__(self) -> None:
        self.prompts: list[str] = []

    def segment_text(self, image: Image.Image, text: str) -> list[dict[str, object]]:
        self.prompts.append(text)
        return [{"id": 1, "score": 0.9, "mask": np.array([[1]], dtype=bool)}]


class Sam31ImageAdapterTests(unittest.TestCase):
    def image(self) -> Image.Image:
        return Image.new("RGB", (6, 4), "white")

    def test_points_are_normalized_and_session_is_removed(self) -> None:
        predictor = FakePredictor()
        with tempfile.TemporaryDirectory() as workspace:
            masks = segment_single_image(
                predictor,
                self.image(),
                prompt_type="points",
                points=[[3, 2]],
                labels=[1],
                workspace=Path(workspace),
            )
            self.assertEqual([{"id": 9, "score": 0.75, "bbox": [2, 1, 3, 2]}], [{key: item[key] for key in ("id", "score", "bbox")} for item in masks])
            self.assertIsNotNone(predictor.session_path)
            self.assertFalse(predictor.session_path.exists())

        self.assertEqual(["start_session", "add_prompt", "close_session"], [call["type"] for call in predictor.calls])
        prompt = predictor.calls[1]
        self.assertEqual([[0.5, 0.5]], prompt["points"])
        self.assertEqual([1], prompt["point_labels"])
        self.assertEqual(1, prompt["obj_id"])
        self.assertTrue(prompt["rel_coordinates"])

    def test_points_seed_the_multiplex_frame_cache_before_add_prompt(self) -> None:
        class MultiplexPointPredictor(FakePredictor):
            def __init__(self) -> None:
                super().__init__()
                self._all_inference_states: dict[str, dict[str, object]] = {}

            def handle_request(self, request: dict[str, object]) -> object:
                self.calls.append(request)
                if request["type"] == "start_session":
                    self.session_path = Path(str(request["resource_path"]))
                    self.assert_frame_exists()
                    self._all_inference_states["session-1"] = {"state": {}}
                    return {"session_id": "session-1"}
                if request["type"] == "add_prompt":
                    state = self._all_inference_states["session-1"]["state"]
                    if not isinstance(state, dict) or 0 not in state.get("cached_frame_outputs", {}):
                        return {"outputs": {}}
                    return self.response
                return {"ok": True}

        masks = segment_single_image(
            MultiplexPointPredictor(),
            self.image(),
            prompt_type="points",
            points=[[3, 2]],
            labels=[1],
        )

        self.assertEqual([9], [mask["id"] for mask in masks])

    def test_point_session_reuses_its_frame_until_closed(self) -> None:
        open_session = getattr(sam31, "open_point_session", None)
        segment_session = getattr(sam31, "segment_point_session", None)
        close_session = getattr(sam31, "close_point_session", None)
        self.assertTrue(callable(open_session), "point session opener is required")
        self.assertTrue(callable(segment_session), "point session segmenter is required")
        self.assertTrue(callable(close_session), "point session closer is required")
        assert callable(open_session) and callable(segment_session) and callable(close_session)

        predictor = FakePredictor()
        with tempfile.TemporaryDirectory() as workspace:
            session = open_session(predictor, self.image(), Path(workspace))
            first = segment_session(predictor, session, self.image().size, [[3, 2]], [1])
            second = segment_session(predictor, session, self.image().size, [[4, 2]], [1])

            self.assertEqual([9], [mask["id"] for mask in first])
            self.assertEqual([9], [mask["id"] for mask in second])
            self.assertEqual(["start_session", "add_prompt", "add_prompt"], [call["type"] for call in predictor.calls])
            self.assertIsNotNone(predictor.session_path)
            assert predictor.session_path is not None
            self.assertTrue(predictor.session_path.exists())
            close_session(predictor, session)
            self.assertFalse(predictor.session_path.exists())

        self.assertEqual(["start_session", "add_prompt", "add_prompt", "close_session"], [call["type"] for call in predictor.calls])

    def test_boxes_guidance_and_text_map_to_official_prompt_fields(self) -> None:
        for prompt_type, kwargs, expected in [
            ("boxes", {"boxes": [[1, 1, 5, 3]]}, {"bounding_boxes": [[1 / 6, 1 / 4, 4 / 6, 2 / 4]]}),
            ("guidance_mask", {"guidance_bitmap": np.array([[0, 0, 0, 0, 0, 0], [0, 1, 1, 0, 0, 0], [0, 1, 1, 0, 0, 0], [0, 0, 0, 0, 0, 0]], dtype=bool)}, {"bounding_boxes": [[1 / 6, 1 / 4, 2 / 6, 2 / 4]]}),
            ("text", {"text_prompts": ["cat", "animal"]}, {"text": "cat/animal"}),
        ]:
            with self.subTest(prompt_type=prompt_type):
                predictor = FakePredictor(response={"outputs": {}})
                segment_single_image(predictor, self.image(), prompt_type=prompt_type, **kwargs)
                prompt = predictor.calls[1]
                for key, value in expected.items():
                    self.assertEqual(value, prompt[key])
                self.assertTrue(prompt["rel_coordinates"])

    def test_auto_uses_a_stable_official_text_prompt(self) -> None:
        predictor = FakePredictor(response={"outputs": {}})
        segment_single_image(predictor, self.image(), prompt_type="auto")
        self.assertEqual("object", predictor.calls[1]["text"])

    def test_image_predictor_handles_text_and_auto_without_a_video_session(self) -> None:
        predictor = FakeImagePredictor()
        segment_text = getattr(sam31, "segment_single_image_text", None)
        self.assertTrue(callable(segment_text), "semantic image fast path is required")
        assert callable(segment_text)

        text_results = segment_text(
            predictor,
            self.image(),
            prompt_type="text",
            text_prompts=["cat", "animal"],
        )
        auto_results = segment_text(predictor, self.image(), prompt_type="auto")

        self.assertEqual(["cat/animal", "object"], predictor.prompts)
        self.assertEqual(1, text_results[0]["id"])
        self.assertEqual(1, auto_results[0]["id"])

    def test_image_predictor_rejects_nonsemantic_prompt_types(self) -> None:
        segment_text = getattr(sam31, "segment_single_image_text", None)
        self.assertTrue(callable(segment_text), "semantic image fast path is required")
        assert callable(segment_text)
        with self.assertRaisesRegex(Sam31Error, "fast_path_unsupported"):
            segment_text(FakeImagePredictor(), self.image(), prompt_type="points", text_prompts=["cat"])

    def test_image_result_items_flattens_singleton_mask_channel(self) -> None:
        result_items = getattr(sam31, "_image_result_items", None)
        self.assertTrue(callable(result_items), "image result normalization is required")
        assert callable(result_items)

        results = result_items({"masks": np.array([[[[True]]]]), "scores": [0.9]})

        self.assertEqual(1, len(results))
        self.assertEqual((1, 1), results[0]["mask"].shape)

    def test_image_result_masks_are_remapped_to_the_source_image_size(self) -> None:
        result_items = getattr(sam31, "_image_result_items", None)
        self.assertTrue(callable(result_items), "image result normalization is required")
        assert callable(result_items)

        results = result_items(
            {"masks": np.array([[[False, True, False], [True, True, False]]]), "scores": [0.9]},
            image_size=(6, 4),
        )

        self.assertEqual((4, 6), results[0]["mask"].shape)
        self.assertTrue(results[0]["mask"][0, 2])
        self.assertTrue(results[0]["mask"][3, 0])

    def test_semantic_image_is_normalized_before_it_reaches_cuda(self) -> None:
        normalizer = getattr(sam31, "normalize_image_for_processor", None)
        self.assertTrue(callable(normalizer), "semantic image normalizer is required")
        assert callable(normalizer)

        image = normalizer(Image.new("RGB", (4096, 2731), "white"))

        self.assertEqual("RGB", image.mode)
        self.assertEqual((1008, 1008), image.size)

    def test_invalid_mixed_or_out_of_bounds_prompt_is_rejected_before_session(self) -> None:
        predictor = FakePredictor()
        with self.assertRaisesRegex(Sam31Error, "invalid_prompt"):
            segment_single_image(predictor, self.image(), prompt_type="points", points=[[7, 2]], labels=[1], text_prompts=["cat"])
        self.assertEqual([], predictor.calls)

    def test_session_is_closed_and_private_frame_is_removed_on_inference_failure(self) -> None:
        predictor = FakePredictor(fail_add=True)
        with tempfile.TemporaryDirectory() as workspace:
            with self.assertRaisesRegex(RuntimeError, "predictor failed"):
                segment_single_image(predictor, self.image(), prompt_type="auto", workspace=Path(workspace))
            self.assertIsNotNone(predictor.session_path)
            self.assertFalse(predictor.session_path.exists())
        self.assertEqual(["start_session", "add_prompt", "close_session"], [call["type"] for call in predictor.calls])

    def test_multiplex_predictor_ignores_unsupported_offload_state_argument(self) -> None:
        class LegacyMultiplexModel:
            def init_state(self, resource_path: str, offload_video_to_cpu: bool = False) -> dict[str, object]:
                return {"resource_path": resource_path, "offload_video_to_cpu": offload_video_to_cpu}

        class Predictor:
            model = LegacyMultiplexModel()

        predictor = Predictor()
        _patch_multiplex_init_state(predictor)

        self.assertEqual(
            {"resource_path": "fixture", "offload_video_to_cpu": False},
            predictor.model.init_state(resource_path="fixture", offload_state_to_cpu=False),
        )

    def test_load_predictor_disables_optional_flash_attention_three(self) -> None:
        calls: dict[str, object] = {}
        model_builder = ModuleType("sam3.model_builder")

        class Model:
            def init_state(self, resource_path: str, offload_state_to_cpu: bool = False) -> dict[str, object]:
                return {"resource_path": resource_path, "offload_state_to_cpu": offload_state_to_cpu}

        class Predictor:
            model = Model()

        def build_sam3_multiplex_video_predictor(**kwargs: object) -> Predictor:
            calls.update(kwargs)
            return Predictor()

        model_builder.build_sam3_multiplex_video_predictor = build_sam3_multiplex_video_predictor
        sam3 = ModuleType("sam3")
        sam3.__path__ = []  # type: ignore[attr-defined]
        with patch.dict("sys.modules", {"sam3": sam3, "sam3.model_builder": model_builder}):
            load_predictor(Path("/models/sam3/sam3.1_multiplex.pt"), "cuda")

        self.assertIs(False, calls.get("use_fa3"))


if __name__ == "__main__":
    unittest.main()
