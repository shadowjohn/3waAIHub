from __future__ import annotations

import json
import sys

import numpy as np

from geometry import polygon_from_mask, polygons_from_mask, rle_from_mask


def has_cv2() -> bool:
    try:
        import cv2  # noqa: F401
        return True
    except Exception:
        return False


def main() -> int:
    mask = np.zeros((8, 8), dtype=np.uint8)
    mask[2:7, 2:7] = 1
    rle = rle_from_mask(mask)
    polygon = polygon_from_mask(mask)
    polygons = polygons_from_mask(mask)

    donut = np.zeros((16, 16), dtype=np.uint8)
    donut[2:14, 2:14] = 1
    donut[6:10, 6:10] = 0
    donut_polygons = polygons_from_mask(donut)
    cv2_available = has_cv2()
    ok = (
        rle["size"] == [8, 8]
        and sum(rle["counts"]) == 64
        and rle["counts"][0] == 18
        and isinstance(polygon, list)
        and bool(polygon)
        and isinstance(polygons, list)
        and bool(polygons)
        and "outer" in polygons[0]
        and "holes" in polygons[0]
        and (not cv2_available or bool(donut_polygons[0]["holes"]))
    )
    print(json.dumps({
        "ok": ok,
        "rle": rle,
        "polygon_count": len(polygon),
        "polygons_count": len(polygons),
        "cv2_available": cv2_available,
        "donut_holes": len(donut_polygons[0]["holes"]) if donut_polygons else 0,
    }, ensure_ascii=False))
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
