from __future__ import annotations

import json
import sys

import numpy as np

from geometry import polygon_from_mask, rle_from_mask


def main() -> int:
    mask = np.zeros((8, 8), dtype=np.uint8)
    mask[2:7, 2:7] = 1
    rle = rle_from_mask(mask)
    polygon = polygon_from_mask(mask)
    ok = (
        rle["size"] == [8, 8]
        and sum(rle["counts"]) == 64
        and rle["counts"][0] == 18
        and isinstance(polygon, list)
        and bool(polygon)
    )
    print(json.dumps({"ok": ok, "rle": rle, "polygon_count": len(polygon)}, ensure_ascii=False))
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
