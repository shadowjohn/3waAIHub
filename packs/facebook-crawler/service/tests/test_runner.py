import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock

from crawl_runner import execute
from crawler.retry.policy import AttemptResult, ErrorClass, EXIT_OK, EXIT_SESSION_FAILURE


class RunnerTest(unittest.TestCase):
    def test_multi_target_partial_dataset(self):
        request = {
            "targets_json": json.dumps([
                {"url": "https://www.facebook.com/wra.gov.tw"},
                {"url": "https://www.facebook.com/groups/123456789"},
            ]),
            "limit_per_target": 10,
        }
        scrape = Mock(side_effect=[
            AttemptResult(
                error_class=ErrorClass.OK,
                exit_code=EXIT_OK,
                message="ok",
                records=[{"source_url": request["targets_json"], "post_url": "https://www.facebook.com/posts/1", "content": "防災資訊", "fetched_at": "2026-08-08T00:00:00+00:00"}],
                meta={"duration_seconds": 0.1},
            ),
            AttemptResult(
                error_class=ErrorClass.SESSION,
                exit_code=EXIT_SESSION_FAILURE,
                message="private group — not a member",
                records=[],
                meta={"health_code": "group_access_denied", "duration_seconds": 0.1},
            ),
        ])
        with tempfile.TemporaryDirectory() as temp:
            result = execute(request, Path(temp), scrape=scrape)
            report = json.loads((Path(temp) / "facebook_crawl_report.json").read_text())
            lines = (Path(temp) / "facebook_posts.jsonl").read_text().splitlines()
        self.assertEqual(result, 0)
        self.assertEqual(report["outcome"], "partial")
        self.assertEqual([item["status"] for item in report["targets"]], ["completed", "not_accessible"])
        self.assertEqual(len(lines), 1)

    def test_no_accessible_target_fails_without_success_dataset(self):
        scrape = Mock(return_value=AttemptResult(
            error_class=ErrorClass.SESSION,
            exit_code=EXIT_SESSION_FAILURE,
            message="login wall",
            records=[],
            meta={"health_code": "login_required"},
        ))
        request = {"targets_json": '[{"url":"https://www.facebook.com/groups/1"}]', "limit_per_target": 10}
        with tempfile.TemporaryDirectory() as temp:
            self.assertEqual(execute(request, Path(temp), scrape=scrape), 2)
            self.assertFalse((Path(temp) / "facebook_posts.jsonl").exists())


if __name__ == "__main__":
    unittest.main()
