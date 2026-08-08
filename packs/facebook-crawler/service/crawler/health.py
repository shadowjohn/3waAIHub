"""Page health / login-wall / rate-limit detection."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass
class HealthResult:
    ok: bool
    code: str
    message: str


def inspect_page_health(page: Any, target_url: str = "") -> HealthResult:
    """Best-effort session/page health checks for Facebook-like pages."""
    try:
        url = (page.url or "").lower()
        title = (page.title() or "").lower()
        body = ""
        try:
            body = (page.locator("body").inner_text(timeout=3000) or "")[:4000].lower()
        except Exception:
            body = ""
    except Exception as exc:
        return HealthResult(ok=False, code="nav_error", message=str(exc))

    combined = f"{url}\n{title}\n{body}"

    session_markers = (
        ("login", "login wall / login required"),
        ("checkpoint", "account checkpoint"),
        ("two-factor", "two-factor challenge"),
        ("identity confirmation", "identity confirmation"),
        ("確認你的身分", "identity confirmation"),
        ("你必須登入", "login required (zh)"),
        ("請登入", "login required (zh)"),
        ("log in to facebook", "login required"),
        ("account disabled", "account disabled"),
        ("帳號已停用", "account disabled (zh)"),
    )
    for marker, msg in session_markers:
        if marker in combined:
            return HealthResult(ok=False, code="session_failure", message=msg)

    rate_markers = (
        ("try again later", "rate limited"),
        ("暫時無法", "temporarily unavailable"),
        ("we limit how often", "rate limited"),
        ("你暫時無法使用此功能", "rate limited (zh)"),
    )
    for marker, msg in rate_markers:
        if marker in combined:
            return HealthResult(ok=False, code="rate_limited", message=msg)

    # Private / non-member group walls (cannot scrape without membership)
    group_block_markers = (
        ("this group is private", "private group — not a member"),
        ("this content isn't available", "content unavailable (group/page)"),
        ("content isn't available", "content unavailable"),
        ("此社團為不公開", "private group (zh)"),
        ("這是不公開社團", "private group (zh)"),
        ("私人社團", "private group (zh)"),
        ("你必須是成員", "must be group member (zh)"),
        ("成為成員後即可查看", "join group to view (zh)"),
        ("加入社團", "join group wall (zh) — need member cookie"),
        ("join group", "join group wall — need member cookie"),
        ("request to join", "request to join group"),
        ("申請加入", "request to join group (zh)"),
        ("group_wall", "fixture group wall"),
    )
    # Only treat as group block if clearly group-related or fixture
    if "groups/" in url or "group" in title or "社團" in body[:500]:
        for marker, msg in group_block_markers:
            if marker in combined:
                return HealthResult(ok=False, code="group_access_denied", message=msg)
    else:
        # still catch generic unavailable
        for marker, msg in (
            ("this content isn't available", "content unavailable"),
            ("content isn't available right now", "content unavailable"),
        ):
            if marker in combined:
                return HealthResult(ok=False, code="session_failure", message=msg)

    # Fixture login wall page
    if "login_wall" in url or "login wall" in title:
        return HealthResult(ok=False, code="session_failure", message="fixture login wall")

    return HealthResult(ok=True, code="ok", message="")
