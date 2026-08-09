from __future__ import annotations

import argparse
import json
import math
import os
import re
import sys
import time
from io import BytesIO
from pathlib import Path
from typing import Any

import numpy as np
from PIL import Image, UnidentifiedImageError

from geometry import rle_from_mask
from model_smoke import CHECKPOINT_NAME, model_status
from sam31 import Sam31Error, load_predictor, release_predictor, result_items, segment_single_image


TRACK_KEY = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$")


def validate_track_prompts(raw: str, frame_count: int) -> list[dict[str, Any]]:
    try:
        prompts = json.loads(raw)
    except (TypeError, json.JSONDecodeError) as exc:
        raise RuntimeError("invalid_prompts") from exc
    if not isinstance(prompts, list) or not 1 <= len(prompts) <= 16 or not isinstance(frame_count, int) or frame_count < 1:
        raise RuntimeError("invalid_prompts")
    keys: set[str] = set()
    normalized: list[dict[str, Any]] = []
    for prompt in prompts:
        if not isinstance(prompt, dict) or set(prompt) - {"track_key", "frame_index", "text", "points", "point_labels", "boxes"}:
            raise RuntimeError("invalid_prompts")
        track_key = prompt.get("track_key")
        frame_index = prompt.get("frame_index")
        if not isinstance(track_key, str) or not TRACK_KEY.fullmatch(track_key) or track_key in keys or isinstance(frame_index, bool) or not isinstance(frame_index, int) or not 0 <= frame_index < frame_count:
            raise RuntimeError("invalid_prompts")
        kinds = [name for name in ("text", "points", "boxes") if name in prompt]
        if len(kinds) != 1:
            raise RuntimeError("invalid_prompts")
        value: dict[str, Any] = {"track_key": track_key, "frame_index": frame_index}
        if kinds[0] == "text":
            text = prompt["text"]
            if not isinstance(text, str) or not 1 <= len(text.strip()) <= 80:
                raise RuntimeError("invalid_prompts")
            value["text"] = text.strip()
        elif kinds[0] == "points":
            points = prompt["points"]
            labels = prompt.get("point_labels")
            if not isinstance(points, list) or not 1 <= len(points) <= 32 or not isinstance(labels, list) or len(labels) != len(points):
                raise RuntimeError("invalid_prompts")
            normalized_points = [_point(point) for point in points]
            if any(isinstance(label, bool) or not isinstance(label, int) or label not in {0, 1} for label in labels) or 1 not in labels:
                raise RuntimeError("invalid_prompts")
            value.update({"points": normalized_points, "point_labels": labels})
        else:
            boxes = prompt["boxes"]
            if not isinstance(boxes, list) or not 1 <= len(boxes) <= 16:
                raise RuntimeError("invalid_prompts")
            value["boxes"] = [_box(box) for box in boxes]
        keys.add(track_key)
        normalized.append(value)
    return normalized


def serialize_track_record(frame_index: int, track_key: str, items: list[dict[str, Any]]) -> dict[str, Any]:
    masks = []
    for item in items:
        bitmap = np.asarray(item.get("mask")) > 0
        if bitmap.ndim != 2:
            continue
        masks.append({
            "id": int(item.get("id", 0)),
            "score": _number(item.get("score", 1.0)),
            "bbox": [_number(value) for value in item.get("bbox", [])][:4],
            "mask": rle_from_mask(bitmap),
        })
    return {"frame_index": frame_index, "track_key": track_key, "masks": masks}


def _point(value: object) -> list[float]:
    if not isinstance(value, list) or len(value) != 2:
        raise RuntimeError("invalid_prompts")
    point = [_number(item) for item in value]
    if not all(0 <= item <= 1 for item in point):
        raise RuntimeError("invalid_prompts")
    return point


def _box(value: object) -> list[float]:
    if not isinstance(value, list) or len(value) != 4:
        raise RuntimeError("invalid_prompts")
    x, y, width, height = [_number(item) for item in value]
    if x < 0 or y < 0 or width <= 0 or height <= 0 or x + width > 1 or y + height > 1:
        raise RuntimeError("invalid_prompts")
    return [x, y, width, height]


def _number(value: object) -> float:
    if isinstance(value, bool):
        raise RuntimeError("invalid_prompts")
    try:
        number = float(value)
    except (TypeError, ValueError) as exc:
        raise RuntimeError("invalid_prompts") from exc
    if not math.isfinite(number):
        raise RuntimeError("invalid_prompts")
    return number


def run_job(job: str, workspace: Path, input_dir: Path, output_dir: Path) -> dict[str, Any]:
    workspace, input_dir, output_dir = _workspace_paths(workspace, input_dir, output_dir)
    if job == "segment_image":
        return run_image_job(workspace, input_dir, output_dir)
    if job == "track_video":
        return run_video_job(input_dir, output_dir)
    if job == "monitor":
        return run_monitor_job(input_dir, output_dir)
    raise RuntimeError("invalid_job")


