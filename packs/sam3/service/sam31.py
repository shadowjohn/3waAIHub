from __future__ import annotations

import gc
import inspect
import math
import tempfile
from pathlib import Path
from typing import Any

import numpy as np
from PIL import Image


class Sam31Error(ValueError):
    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


def load_predictor(checkpoint: Path, device: str) -> Any:
    del device  # SAM 3.1 selects CUDA from its installed runtime.
    from sam3.model_builder import build_sam3_multiplex_video_predictor

    predictor = build_sam3_multiplex_video_predictor(
        checkpoint_path=str(checkpoint),
        max_num_objects=16,
        multiplex_count=16,
        # The production CUDA image uses SAM's PyTorch attention fallback.
        use_fa3=False,
        warm_up=False,
    )
    _patch_multiplex_init_state(predictor)
    return predictor


def _patch_multiplex_init_state(predictor: Any) -> None:
    init_state = predictor.model.init_state
    if "offload_state_to_cpu" in inspect.signature(init_state).parameters:
        return

    def compatible_init_state(*args: Any, **kwargs: Any) -> Any:
        kwargs.pop("offload_state_to_cpu", None)
        return init_state(*args, **kwargs)

    predictor.model.init_state = compatible_init_state


def release_predictor(predictor: Any) -> None:
    del predictor
    gc.collect()
    try:
        import torch

        if torch.cuda.is_available():
            torch.cuda.synchronize()
            torch.cuda.empty_cache()
    except Exception:
        pass


def segment_single_image(
    predictor: Any,
    image: Image.Image,
    *,
    prompt_type: str,
    points: list[list[float]] | None = None,
    labels: list[int] | None = None,
    boxes: list[list[float]] | None = None,
    text_prompts: list[str] | None = None,
    guidance_bitmap: np.ndarray | None = None,
    workspace: Path | None = None,
) -> list[dict[str, Any]]:
    request = _prompt_request(image.size, prompt_type, points, labels, boxes, text_prompts, guidance_bitmap)
    if workspace is not None and not workspace.is_dir():
        raise Sam31Error("invalid_workspace")
    with tempfile.TemporaryDirectory(prefix="sam31-", dir=str(workspace) if workspace is not None else None) as folder:
        frame_dir = Path(folder)
        image.convert("RGB").save(frame_dir / "000000.jpg", format="JPEG", quality=95)
        started = predictor.handle_request({"type": "start_session", "resource_path": str(frame_dir)})
        session_id = _session_id(started)
        if prompt_type == "points":
            _seed_multiplex_frame_cache(predictor, session_id)
        request.update({"type": "add_prompt", "session_id": session_id, "frame_index": 0, "rel_coordinates": True})
        try:
            return result_items(predictor.handle_request(request))
        finally:
            predictor.handle_request({"type": "close_session", "session_id": session_id})


def _prompt_request(
    image_size: tuple[int, int],
    prompt_type: str,
    points: list[list[float]] | None,
    labels: list[int] | None,
    boxes: list[list[float]] | None,
    text_prompts: list[str] | None,
    guidance_bitmap: np.ndarray | None,
) -> dict[str, Any]:
    width, height = image_size
    supplied = sum(bool(value) for value in (points, boxes, text_prompts, guidance_bitmap is not None))
    if prompt_type not in {"auto", "points", "boxes", "text", "guidance_mask"} or supplied > 1:
        raise Sam31Error("invalid_prompt")
    if prompt_type == "auto":
        if supplied:
            raise Sam31Error("invalid_prompt")
        return {"text": "object"}
    if prompt_type == "points":
        if not points or labels is None or len(points) != len(labels):
            raise Sam31Error("invalid_prompt")
        normalized_points = [_relative_point(point, width, height) for point in points]
        if any(label not in {0, 1} for label in labels) or 1 not in labels:
            raise Sam31Error("invalid_prompt")
        return {"points": normalized_points, "point_labels": labels, "obj_id": 1}
    if prompt_type == "boxes":
        if not boxes:
            raise Sam31Error("invalid_prompt")
        return {"bounding_boxes": [_relative_box(box, width, height) for box in boxes]}
    if prompt_type == "text":
        if not text_prompts:
            raise Sam31Error("invalid_prompt")
        return {"text": "/".join(text_prompts)}
    if guidance_bitmap is None or guidance_bitmap.shape != (height, width) or not bool(guidance_bitmap.any()):
        raise Sam31Error("invalid_prompt")
    ys, xs = np.where(guidance_bitmap)
    return {"bounding_boxes": [_relative_box([float(xs.min()), float(ys.min()), float(xs.max()) + 1, float(ys.max()) + 1], width, height)]}


