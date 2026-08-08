"""Human-like mouse movement and scrolling."""

from __future__ import annotations

import math
import random
import time
from dataclasses import dataclass
from typing import Any


@dataclass
class HumanConfig:
    enabled: bool = False
    # Total budget for one logical scroll "page" (ms). Maps from CLI scroll_delay_ms.
    scroll_budget_ms: int = 1500
    min_step_ms: int = 40
    max_step_ms: int = 180
    mouse_steps: int = 12
    idle_jitter_ms: tuple[int, int] = (80, 350)


def _bezier(p0: float, p1: float, p2: float, p3: float, t: float) -> float:
    u = 1 - t
    return (u**3) * p0 + 3 * (u**2) * t * p1 + 3 * u * (t**2) * p2 + (t**3) * p3


def move_mouse_human(page: Any, x: float, y: float, *, steps: int = 12) -> None:
    """Move mouse to (x, y) along a cubic Bézier with light jitter."""
    try:
        box = page.evaluate(
            """() => ({
                w: window.innerWidth || 1200,
                h: window.innerHeight || 800,
                x: window.mouseX || Math.floor((window.innerWidth||1200)/2),
                y: window.mouseY || Math.floor((window.innerHeight||800)/3)
            })"""
        )
    except Exception:
        box = {"w": 1200, "h": 800, "x": 600, "y": 300}

    x0 = float(box.get("x") or box["w"] / 2)
    y0 = float(box.get("y") or box["h"] / 3)
    x = max(2.0, min(float(box["w"]) - 2.0, x))
    y = max(2.0, min(float(box["h"]) - 2.0, y))

    cx1 = x0 + (x - x0) * random.uniform(0.2, 0.5) + random.uniform(-40, 40)
    cy1 = y0 + random.uniform(-60, 60)
    cx2 = x0 + (x - x0) * random.uniform(0.5, 0.8) + random.uniform(-40, 40)
    cy2 = y + random.uniform(-60, 60)

    n = max(3, steps)
    for i in range(1, n + 1):
        t = i / n
        # Ease-in-out
        te = t * t * (3 - 2 * t)
        px = _bezier(x0, cx1, cx2, x, te) + random.uniform(-1.2, 1.2)
        py = _bezier(y0, cy1, cy2, y, te) + random.uniform(-1.2, 1.2)
        page.mouse.move(px, py)
        time.sleep(random.uniform(0.008, 0.025))

    try:
        page.evaluate(f"() => {{ window.mouseX = {x}; window.mouseY = {y}; }}")
    except Exception:
        pass


def idle(page: Any, cfg: HumanConfig) -> None:
    lo, hi = cfg.idle_jitter_ms
    page.wait_for_timeout(random.randint(lo, hi))


def human_scroll_once(page: Any, cfg: HumanConfig) -> None:
    """One logical feed scroll: multi-step wheel + budgeted delays."""
    if not cfg.enabled:
        page.evaluate("window.scrollBy(0, Math.max(window.innerHeight, 800))")
        page.wait_for_timeout(max(0, cfg.scroll_budget_ms))
        return

    viewport = page.viewport_size or {"width": 1440, "height": 900}
    mid_x = viewport["width"] * random.uniform(0.35, 0.65)
    mid_y = viewport["height"] * random.uniform(0.35, 0.7)
    move_mouse_human(page, mid_x, mid_y, steps=cfg.mouse_steps)

    total = max(200, cfg.scroll_budget_ms)
    distance = int(viewport["height"] * random.uniform(0.75, 1.15))
    # 4–8 wheel steps within budget
    n_steps = random.randint(4, 8)
    remaining = total
    for i in range(n_steps):
        chunk = distance // n_steps + random.randint(-20, 40)
        page.mouse.wheel(0, max(40, chunk))
        if i < n_steps - 1:
            step_ms = random.randint(cfg.min_step_ms, cfg.max_step_ms)
            step_ms = min(step_ms, max(cfg.min_step_ms, remaining // (n_steps - i)))
            page.wait_for_timeout(step_ms)
            remaining -= step_ms
    if remaining > 0:
        page.wait_for_timeout(remaining)
    idle(page, cfg)


def human_click_locator(page: Any, locator: Any, cfg: HumanConfig) -> bool:
    """Click a locator with optional human mouse approach. Returns True if clicked."""
    try:
        if locator.count() == 0:
            return False
        target = locator.first
        if not target.is_visible():
            return False
        box = target.bounding_box()
        if not box:
            target.click(timeout=2000)
            return True
        if cfg.enabled:
            x = box["x"] + box["width"] * random.uniform(0.3, 0.7)
            y = box["y"] + box["height"] * random.uniform(0.3, 0.7)
            move_mouse_human(page, x, y, steps=cfg.mouse_steps)
            page.wait_for_timeout(random.randint(40, 160))
            page.mouse.click(x, y)
        else:
            target.click(timeout=2000)
        return True
    except Exception:
        return False


def expand_see_more(page: Any, cfg: HumanConfig, *, max_clicks: int = 20) -> int:
    """Click visible '查看更多' / 'See more' controls."""
    patterns = (
        "text=查看更多",
        "text=See more",
        "text=顯示更多",
        "[role='button']:has-text('查看更多')",
        "[role='button']:has-text('See more')",
    )
    clicks = 0
    for _ in range(max_clicks):
        clicked = False
        for sel in patterns:
            loc = page.locator(sel)
            if human_click_locator(page, loc, cfg):
                clicks += 1
                clicked = True
                page.wait_for_timeout(random.randint(200, 500) if cfg.enabled else 150)
                break
        if not clicked:
            break
    return clicks
