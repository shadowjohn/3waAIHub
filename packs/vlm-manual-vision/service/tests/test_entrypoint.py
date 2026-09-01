from __future__ import annotations

import unittest
from pathlib import Path


ENTRYPOINT = Path(__file__).resolve().parents[1] / "entrypoint.sh"


class EntrypointTests(unittest.TestCase):
    def test_entrypoint_targets_exact_writable_mounts_then_drops_to_appuser(self) -> None:
        source = ENTRYPOINT.read_text(encoding="utf-8")
        self.assertIn('cache_dir=/cache/manual-vision', source)
        self.assertIn('data_dir=/data/service', source)
        self.assertIn('model_dir=/models/manual-vision', source)
        self.assertIn('chown 10001:10001 "$directory"', source)
        self.assertIn('setpriv --reuid=10001 --regid=10001 --clear-groups', source)


if __name__ == "__main__":
    unittest.main()
