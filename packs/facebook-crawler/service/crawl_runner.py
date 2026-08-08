from __future__ import annotations

import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable
from urllib.parse import urlsplit

from crawler.discovery.facebook import target_from_user_input
from crawler.retry.policy import ErrorClass, RetryConfig
from crawler.scraper import ScrapeOptions, scrape_target


def _dedupe(records: list[dict]) -> list[dict]:
    seen: set[str] = set()
    kept: list[dict] = []
    for record in records:
        content = " ".join(str(record.get("content", "")).split())
        post_url = str(record.get("post_url", "")).strip()
        source_url = str(record.get("source_url", "")).strip()
        key = post_url if post_url and post_url != source_url else source_url + "|" + hashlib.sha256(content.encode()).hexdigest()
        if content and key not in seen:
            seen.add(key)
            kept.append(record)
    return kept


def execute(request: dict, output_dir: Path, scrape: Callable = scrape_target) -> int:
    targets = json.loads(request["targets_json"])
    limit = int(request.get("limit_per_target", 10))
    state = Path("/data/facebook_profile/storage_state.json")
    options = ScrapeOptions(
        headless=True,
        stealth=False,
        human=False,
        fingerprint_name="tw_desktop_chrome",
        storage_state=state if state.is_file() else None,
        proxy=None,
        retry=RetryConfig(max_attempts=2),
    )
    records: list[dict] = []
    outcomes: list[dict] = []
    for item in targets:
        try:
            url = item["url"]
            parsed = urlsplit(url)
            host = parsed.hostname or ""
            if parsed.scheme != "https" or (host != "facebook.com" and not host.endswith(".facebook.com")) or parsed.username is not None or parsed.password is not None or parsed.port is not None or parsed.fragment:
                raise ValueError("invalid Facebook target URL")
            target = target_from_user_input(url, kind="auto", scrolls=min(5, max(1, (limit + 9) // 10)), limit=limit, allow_zero=True)
            result = scrape(target, options)
            health = str((result.meta or {}).get("health_code", ""))
            status = "completed" if result.error_class is ErrorClass.OK else "empty" if result.error_class is ErrorClass.ZERO_RECORDS else "not_accessible" if health == "group_access_denied" else "login_required" if result.error_class is ErrorClass.SESSION else "navigation_failed"
            outcomes.append({"url": url, "status": status, "count": len(result.records or []), "message": str(result.message)[:240]})
            records.extend(result.records or [])
        except Exception as exc:
            outcomes.append({"url": item.get("url", "") if isinstance(item, dict) else "", "status": "extraction_failed", "count": 0, "message": str(exc)[:240]})
    records = _dedupe(records)
    valid = [item for item in outcomes if item["status"] in ("completed", "empty")]
    if not valid:
        return 2
    output_dir.mkdir(parents=True, exist_ok=True)
    dataset = output_dir / "facebook_posts.jsonl"
    dataset.write_text("".join(json.dumps(item, ensure_ascii=False) + "\n" for item in records), encoding="utf-8")
    report = {
        "outcome": "complete" if len(valid) == len(outcomes) else "partial",
        "target_count": len(outcomes),
        "post_count": len(records),
        "limit_per_target": limit,
        "targets": outcomes,
        "created_at": datetime.now(timezone.utc).isoformat(),
        "runner_version": "0.1.0",
    }
    (output_dir / "facebook_crawl_report.json").write_text(json.dumps(report, ensure_ascii=False), encoding="utf-8")
    return 0


if __name__ == "__main__":
    request = json.loads(Path("/workspace/input/request.json").read_text(encoding="utf-8"))
    raise SystemExit(execute(request, Path("/workspace/output")))