def run_image_job(workspace: Path, input_dir: Path, output_dir: Path) -> dict[str, Any]:
    request = _read_request(input_dir)
    source = _source(input_dir)
    prompt_type = request.get("prompt_type", "auto")
    if prompt_type not in {"auto", "points", "boxes", "text"}:
        raise RuntimeError("invalid_prompts")
    include_mask_png = request.get("include_mask_png", False)
    if not isinstance(include_mask_png, bool):
        raise RuntimeError("request_invalid")
    try:
        with Image.open(source) as image:
            image.verify()
        with Image.open(source) as image:
            decoded = image.convert("RGB")
    except (UnidentifiedImageError, OSError) as exc:
        raise RuntimeError("source_unavailable") from exc
    points, labels, boxes, text_prompts = _image_prompt(request, prompt_type)
    started = time.monotonic()
    predictor = None
    try:
        predictor = load_predictor(_checkpoint(), "cuda")
        items = segment_single_image(
            predictor,
            decoded,
            prompt_type=prompt_type,
            points=points,
            labels=labels,
            boxes=boxes,
            text_prompts=text_prompts,
            workspace=output_dir,
        )
    except Sam31Error as exc:
        raise RuntimeError("invalid_prompts" if exc.code == "invalid_prompt" else "inference_failed") from exc
    except RuntimeError:
        raise
    except Exception as exc:
        raise RuntimeError("inference_failed") from exc
    finally:
        if predictor is not None:
            release_predictor(predictor)
    masks = serialize_track_record(0, "image", items)["masks"]
    _write_json(output_dir / "sam3_masks.json", {"masks": masks})
    if include_mask_png:
        _write_mask_png(output_dir / "sam3_mask.png", items, decoded.size)
    report = {"status": "succeeded", "mask_count": len(masks), "elapsed_ms": int((time.monotonic() - started) * 1000)}
    _write_json(output_dir / "sam3_image_report.json", report)
    return report


def run_video_job(input_dir: Path, output_dir: Path, request: dict[str, Any] | None = None) -> dict[str, Any]:
    source = _source(input_dir)
    request = _read_request(input_dir) if request is None else request
    include_overlay = request.get("include_overlay", False)
    if not isinstance(include_overlay, bool):
        raise RuntimeError("request_invalid")
    try:
        import cv2

        capture = cv2.VideoCapture(str(source))
        total_frames = int(capture.get(cv2.CAP_PROP_FRAME_COUNT))
        fps = float(capture.get(cv2.CAP_PROP_FPS))
        capture.release()
    except Exception as exc:
        raise RuntimeError("unsupported_video") from exc
    clip_seconds = request.get("clip_seconds", 60)
    if isinstance(clip_seconds, bool) or not isinstance(clip_seconds, int) or not 1 <= clip_seconds <= 60 or total_frames < 1 or not math.isfinite(fps) or fps <= 0:
        raise RuntimeError("unsupported_video")
    if total_frames / fps > 60:
        raise RuntimeError("video_too_long")
    frame_count = min(total_frames, max(1, int(fps * clip_seconds)))
    prompts = validate_track_prompts(request.get("prompts_json", ""), frame_count)
    started = time.monotonic()
    predictor = None
    records: list[dict[str, Any]] = []
    overlay_items: dict[int, list[dict[str, Any]]] = {}
    try:
        predictor = load_predictor(_checkpoint(), "cuda")
        session = predictor.handle_request({"type": "start_session", "resource_path": str(source)})
        session_id = session.get("session_id") if isinstance(session, dict) else None
        if not isinstance(session_id, str) or not session_id:
            raise RuntimeError("inference_failed")
        try:
            for index, prompt in enumerate(prompts, start=1):
                response = predictor.handle_request(_video_prompt_request(session_id, index, prompt))
                items = _track_items(response, index)
                if items:
                    frame_index = prompt["frame_index"]
                    records.append(serialize_track_record(frame_index, prompt["track_key"], items))
                    overlay_items.setdefault(frame_index, []).extend(items)
            for response in predictor.handle_stream_request({
                "type": "propagate_in_video",
                "session_id": session_id,
                "propagation_direction": "forward",
                "max_frame_num_to_track": frame_count,
            }):
                if not isinstance(response, dict) or not isinstance(response.get("frame_index"), int):
                    raise RuntimeError("inference_failed")
                for index, prompt in enumerate(prompts, start=1):
                    items = _track_items(response, index)
                    if items:
                        frame_index = response["frame_index"]
                        records.append(serialize_track_record(frame_index, prompt["track_key"], items))
                        overlay_items.setdefault(frame_index, []).extend(items)
        finally:
            predictor.handle_request({"type": "close_session", "session_id": session_id})
    except RuntimeError:
        raise
    except Exception as exc:
        raise RuntimeError("inference_failed") from exc
    finally:
        if predictor is not None:
            release_predictor(predictor)
    _write_jsonl(output_dir / "sam3_tracks.jsonl", records)
    if include_overlay:
        _write_overlay(source, output_dir / "sam3_overlay.mp4", overlay_items)
    report = {
        "status": "succeeded",
        "frame_count": frame_count,
        "track_count": len({record["track_key"] for record in records}),
        "elapsed_ms": int((time.monotonic() - started) * 1000),
    }
    _write_json(output_dir / "sam3_video_report.json", report)
    return report


