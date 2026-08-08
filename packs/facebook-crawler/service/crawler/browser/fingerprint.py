"""Browser fingerprint profiles (pragmatic stealth, not undetectable)."""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from crawler.paths import PROJECT_ROOT, project_path

# Frozen: only these patches. Do not expand without design review.
MINIMAL_INIT_SCRIPT = """
Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
Object.defineProperty(navigator, 'languages', { get: () => ['zh-TW', 'zh', 'en-US', 'en'] });
Object.defineProperty(navigator, 'language', { get: () => 'zh-TW' });
"""


@dataclass(frozen=True)
class FingerprintProfile:
    name: str
    user_agent: str
    viewport: dict[str, int]
    locale: str = "zh-TW"
    timezone_id: str = "Asia/Taipei"
    color_scheme: str = "light"
    device_scale_factor: float = 2.0
    is_mobile: bool = False
    has_touch: bool = False
    extra_http_headers: dict[str, str] = field(default_factory=dict)

    def context_options(self) -> dict[str, Any]:
        opts: dict[str, Any] = {
            "user_agent": self.user_agent,
            "viewport": dict(self.viewport),
            "locale": self.locale,
            "timezone_id": self.timezone_id,
            "color_scheme": self.color_scheme,
            "device_scale_factor": self.device_scale_factor,
            "is_mobile": self.is_mobile,
            "has_touch": self.has_touch,
        }
        if self.extra_http_headers:
            opts["extra_http_headers"] = dict(self.extra_http_headers)
        return opts


def default_profiles_path() -> Path:
    return PROJECT_ROOT / "configs" / "browser_profiles.json"


def load_profiles(path: str | Path | None = None) -> dict[str, FingerprintProfile]:
    p = project_path(path) if path else default_profiles_path()
    if not p.exists():
        return {"tw_desktop_chrome": _builtin_tw_desktop()}
    raw = json.loads(p.read_text(encoding="utf-8"))
    out: dict[str, FingerprintProfile] = {}
    for name, cfg in raw.items():
        out[name] = FingerprintProfile(
            name=name,
            user_agent=cfg["user_agent"],
            viewport=cfg["viewport"],
            locale=cfg.get("locale", "zh-TW"),
            timezone_id=cfg.get("timezone_id", "Asia/Taipei"),
            color_scheme=cfg.get("color_scheme", "light"),
            device_scale_factor=float(cfg.get("device_scale_factor", 2)),
            is_mobile=bool(cfg.get("is_mobile", False)),
            has_touch=bool(cfg.get("has_touch", False)),
            extra_http_headers=dict(cfg.get("extra_http_headers") or {}),
        )
    return out


def get_profile(name: str | None, path: str | Path | None = None) -> FingerprintProfile | None:
    if not name:
        return None
    profiles = load_profiles(path)
    if name not in profiles:
        raise KeyError(f"Unknown fingerprint profile: {name!r}. Available: {sorted(profiles)}")
    return profiles[name]


def _builtin_tw_desktop() -> FingerprintProfile:
    return FingerprintProfile(
        name="tw_desktop_chrome",
        user_agent=(
            "Mozilla/5.0 (X11; Linux x86_64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) "
            "Chrome/149.0.0.0 Safari/537.36"
        ),
        viewport={"width": 1440, "height": 900},
        locale="zh-TW",
        timezone_id="Asia/Taipei",
        extra_http_headers={
            "Accept-Language": "zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7",
            "Sec-Ch-Ua": '"Google Chrome";v="149", "Chromium";v="149", "Not)A;Brand";v="24"',
            "Sec-Ch-Ua-Mobile": "?0",
            "Sec-Ch-Ua-Platform": '"Linux"',
        },
    )
