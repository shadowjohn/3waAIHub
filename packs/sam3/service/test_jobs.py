from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import numpy as np

import jobs
from jobs import overlay_frame, run_monitor_job, serialize_track_record, validate_track_prompts


class Sam31JobTests(unittest.TestCase):
    def test_prompts_allow_up_to_sixteen_unique_normalized_tracks(self) -> None:
        prompts = validate_track_prompts(json.dumps([
            {"track_key": "person", "frame_index": 0, "text": "person"},
            {"track_key": "car_1", "frame_index": 4, "points": [[0.5, 0.5]], "point_labels": [1]},
            {"track_key": "sign", "frame_index": 8, "boxes": [[0.1, 0.2, 0.3, 0.4]]},
        ]), frame_count=9)
        self.assertEqual(["person", "car_1", "sign"], [prompt["track_key"] for prompt in prompts])
        self.assertEqual([0, 4, 8], [prompt["frame_index"] for prompt in prompts])

    def test_prompts_reject_invalid_cardinality_or_values(self) -> None:
        cases = [
            [{"track_key": f"item_{index}", "frame_index": 0, "text": "item"} for index in range(17)],
            [{"track_key": "same", "frame_index": 0, "text": "item"}, {"track_key": "same", "frame_index": 1, "text": "other"}],
            [{"track_key": "mixed", "frame_index": 0, "text": "item", "points": [[0.5, 0.5]], "point_labels": [1]}],
            [{"track_key": "nan", "frame_index": 0, "points": [[float("nan"), 0.5]], "point_labels": [1]}],
            [{"track_key": "long", "frame_index": 0, "text": "x" * 81}],
            [{"track_key": "late", "frame_index": 9, "text": "item"}],
        ]
        for value in cases:
            with self.subTest(value=value):
                with self.assertRaisesRegex(RuntimeError, "invalid_prompts"):
                    validate_track_prompts(json.dumps(value), frame_count=9)

    def test_track_records_are_static_artifact_data_only(self) -> None:
        record = serialize_track_record(3, "person", [{
            "id": 4,
            "score": 0.8,
            "bbox": [0.1, 0.2, 0.3, 0.4],
            "mask": np.array([[True, False], [False, True]]),
        }])
        self.assertEqual({"frame_index", "track_key", "masks"}, set(record))
        self.assertEqual({"id", "score", "bbox", "mask"}, set(record["masks"][0]))
        self.assertNotIn("source", json.dumps(record))
        self.assertNotIn("path", json.dumps(record))

    def test_overlay_frame_marks_each_mask_without_source_metadata(self) -> None:
        frame = np.zeros((2, 2, 3), dtype=np.uint8)
        overlaid = overlay_frame(frame, [{"id": 1, "mask": np.array([[True, False], [False, False]])}])
        self.assertEqual(frame.shape, overlaid.shape)
        self.assertGreater(int(overlaid[0, 0].sum()), 0)
        self.assertEqual(0, int(overlaid[1, 1].sum()))

    def test_monitor_uses_a_safe_default_prompt_and_writes_monitor_artifacts(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            input_dir = root / "input"
            output_dir = root / "output"
            input_dir.mkdir()
            output_dir.mkdir()
            (input_dir / "source").write_bytes(b"video")
            (input_dir / "request.json").write_text(json.dumps({"source_id": "sam3src_" + "a" * 32}), encoding="utf-8")

            def video_job(_: Path, output: Path, request: dict | None = None) -> dict:
                self.assertEqual("object", json.loads(request["prompts_json"])[0]["text"])
                (output / "sam3_tracks.jsonl").write_text('{"frame_index":0}\n', encoding="utf-8")
                return {"elapsed_ms": 7}

            with patch.object(jobs, "run_video_job", side_effect=video_job):
                report = run_monitor_job(input_dir, output_dir)

            self.assertEqual({"status": "succeeded", "event_count": 1, "elapsed_ms": 7}, report)
            self.assertTrue((output_dir / "sam3_monitor_events.jsonl").is_file())
            self.assertFalse((output_dir / "sam3_tracks.jsonl").exists())

    def test_video_job_has_a_fixed_sixty_second_duration_ceiling(self) -> None:
        source = Path("/tmp/source.mp4")
        with patch("cv2.VideoCapture") as capture:
            capture.return_value.get.side_effect = [3601, 60.0]
            capture.return_value.release.return_value = None
            with patch.object(jobs, "_source", return_value=source), patch.object(jobs, "_read_request", return_value={"clip_seconds": 60, "prompts_json": "[]"}):
                with self.assertRaisesRegex(RuntimeError, "video_too_long"):
                    jobs.run_video_job(Path("/tmp/input"), Path("/tmp/output"))


if __name__ == "__main__":
    unittest.main()
