"""Residential proxy config — explicit enable only."""

from __future__ import annotations

import hashlib
import os
from dataclasses import dataclass
from typing import Any
from urllib.parse import urlparse


@dataclass(frozen=True)
class ProxyConfig:
    server: str
    username: str | None = None
    password: str | None = None

    def playwright_proxy(self) -> dict[str, str]:
        out: dict[str, str] = {"server": self.server}
        if self.username:
            out["username"] = self.username
        if self.password:
            out["password"] = self.password
        return out

    def fingerprint(self) -> str:
        """Non-secret identifier for meta/logs (never includes password)."""
        host = urlparse(self.server).netloc or self.server
        user = self.username or ""
        digest = hashlib.sha256(f"{host}|{user}".encode()).hexdigest()[:12]
        return f"{host}#{digest}"


def resolve_proxy(
    *,
    use_proxy: bool,
    server: str | None = None,
    username: str | None = None,
    password: str | None = None,
    env: dict[str, str] | None = None,
) -> ProxyConfig | None:
    """Return ProxyConfig only when use_proxy is True.

    Ambient CRAWLER_PROXY_* env vars are ignored unless use_proxy is enabled.
    """
    if not use_proxy:
        return None

    env = env if env is not None else os.environ
    server = (server or env.get("CRAWLER_PROXY_SERVER") or "").strip()
    if not server:
        raise ValueError(
            "Proxy explicitly enabled but no server set. "
            "Pass --proxy-server or set CRAWLER_PROXY_SERVER."
        )
    username = username if username is not None else env.get("CRAWLER_PROXY_USERNAME")
    password = password if password is not None else env.get("CRAWLER_PROXY_PASSWORD")
    return ProxyConfig(
        server=server,
        username=(username or None),
        password=(password or None),
    )


def scrub_proxy_env(env: dict[str, str]) -> dict[str, str]:
    """Remove proxy secrets from a child process environment copy."""
    keys = (
        "CRAWLER_PROXY_SERVER",
        "CRAWLER_PROXY_USERNAME",
        "CRAWLER_PROXY_PASSWORD",
        "CRAWLER_USE_PROXY",
        "HTTPS_PROXY",
        "HTTP_PROXY",
        "ALL_PROXY",
        "https_proxy",
        "http_proxy",
        "all_proxy",
    )
    out = dict(env)
    for key in keys:
        out.pop(key, None)
    return out


def proxy_launch_kwargs(proxy: ProxyConfig | None) -> dict[str, Any]:
    if proxy is None:
        return {}
    return {"proxy": proxy.playwright_proxy()}
