from __future__ import annotations

import math
import os
from typing import Any

import numpy as np


def bool_mask(mask: Any) -> np.ndarray:
    array = np.asarray(mask)
    if array.ndim != 2:
        raise ValueError("mask must be 2D")
    return array > 0


def rle_from_mask(mask: Any) -> dict[str, Any]:
    bitmap = bool_mask(mask)
    counts: list[int] = []
    current = False
    run = 0
    for value in bitmap.ravel(order="C"):
        bit = bool(value)
        if bit == current:
            run += 1
        else:
            counts.append(run)
            current = bit
            run = 1
    counts.append(run)

    return {"size": [int(bitmap.shape[0]), int(bitmap.shape[1])], "counts": counts}


def _bbox_polygon(bitmap: np.ndarray) -> list[list[list[int]]]:
    ys, xs = np.where(bitmap)
    if len(xs) == 0 or len(ys) == 0:
        return []
    x1, x2 = int(xs.min()), int(xs.max())
    y1, y2 = int(ys.min()), int(ys.max())
    return [[[x1, y1], [x2, y1], [x2, y2], [x1, y2]]]


def _contour_points(contour: Any, cv2: Any, epsilon: float, max_points: int) -> list[list[int]]:
    approx = cv2.approxPolyDP(contour, epsilon, True).reshape(-1, 2)
    points = [[int(x), int(y)] for x, y in approx]
    if len(points) > max_points:
        step = max(1, math.ceil(len(points) / max_points))
        points = points[::step]
    return points


def polygon_from_mask(mask: Any) -> list[list[list[int]]]:
    bitmap = bool_mask(mask)
    if int(bitmap.sum()) < _int_env("SAM3_MIN_CONTOUR_AREA", 16):
        return []

    try:
        import cv2

        contours, _ = cv2.findContours(bitmap.astype("uint8"), cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        polygons: list[list[list[int]]] = []
        max_points = _int_env("SAM3_MAX_POLYGON_POINTS", 2000)
        epsilon = _float_env("SAM3_POLYGON_EPSILON", 2.0)
        min_area = _float_env("SAM3_MIN_CONTOUR_AREA", 16.0)
        for contour in contours:
            if cv2.contourArea(contour) < min_area:
                continue
            points = _contour_points(contour, cv2, epsilon, max_points)
            if len(points) >= 3:
                polygons.append(points)
        return polygons
    except Exception:
        return _bbox_polygon(bitmap)


def polygons_from_mask(mask: Any) -> list[dict[str, Any]]:
    bitmap = bool_mask(mask)
    if int(bitmap.sum()) < _int_env("SAM3_MIN_CONTOUR_AREA", 16):
        return []

    try:
        import cv2

        contours, hierarchy = cv2.findContours(bitmap.astype("uint8"), cv2.RETR_CCOMP, cv2.CHAIN_APPROX_SIMPLE)
        if hierarchy is None:
            return []
        hierarchy_rows = hierarchy[0]
        max_points = _int_env("SAM3_MAX_POLYGON_POINTS", 2000)
        epsilon = _float_env("SAM3_POLYGON_EPSILON", 2.0)
        min_area = _float_env("SAM3_MIN_CONTOUR_AREA", 16.0)
        items: list[dict[str, Any]] = []
        by_index: dict[int, dict[str, Any]] = {}
        holes_by_parent: dict[int, list[list[list[int]]]] = {}
        for index, contour in enumerate(contours):
            if cv2.contourArea(contour) < min_area:
                continue
            points = _contour_points(contour, cv2, epsilon, max_points)
            if len(points) < 3:
                continue
            parent = int(hierarchy_rows[index][3])
            if parent < 0:
                item = {"outer": points, "holes": []}
                by_index[index] = item
                items.append(item)
            else:
                holes_by_parent.setdefault(parent, []).append(points)
        for parent, holes in holes_by_parent.items():
            if parent in by_index:
                by_index[parent]["holes"].extend(holes)
        return items
    except Exception:
        return [{"outer": polygon, "holes": []} for polygon in _bbox_polygon(bitmap)]


def _int_env(key: str, default: int) -> int:
    try:
        return max(1, int(os.getenv(key, str(default))))
    except ValueError:
        return default


def _float_env(key: str, default: float) -> float:
    try:
        return max(0.0, float(os.getenv(key, str(default))))
    except ValueError:
        return default
