"""Core scrape orchestration for a single target."""

from __future__ import annotations

import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

from crawler.behavior.human import HumanConfig, expand_see_more, human_scroll_once
from crawler.browser.factory import create_browser_bundle
from crawler.browser.fingerprint import FingerprintProfile, get_profile
from crawler.browser.proxy import ProxyConfig
from crawler.discovery.facebook import Target
from crawler.extract.posts import extract_records
from crawler.health import inspect_page_health
from crawler.retry.policy import (
    EXIT_ERROR,
    EXIT_OK,
    EXIT_SESSION_FAILURE,
    EXIT_ZERO_RECORDS,
    AttemptResult,
    ErrorClass,
    RetryConfig,
    exit_code_for,
    run_with_retry,
)


@dataclass
class ScrapeOptions:
    headless: bool = True
    stealth: bool = False
    human: bool = False
    fingerprint_name: str | None = None
    storage_state: Path | None = None
    proxy: ProxyConfig | None = None
    scroll_delay_ms: int = 1500
    stop_after_empty_scrolls: int = 2
    min_chars: int = 20
    max_see_more_clicks: int = 20
    hashtag_style: str = "hashtag"
    skip_health_check: bool = False
    allow_no_cookie: bool = False
    retry: RetryConfig | None = None


def is_local_target(url: str) -> bool:
    try:
        host = urlparse(url).hostname or ""
    except Exception:
        return False
    return host in ("127.0.0.1", "localhost", "::1")


def page_post_signature(page: Any) -> str:
    try:
        return page.evaluate(
            """() => {
                const anchors = Array.from(document.querySelectorAll('a[href]'))
                    .map(a => a.getAttribute('href') || '')
                    .filter(href =>
                        href.includes('/posts/') ||
                        href.includes('permalink') ||
                        href.includes('story_fbid') ||
                        href.includes('/t/') ||
                        /\\/@[^/]+\\/post\\//.test(href)
                    );
                const textLen = (document.body && document.body.innerText)
                    ? document.body.innerText.length : 0;
                return anchors.slice(0, 40).join('|') + '#' + textLen;
            }"""
        )
    except Exception:
        return ""


def wait_for_network_idle(page: Any, timeout_ms: int = 5000) -> None:
    """Soft wait — networkidle timeout is NOT a retry trigger."""
    try:
        page.wait_for_load_state("networkidle", timeout=timeout_ms)
    except Exception:
        pass


