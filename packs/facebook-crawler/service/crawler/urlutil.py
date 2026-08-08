"""URL helpers: detect Facebook page / group / hashtag."""

from __future__ import annotations

import re
from urllib.parse import unquote, urlparse


GROUP_RE = re.compile(
    r"(?:https?://)?(?:www\.|m\.)?facebook\.com/groups/([^/?#]+)",
    re.I,
)
HASHTAG_PATH_RE = re.compile(
    r"(?:https?://)?(?:www\.|m\.)?facebook\.com/hashtag/([^/?#]+)",
    re.I,
)
# bare #tag or 防災 as hashtag input
BARE_TAG_RE = re.compile(r"^#?([\w\u4e00-\u9fff]{1,80})$")


def detect_kind(value: str) -> tuple[str, str]:
    """Return (kind, normalized_value) for UI / CLI.

    kind: page | group | hashtag
    """
    raw = (value or "").strip()
    if not raw:
        raise ValueError("empty target")

    # Hashtag URL
    m = HASHTAG_PATH_RE.search(raw)
    if m:
        return "hashtag", unquote(m.group(1))

    # Group URL
    m = GROUP_RE.search(raw)
    if m:
        # Keep full URL for navigation (group id or slug)
        url = raw if raw.startswith("http") else f"https://www.facebook.com/groups/{m.group(1)}"
        # normalize to https facebook groups path without junk
        parsed = urlparse(url if "://" in url else f"https://{url}")
        path = parsed.path.rstrip("/")
        if not path.startswith("/groups/"):
            path = f"/groups/{m.group(1)}"
        clean = f"https://www.facebook.com{path}"
        return "group", clean

    # Explicit hashtag / short tag
    if raw.startswith("#") or (not raw.startswith("http") and BARE_TAG_RE.match(raw)):
        tag = raw.lstrip("#")
        return "hashtag", tag

    # Page / local fixture URLs
    if raw.startswith("http://") or raw.startswith("https://"):
        low = raw.lower()
        # local fixtures
        if "group_wall" in low or "group_feed" in low or "/groups/" in low:
            return "group", raw
        return "page", raw

    # fallback treat as page path
    if "facebook.com" in raw:
        return "page", raw if raw.startswith("http") else f"https://{raw}"

    raise ValueError(
        f"無法辨識目標：{raw!r}。請貼粉專／社團完整網址，或 hashtag（如 防災 / #淹水）。"
    )


def is_facebook_group_url(url: str) -> bool:
    return bool(GROUP_RE.search(url or ""))


def group_requirements_note() -> str:
    return (
        "社團貼文需要：① 已用「有加入該社團」的帳號完成 login 存 cookie；"
        "② 私密／不公開社團若帳號非成員會看到加入牆，無法擷取；"
        "③ 本工具不會自動加入社團或繞過權限。"
    )
