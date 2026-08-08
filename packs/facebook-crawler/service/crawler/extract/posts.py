"""Post extraction from Facebook-like DOM (and local fixtures)."""

from __future__ import annotations

import re
from datetime import datetime, timezone
from typing import Any
from urllib.parse import urljoin, urlparse

DEFAULT_BLOCK_SELECTOR = (
    "article, [role='article'], "
    "div[data-pagelet^='FeedUnit'], div[data-ft], "
    ".post, .feed-post"
)
DEFAULT_MESSAGE_SELECTOR = (
    "[data-ad-preview='message'], "
    "[data-ad-comet-preview='message'], "
    "[data-ad-rendering-role='story_message'], "
    "[data-testid='post_message'], "
    ".post-message, .message"
)


def compact_text(text: str) -> str:
    return " ".join((text or "").split())


def clean_message_text(text: str) -> str:
    t = compact_text(text)
    # Drop common trailing expand labels if concatenated
    for noise in ("查看更多", "See more", "顯示更多"):
        if t.endswith(noise):
            t = t[: -len(noise)].strip()
    return t


def normalize_post_url(href: str, base: str = "") -> str | None:
    if not href:
        return None
    href = href.strip()
    if href.startswith("/"):
        href = urljoin(base or "https://www.facebook.com", href)
    if href.startswith("http://") or href.startswith("https://"):
        # strip tracking query noise lightly
        parsed = urlparse(href)
        return f"{parsed.scheme}://{parsed.netloc}{parsed.path}"
    return None


def pick_post_url(links: list[str], fallback: str = "") -> str:
    for link in links:
        low = link.lower()
        if any(
            k in low
            for k in (
                "/posts/",
                "permalink",
                "story_fbid",
                "multi_permalinks",
                "/photo",
                "/videos/",
                "/reel/",
            )
        ):
            return normalize_post_url(link) or link
    return normalize_post_url(fallback) or fallback


def extract_links(locator: Any) -> list[str]:
    try:
        hrefs = locator.evaluate(
            """(el) => Array.from(el.querySelectorAll('a[href]'))
                .map(a => a.href || a.getAttribute('href') || '')
                .filter(Boolean)"""
        )
        return list(dict.fromkeys(hrefs or []))
    except Exception:
        return []


def extract_post_time(locator: Any) -> str | None:
    try:
        payload = locator.evaluate(
            """(element) => {
                let node = element;
                for (let depth = 0; node && depth < 12; depth += 1, node = node.parentElement) {
                    const abbr = node.querySelector && node.querySelector('abbr[data-utime]');
                    if (abbr) {
                        return {
                            unix: abbr.getAttribute('data-utime'),
                            text: abbr.getAttribute('title') || abbr.getAttribute('aria-label') || ''
                        };
                    }
                    const timeEl = node.querySelector && node.querySelector('time[datetime]');
                    if (timeEl) {
                        return {
                            iso: timeEl.getAttribute('datetime'),
                            text: timeEl.getAttribute('title') || timeEl.textContent || ''
                        };
                    }
                }
                return null;
            }"""
        )
    except Exception:
        payload = None
    if not payload:
        return None
    if payload.get("iso"):
        return str(payload["iso"])
    if payload.get("unix"):
        try:
            return datetime.fromtimestamp(int(payload["unix"]), tz=timezone.utc).isoformat()
        except Exception:
            pass
    text = (payload.get("text") or "").strip()
    return text or None


def extract_records(
    page: Any,
    *,
    target_url: str,
    limit: int = 100,
    min_chars: int = 20,
    message_selector: str = DEFAULT_MESSAGE_SELECTOR,
    block_selector: str = DEFAULT_BLOCK_SELECTOR,
    discovery_mode: str = "page",
    discovery_query: str = "",
) -> list[dict]:
    records = _extract_message_records(
        page,
        selector=message_selector,
        target_url=target_url,
        limit=limit,
        min_chars=min_chars,
        discovery_mode=discovery_mode,
        discovery_query=discovery_query,
    )
    if not records:
        records = _extract_block_records(
            page,
            selector=block_selector,
            target_url=target_url,
            limit=limit,
            min_chars=min_chars,
            discovery_mode=discovery_mode,
            discovery_query=discovery_query,
        )
    return records


def _extract_message_records(
    page: Any,
    *,
    selector: str,
    target_url: str,
    limit: int,
    min_chars: int,
    discovery_mode: str,
    discovery_query: str,
) -> list[dict]:
    locator = page.locator(selector)
    try:
        count = min(locator.count(), limit)
    except Exception:
        return []
    fetched_at = datetime.now(timezone.utc).isoformat()
    seen: set[str] = set()
    records: list[dict] = []
    for index in range(count):
        block = locator.nth(index)
        try:
            text = clean_message_text(block.inner_text(timeout=3000))
        except Exception:
            continue
        if len(text) < min_chars or text in seen:
            continue
        # Prefer article parent for links
        try:
            article = block.locator("xpath=ancestor::*[@role='article'][1]")
            root = article if article.count() else block
        except Exception:
            root = block
        links = extract_links(root)
        post_url = pick_post_url(links, target_url)
        posted_at = extract_post_time(root)
        seen.add(text)
        records.append(
            {
                "source_url": target_url,
                "post_url": post_url,
                "index": index,
                "content": text,
                "links": links,
                "extraction": "message",
                "posted_at": posted_at,
                "fetched_at": fetched_at,
                "discovery_mode": discovery_mode,
                "discovery_query": discovery_query,
            }
        )
    return records


def _extract_block_records(
    page: Any,
    *,
    selector: str,
    target_url: str,
    limit: int,
    min_chars: int,
    discovery_mode: str,
    discovery_query: str,
) -> list[dict]:
    locator = page.locator(selector)
    try:
        count = min(locator.count(), limit)
    except Exception:
        return []
    fetched_at = datetime.now(timezone.utc).isoformat()
    seen: set[str] = set()
    records: list[dict] = []
    for index in range(count):
        block = locator.nth(index)
        try:
            text = compact_text(block.inner_text(timeout=3000))
        except Exception:
            continue
        if len(text) < min_chars or text in seen:
            continue
        links = extract_links(block)
        post_url = pick_post_url(links, target_url)
        posted_at = extract_post_time(block)
        seen.add(text)
        records.append(
            {
                "source_url": target_url,
                "post_url": post_url,
                "index": index,
                "content": text,
                "links": links,
                "extraction": "block",
                "posted_at": posted_at,
                "fetched_at": fetched_at,
                "discovery_mode": discovery_mode,
                "discovery_query": discovery_query,
            }
        )
    return records


HASHTAG_RE = re.compile(r"(#[\w\u4e00-\u9fff]+)")


def match_keywords(content: str, keywords: list[str]) -> list[str]:
    hay = (content or "").casefold()
    if not hay:
        return []
    hit = []
    for kw in keywords:
        k = kw.strip()
        if k and k.casefold() in hay:
            hit.append(k)
    return hit