def _relative_point(point: list[float], width: int, height: int) -> list[float]:
    if not isinstance(point, list) or len(point) != 2:
        raise Sam31Error("invalid_prompt")
    x, y = (_finite(value) for value in point)
    if not 0 <= x < width or not 0 <= y < height:
        raise Sam31Error("invalid_prompt")
    return [x / width, y / height]


def _relative_box(box: list[float], width: int, height: int) -> list[float]:
    if not isinstance(box, list) or len(box) != 4:
        raise Sam31Error("invalid_prompt")
    x1, y1, x2, y2 = (_finite(value) for value in box)
    if not 0 <= x1 < x2 <= width or not 0 <= y1 < y2 <= height:
        raise Sam31Error("invalid_prompt")
    return [x1 / width, y1 / height, (x2 - x1) / width, (y2 - y1) / height]


def _finite(value: object) -> float:
    try:
        number = float(value)
    except (TypeError, ValueError) as exc:
        raise Sam31Error("invalid_prompt") from exc
    if not math.isfinite(number):
        raise Sam31Error("invalid_prompt")
    return number


def _session_id(response: object) -> str:
    if not isinstance(response, dict) or not isinstance(response.get("session_id"), str) or not response["session_id"]:
        raise Sam31Error("inference_failed")
    return response["session_id"]


def _seed_multiplex_frame_cache(predictor: Any, session_id: str) -> None:
    sessions = getattr(predictor, "_all_inference_states", None)
    if not isinstance(sessions, dict):
        return
    session = sessions.get(session_id)
    if not isinstance(session, dict):
        return
    state = session.get("state")
    if not isinstance(state, dict):
        return
    cache = state.setdefault("cached_frame_outputs", {})
    if isinstance(cache, dict):
        # SAM 3.1 對全新 session 會忽略未快取 frame 的新點位物件。
        cache.setdefault(0, {})


def _result_items(response: object) -> list[dict[str, Any]]:
    outputs = response.get("outputs", {}) if isinstance(response, dict) else {}
    if not isinstance(outputs, dict):
        return []
    masks = _as_items(outputs.get("out_binary_masks"))
    ids = _as_items(outputs.get("out_obj_ids"))
    scores = _as_items(outputs.get("out_probs"))
    boxes = _as_items(outputs.get("out_boxes_xywh"))
    items: list[dict[str, Any]] = []
    for index, mask in enumerate(masks):
        bitmap = np.asarray(mask) > 0
        if bitmap.ndim == 3 and bitmap.shape[0] == 1:
            bitmap = bitmap[0]
        if bitmap.ndim != 2 or not bool(bitmap.any()):
            continue
        item: dict[str, Any] = {
            "id": int(ids[index]) if index < len(ids) else index + 1,
            "score": _score(scores[index]) if index < len(scores) else 1.0,
            "mask": bitmap,
        }
        if index < len(boxes) and isinstance(boxes[index], (list, tuple)) and len(boxes[index]) == 4:
            item["bbox"] = [_finite(value) for value in boxes[index]]
        items.append(item)
    return items


def result_items(response: object) -> list[dict[str, Any]]:
    return _result_items(response)


def _as_items(value: object) -> list[Any]:
    if value is None:
        return []
    if hasattr(value, "detach"):
        value = value.detach()
    if hasattr(value, "cpu"):
        value = value.cpu()
    if hasattr(value, "tolist"):
        value = value.tolist()
    if isinstance(value, list):
        return value
    if isinstance(value, tuple):
        return list(value)
    return [value]


def _score(value: object) -> float:
    try:
        return _finite(value)
    except Sam31Error:
        return 1.0
