"""Entry: python -m crawler.webapp"""

from __future__ import annotations

import runpy
from pathlib import Path


def main() -> int:
    server = Path(__file__).resolve().parents[1] / "scripts" / "web_server.py"
    # Execute as __main__ equivalent
    runpy.run_path(str(server), run_name="__main__")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
