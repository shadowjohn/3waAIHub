from __future__ import annotations

import os
import subprocess
import tempfile
import unittest
from pathlib import Path


ENTRYPOINT = Path(__file__).resolve().parents[1] / "entrypoint.sh"


class EntrypointTests(unittest.TestCase):
    def test_only_writable_mounts_are_initialized_then_process_drops_to_appuser(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            commands = root / "commands"
            commands.mkdir()
            log = root / "log"
            for name in ("chown", "setpriv"):
                path = commands / name
                path.write_text(f"#!/bin/sh\nprintf '%s %s\\n' {name} \"$*\" >> \"$ENTRYPOINT_LOG\"\n", encoding="utf-8")
                path.chmod(0o755)
            cache, data, model = root / "cache", root / "data", Path("/proc")
            result = subprocess.run(
                ["/bin/sh", str(ENTRYPOINT), "python3", "-c", "pass"],
                env={**os.environ, "PATH": f"{commands}:{os.environ['PATH']}", "ENTRYPOINT_LOG": str(log), "MANUAL_VISION_CACHE_DIR": str(cache), "MANUAL_VISION_SERVICE_DATA_DIR": str(data), "MANUAL_VISION_MODEL_DIR": str(model)},
                text=True,
                capture_output=True,
                check=False,
            )
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertTrue(cache.is_dir())
            self.assertTrue(data.is_dir())
            lines = log.read_text(encoding="utf-8")
            self.assertIn(f"chown 10001:10001 {cache}", lines)
            self.assertIn(f"chown 10001:10001 {data}", lines)
            self.assertNotIn(str(model), lines)
            self.assertIn("setpriv --reuid=10001 --regid=10001 --clear-groups", lines)


if __name__ == "__main__":
    unittest.main()
