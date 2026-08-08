"""Unified Chromium launch / context factory."""

from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any

from crawler.browser.fingerprint import MINIMAL_INIT_SCRIPT, FingerprintProfile
from crawler.browser.proxy import ProxyConfig


# Conservative launch args (pragmatic, not a full anti-detect stack).
DEFAULT_LAUNCH_ARGS = (
    "--disable-blink-features=AutomationControlled",
    "--disable-dev-shm-usage",
    "--no-default-browser-check",
    "--no-first-run",
)


@dataclass
class BrowserBundle:
    browser: Any
    context: Any
    page: Any

    def close(self) -> None:
        try:
            self.context.close()
        finally:
            self.browser.close()


def create_browser_bundle(
    playwright: Any,
    *,
    headless: bool = True,
    storage_state: str | Path | None = None,
    fingerprint: FingerprintProfile | None = None,
    apply_fingerprint: bool = False,
    stealth: bool = False,
    proxy: ProxyConfig | None = None,
) -> BrowserBundle:
    """Create browser + context + page.

    Compatibility path (Goal: bit-similar to plain Playwright):
      fingerprint=None, apply_fingerprint=False, stealth=False, proxy=None
      → chromium.launch + new_context(storage_state=...) only.

    Stealth path:
      stealth=True implies apply_fingerprint with provided or caller-chosen profile.
    """
    if stealth and fingerprint is None:
        raise ValueError("stealth=True requires a FingerprintProfile")

    apply_fp = apply_fingerprint or stealth

    launch_kwargs: dict[str, Any] = {"headless": headless}
    if stealth or apply_fp:
        launch_kwargs["args"] = list(DEFAULT_LAUNCH_ARGS)
    if proxy is not None:
        launch_kwargs["proxy"] = proxy.playwright_proxy()

    browser = playwright.chromium.launch(**launch_kwargs)

    context_kwargs: dict[str, Any] = {}
    if storage_state and Path(storage_state).exists():
        context_kwargs["storage_state"] = str(storage_state)

    if apply_fp and fingerprint is not None:
        context_kwargs.update(fingerprint.context_options())

    context = browser.new_context(**context_kwargs)

    if stealth and fingerprint is not None:
        context.add_init_script(MINIMAL_INIT_SCRIPT)

    page = context.new_page()
    return BrowserBundle(browser=browser, context=context, page=page)
