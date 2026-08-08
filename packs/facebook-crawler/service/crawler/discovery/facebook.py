"""Facebook page / group / hashtag discovery URL builders."""

from __future__ import annotations

from dataclasses import dataclass
from urllib.parse import quote

from crawler.urlutil import detect_kind


@dataclass
class Target:
    kind: str  # page | group | hashtag
    value: str  # url or tag without #
    scrolls: int = 3
    limit: int = 50
    allow_zero: bool = False

    @property
    def discovery_query(self) -> str:
        if self.kind == "hashtag":
            tag = self.value.lstrip("#")
            return f"#{tag}"
        return self.value

    def resolved_url(self, *, hashtag_style: str = "hashtag") -> str:
        if self.kind in ("page", "group"):
            return self.value
        tag = self.value.lstrip("#")
        if hashtag_style == "search_posts":
            # Best-effort search URL; FB may redirect / require login
            q = quote(f"#{tag}")
            return f"https://www.facebook.com/search/posts/?q={q}"
        # Default hashtag path
        return f"https://www.facebook.com/hashtag/{quote(tag)}"

    def slug(self) -> str:
        if self.kind == "hashtag":
            return f"hashtag_{self.value.lstrip('#').replace('/', '_')}"
        prefix = "group_" if self.kind == "group" else "page_"
        cleaned = (
            self.value.replace("https://", "")
            .replace("http://", "")
            .replace("/", "_")
            .replace("?", "_")
            .replace("&", "_")
            .replace("=", "_")
        )
        return (prefix + cleaned)[:100] or self.kind


def target_from_user_input(
    value: str,
    *,
    kind: str | None = None,
    scrolls: int = 3,
    limit: int = 50,
    allow_zero: bool | None = None,
) -> Target:
    """Build Target from pasted URL/tag. kind=auto uses detect_kind."""
    if kind and kind != "auto":
        k = kind.lower()
        v = value.strip()
        if k == "hashtag":
            v = v.lstrip("#")
        elif k in ("page", "group") and not v.startswith("http"):
            # try normalize
            if k == "group" and "groups/" not in v:
                v = f"https://www.facebook.com/groups/{v}"
            elif not v.startswith("http"):
                v = f"https://{v}" if "facebook.com" in v else v
    else:
        k, v = detect_kind(value)

    if k not in ("page", "group", "hashtag"):
        raise ValueError(f"Unsupported kind: {k}")

    az = allow_zero if allow_zero is not None else (k == "hashtag")
    return Target(kind=k, value=v, scrolls=scrolls, limit=limit, allow_zero=az)


def parse_targets_file(text: str) -> list[Target]:
    targets: list[Target] = []
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        parts = [p.strip() for p in line.split("|")]
        if len(parts) < 2:
            # bare URL / tag
            if line.startswith("http://") or line.startswith("https://") or line.startswith("#"):
                targets.append(target_from_user_input(line))
            continue
        kind = parts[0].lower()
        value = parts[1]
        scrolls = int(parts[2]) if len(parts) > 2 and parts[2] else 3
        limit = int(parts[3]) if len(parts) > 3 and parts[3] else 50
        if kind not in ("page", "group", "hashtag"):
            raise ValueError(f"Unknown target kind: {kind!r} in line: {line}")
        allow_zero = kind == "hashtag"
        if kind == "hashtag":
            value = value.lstrip("#")
        targets.append(
            Target(
                kind=kind,
                value=value,
                scrolls=scrolls,
                limit=limit,
                allow_zero=allow_zero,
            )
        )
    return targets


def load_targets(path: str) -> list[Target]:
    from pathlib import Path

    return parse_targets_file(Path(path).read_text(encoding="utf-8"))