def run_monitor_job(input_dir: Path, output_dir: Path) -> dict[str, Any]:
    request = _read_request(input_dir)
    source_id = request.get("source_id")
    if not isinstance(source_id, str) or not re.fullmatch(r"sam3src_[a-f0-9]{32}", source_id):
        raise RuntimeError("request_invalid")
    request = dict(request)
    request.setdefault("clip_seconds", 60)
    request.setdefault("prompts_json", '[{"track_key":"monitor","frame_index":0,"text":"object"}]')
    report = run_video_job(input_dir, output_dir, request)
    tracks = output_dir / "sam3_tracks.jsonl"
    events = output_dir / "sam3_monitor_events.jsonl"
    if not tracks.is_file() or tracks.is_symlink() or events.exists():
        raise RuntimeError("inference_failed")
    tracks.replace(events)
    event_count = sum(1 for line in events.read_text(encoding="utf-8").splitlines() if line.strip())
    monitor_report = {"status": "succeeded", "event_count": event_count, "elapsed_ms": report["elapsed_ms"]}
    _write_json(output_dir / "sam3_monitor_report.json", monitor_report)
    return monitor_report


def _workspace_paths(workspace: Path, input_dir: Path, output_dir: Path) -> tuple[Path, Path, Path]:
    workspace = workspace.resolve()
    input_dir = input_dir.resolve()
    output_dir = output_dir.resolve()
    if input_dir != workspace / "input" or output_dir != workspace / "output" or output_dir.is_symlink() or not output_dir.is_dir():
        raise RuntimeError("workspace_invalid")
    return workspace, input_dir, output_dir


def _read_request(input_dir: Path) -> dict[str, Any]:
    path = input_dir / "request.json"
    if not path.is_file() or path.is_symlink():
        raise RuntimeError("request_invalid")
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise RuntimeError("request_invalid") from exc
    if not isinstance(value, dict):
        raise RuntimeError("request_invalid")
    return value


def _source(input_dir: Path) -> Path:
    path = input_dir / "source"
    if not path.is_file() or path.is_symlink():
        raise RuntimeError("source_unavailable")
    return path


def _checkpoint() -> Path:
    status = model_status()
    if not status.get("present"):
        raise RuntimeError("model_not_present")
    if not status.get("ok"):
        raise RuntimeError("model_load_failed")
    return Path(os.environ.get("SAM3_MODEL_DIR", "/models/sam3")) / CHECKPOINT_NAME


def _image_prompt(request: dict[str, Any], prompt_type: str) -> tuple[list[list[float]] | None, list[int] | None, list[list[float]] | None, list[str] | None]:
    try:
        if prompt_type == "points":
            payload = json.loads(_request_string(request, "points_json"))
            points = payload.get("points") if isinstance(payload, dict) else payload
            labels = payload.get("labels") if isinstance(payload, dict) else [1] * len(points)
            if not isinstance(points, list) or not isinstance(labels, list):
                raise ValueError
            return points, labels, None, None
        if prompt_type == "boxes":
            payload = json.loads(_request_string(request, "boxes_json"))
            boxes = payload if isinstance(payload, list) and (not payload or isinstance(payload[0], list)) else [payload]
            if not isinstance(boxes, list):
                raise ValueError
            return None, None, boxes, None
        if prompt_type == "text":
            text = _request_string(request, "text_prompt") or _request_string(request, "text")
            prompts = [part.strip() for part in text.replace(",", "/").split("/") if part.strip()]
            if not prompts or len(prompts) > 12 or any(len(part) > 80 for part in prompts):
                raise ValueError
            return None, None, None, prompts
    except (TypeError, ValueError, json.JSONDecodeError) as exc:
        raise RuntimeError("invalid_prompts") from exc
    return None, None, None, None


def _request_string(request: dict[str, Any], name: str) -> str:
    value = request.get(name, "")
    if not isinstance(value, str):
        raise ValueError
    return value