def _one_attempt(
    playwright: Any,
    target: Target,
    opts: ScrapeOptions,
) -> AttemptResult:
    url = target.resolved_url(hashtag_style=opts.hashtag_style)
    fingerprint: FingerprintProfile | None = None
    apply_fp = False
    if opts.stealth or opts.fingerprint_name:
        name = opts.fingerprint_name or "tw_desktop_chrome"
        fingerprint = get_profile(name)
        apply_fp = True

    storage = opts.storage_state
    if storage and not storage.exists():
        if opts.allow_no_cookie or is_local_target(url):
            storage.parent.mkdir(parents=True, exist_ok=True)
            storage.write_text(
                '{"cookies": [], "origins": []}',
                encoding="utf-8",
            )
        else:
            return AttemptResult(
                error_class=ErrorClass.FATAL,
                exit_code=EXIT_ERROR,
                message=f"Missing cookie state: {storage}",
            )

    human_cfg = HumanConfig(
        enabled=opts.human,
        scroll_budget_ms=max(0, opts.scroll_delay_ms),
    )

    scrolls_done = 0
    see_more_clicks = 0
    proxy_fp = opts.proxy.fingerprint() if opts.proxy else None

    try:
        bundle = create_browser_bundle(
            playwright,
            headless=opts.headless,
            storage_state=storage,
            fingerprint=fingerprint,
            apply_fingerprint=apply_fp,
            stealth=opts.stealth,
            proxy=opts.proxy,
        )
    except Exception as exc:
        msg = str(exc).lower()
        cls = ErrorClass.PROXY if "proxy" in msg else ErrorClass.TRANSIENT
        return AttemptResult(
            error_class=cls,
            exit_code=EXIT_ERROR,
            message=f"browser launch failed: {exc}",
            meta={"proxy_fingerprint": proxy_fp},
        )

    try:
        page = bundle.page
        try:
            page.goto(url, wait_until="domcontentloaded", timeout=60_000)
        except Exception as exc:
            return AttemptResult(
                error_class=ErrorClass.TRANSIENT,
                exit_code=EXIT_ERROR,
                message=f"navigation failed: {exc}",
                meta={"proxy_fingerprint": proxy_fp, "target_url": url},
            )

        wait_for_network_idle(page)

        if not opts.skip_health_check:
            health = inspect_page_health(page, url)
            if not health.ok:
                if health.code == "rate_limited":
                    return AttemptResult(
                        error_class=ErrorClass.RATE_LIMIT,
                        exit_code=EXIT_ERROR,
                        message=health.message,
                        meta={
                            "health_code": health.code,
                            "proxy_fingerprint": proxy_fp,
                            "target_url": url,
                        },
                    )
                # login wall, checkpoint, private/non-member group — never retry
                return AttemptResult(
                    error_class=ErrorClass.SESSION,
                    exit_code=EXIT_SESSION_FAILURE,
                    message=health.message,
                    meta={
                        "health_code": health.code,
                        "proxy_fingerprint": proxy_fp,
                        "target_url": url,
                        "hint": (
                            "社團需使用已加入成員的 cookie 重新 login；"
                            "無法繞過私密社團或未加入狀態。"
                            if health.code == "group_access_denied"
                            else "請重新 login 或確認帳號可存取該頁。"
                        ),
                    },
                )

        see_more_clicks += expand_see_more(
            page, human_cfg, max_clicks=opts.max_see_more_clicks
        )

        empty_streak = 0
        prev_sig = page_post_signature(page)
        max_scrolls = max(0, target.scrolls)
        stop_after = max(0, opts.stop_after_empty_scrolls)

        for _ in range(max_scrolls):
            human_scroll_once(page, human_cfg)
            see_more_clicks += expand_see_more(
                page, human_cfg, max_clicks=max(0, opts.max_see_more_clicks)
            )
            scrolls_done += 1
            sig = page_post_signature(page)
            if sig == prev_sig:
                empty_streak += 1
            else:
                empty_streak = 0
                prev_sig = sig
            if stop_after and empty_streak >= stop_after:
                break

        records = extract_records(
            page,
            target_url=url,
            limit=max(1, target.limit),
            min_chars=max(0, opts.min_chars),
            discovery_mode=target.kind,
            discovery_query=target.discovery_query,
        )

        meta = {
            "target_url": url,
            "discovery_mode": target.kind,
            "discovery_query": target.discovery_query,
            "scrolls_done": scrolls_done,
            "see_more_clicks": see_more_clicks,
            "proxy_fingerprint": proxy_fp,
            "stealth": opts.stealth,
            "human": opts.human,
            "fingerprint": opts.fingerprint_name if apply_fp else None,
            "fetched_at": datetime.now(timezone.utc).isoformat(),
        }

        if not records:
            return AttemptResult(
                error_class=ErrorClass.ZERO_RECORDS,
                exit_code=exit_code_for(
                    ErrorClass.ZERO_RECORDS, allow_zero=target.allow_zero
                ),
                message="zero records extracted",
                records=[],
                meta={**meta, "status": "zero_records", "record_count": 0},
            )

        return AttemptResult(
            error_class=ErrorClass.OK,
            exit_code=EXIT_OK,
            message="ok",
            records=records,
            meta={**meta, "status": "ok", "record_count": len(records)},
        )
    except Exception as exc:
        return AttemptResult(
            error_class=ErrorClass.TRANSIENT,
            exit_code=EXIT_ERROR,
            message=f"scrape error: {exc}",
            meta={"target_url": url, "proxy_fingerprint": proxy_fp},
        )
    finally:
        bundle.close()


def scrape_target(target: Target, opts: ScrapeOptions) -> AttemptResult:
    """Scrape one target with optional retry."""
    from playwright.sync_api import sync_playwright

    retry_cfg = opts.retry or RetryConfig(max_attempts=1)
    started = time.time()

    def _fn() -> AttemptResult:
        with sync_playwright() as p:
            return _one_attempt(p, target, opts)

    result = run_with_retry(_fn, retry_cfg)
    if result.meta is None:
        result.meta = {}
    result.meta["duration_seconds"] = round(time.time() - started, 3)
    # hashtag empty is success for pipeline purposes
    if (
        result.error_class == ErrorClass.ZERO_RECORDS
        and target.allow_zero
        and result.exit_code == EXIT_OK
    ):
        result.meta["status"] = "zero_records"
        result.meta["zero_records_ok"] = True
    return result