def _video_prompt_request(session_id: str, obj_id: int, prompt: dict[str, Any]) -> dict[str, Any]:
    request: dict[str, Any] = {
        "type": "add_prompt",
        "session_id": session_id,
        "frame_index": prompt["frame_index"],
        "obj_id": obj_id,
        "rel_coordinates": True,
    }
    if "text" in prompt:
        request["text"] = prompt["text"]
    elif "points" in prompt:
        request["points"] = prompt["points"]
        request["point_labels"] = prompt["point_labels"]
    else:
        request["bounding_boxes"] = prompt["boxes"]
    return request


def _track_items(response: object, obj_id: int) -> list[dict[str, Any]]:
    return [item for item in result_items(response) if item.get("id") == obj_id]


def _write_json(path: Path, value: dict[str, Any]) -> None:
    _write_text(path, json.dumps(value, ensure_ascii=False, separators=(",", ":")) + "\n")


def _write_jsonl(path: Path, values: list[dict[str, Any]]) -> None:
    _write_text(path, "".join(json.dumps(value, ensure_ascii=False, separators=(",", ":")) + "\n" for value in values))


def _write_text(path: Path, value: str) -> None:
    if path.parent.is_symlink() or path.is_symlink():
        raise RuntimeError("artifact_write_failed")
    temporary = path.with_name("." + path.name + ".tmp")
    try:
        temporary.write_text(value, encoding="utf-8")
        temporary.replace(path)
    except OSError as exc:
        raise RuntimeError("artifact_write_failed") from exc


def _write_bytes(path: Path, value: bytes) -> None:
    if path.parent.is_symlink() or path.is_symlink():
        raise RuntimeError("artifact_write_failed")
    temporary = path.with_name("." + path.name + ".tmp")
    try:
        temporary.write_bytes(value)
        temporary.replace(path)
    except OSError as exc:
        raise RuntimeError("artifact_write_failed") from exc


def _write_mask_png(path: Path, items: list[dict[str, Any]], size: tuple[int, int]) -> None:
    merged = np.zeros((size[1], size[0]), dtype=bool)
    for item in items:
        mask = np.asarray(item.get("mask")) > 0
        if mask.shape == merged.shape:
            merged |= mask
    image = Image.fromarray((merged.astype("uint8") * 255), mode="L")
    output = BytesIO()
    image.save(output, format="PNG")
    _write_bytes(path, output.getvalue())


def overlay_frame(frame: np.ndarray, items: list[dict[str, Any]]) -> np.ndarray:
    output = np.asarray(frame).copy()
    if output.ndim != 3 or output.shape[2] != 3:
        raise RuntimeError("unsupported_video")
    for item in items:
        mask = np.asarray(item.get("mask")) > 0
        if mask.shape != output.shape[:2]:
            continue
        object_id = int(item.get("id", 0))
        color = np.array([(37 * object_id) % 256, (97 * object_id) % 256, (173 * object_id) % 256], dtype=np.uint8)
        output[mask] = ((output[mask].astype("uint16") + color.astype("uint16")) // 2).astype("uint8")
    return output


def _write_overlay(source: Path, path: Path, items_by_frame: dict[int, list[dict[str, Any]]]) -> None:
    if path.parent.is_symlink() or path.is_symlink():
        raise RuntimeError("artifact_write_failed")
    capture = None
    writer = None
    try:
        import cv2

        capture = cv2.VideoCapture(str(source))
        fps = float(capture.get(cv2.CAP_PROP_FPS))
        width = int(capture.get(cv2.CAP_PROP_FRAME_WIDTH))
        height = int(capture.get(cv2.CAP_PROP_FRAME_HEIGHT))
        temporary = path.with_name("." + path.stem + ".tmp" + path.suffix)
        writer = cv2.VideoWriter(str(temporary), cv2.VideoWriter_fourcc(*"mp4v"), fps, (width, height))
        if not writer.isOpened():
            raise RuntimeError("artifact_write_failed")
        frame_index = 0
        while True:
            ok, frame = capture.read()
            if not ok:
                break
            writer.write(overlay_frame(frame, items_by_frame.get(frame_index, [])))
            frame_index += 1
        capture.release()
        capture = None
        writer.release()
        writer = None
        if not temporary.is_file() or temporary.is_symlink() or temporary.stat().st_size > 64 * 1024 * 1024:
            raise RuntimeError("artifact_write_failed")
        temporary.replace(path)
    except RuntimeError:
        raise
    except Exception as exc:
        raise RuntimeError("artifact_write_failed") from exc
    finally:
        if capture is not None:
            capture.release()
        if writer is not None:
            writer.release()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--job", required=True, choices=("segment_image", "track_video", "monitor"))
    parser.add_argument("--workspace", required=True)
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args(argv)
    try:
        run_job(args.job, Path(args.workspace), Path(args.input), Path(args.output))
        return 0
    except RuntimeError as exc:
        print(f"AIHUB_ERROR_CODE={exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
